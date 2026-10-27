<?php

namespace Tests\Feature\Core;

use App\Core\Updates\Release;
use App\Core\Updates\Updater;
use App\Core\Updates\UpdatePackage;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Updating a shop from a signed package (PLAN §45.7): only a package signed by
 * a key the shop trusts, only forwards, only the paths core owns, every file
 * checked, and the previous version kept so the rollback script — plain PHP,
 * outside the code — can put it back. Run on a throwaway install in a temp
 * folder, never on this one.
 */
class UpdaterTest extends TestCase
{
    protected string $dir;

    protected array $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = str_replace('\\', '/', storage_path('framework/testing/update-'.bin2hex(random_bytes(4))));
        $this->keys = Release::generateKeys();
        config(['torgash.updates.public_keys' => [$this->keys['public']]]);

        // The shop as it is: core, a bundled module, someone else's module, its own things.
        $this->write('shop/app/Old.php', 'old code');
        $this->write('shop/config/torgash.php', 'version 0.1.0');
        $this->write('shop/artisan', 'old artisan');
        $this->write('shop/modules/t.bundled/manifest.json', json_encode(['id' => 't.bundled', 'provider' => 'X\\P', 'version' => '1.0.0']));
        $this->write('shop/modules/t.bundled/old.txt', 'old');
        $this->write('shop/modules/v.third/manifest.json', json_encode(['id' => 'v.third', 'provider' => 'X\\P', 'author' => ['name' => 'Someone']]));
        $this->write('shop/.env', 'APP_KEY=secret');
        $this->write('shop/storage/app/keep.txt', 'uploads');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    protected function write(string $path, string $content): string
    {
        File::ensureDirectoryExists(dirname($this->dir.'/'.$path));
        file_put_contents($this->dir.'/'.$path, $content);

        return $this->dir.'/'.$path;
    }

    /** A release of $version holding $files (relative path => content). */
    protected function package(string $version, array $files, ?array $paths = null, ?string $secret = null): string
    {
        $sources = [];
        foreach ($files as $path => $content) {
            $sources[$path] = $this->write("src-$version/$path", $content);
        }
        $zip = $this->dir."/torgash-$version.zip";
        Release::pack($zip, $version, $paths ?? ['app', 'config', 'artisan', 'modules/t.bundled'], $sources, $secret ?? $this->keys['secret']);

        return $zip;
    }

    protected function updater(): Updater
    {
        return new Updater($this->dir.'/shop', $this->dir.'/work', ['backup' => false, 'maintenance' => false, 'finish' => false]);
    }

    protected function release020(): string
    {
        return $this->package('0.2.0', [
            'app/New.php' => 'new code',
            'config/torgash.php' => 'version 0.2.0',
            'artisan' => 'new artisan',
            'modules/t.bundled/manifest.json' => json_encode(['id' => 't.bundled', 'provider' => 'X\\P', 'version' => '2.0.0']),
        ]);
    }

    public function test_a_release_replaces_the_owned_paths_and_nothing_else(): void
    {
        $done = $this->updater()->apply($this->release020(), null, '0.1.0');

        $shop = $this->dir.'/shop';
        $this->assertSame(['from' => '0.1.0', 'to' => '0.2.0', 'secret' => null], $done);
        $this->assertFileExists("$shop/app/New.php");
        $this->assertFileDoesNotExist("$shop/app/Old.php", 'a file the new version does not have is gone from core');
        $this->assertStringEqualsFile("$shop/config/torgash.php", 'version 0.2.0');
        $this->assertStringEqualsFile("$shop/artisan", 'new artisan');
        $this->assertFileDoesNotExist("$shop/modules/t.bundled/old.txt");

        // The shop's own things are not core's to touch.
        $this->assertStringEqualsFile("$shop/.env", 'APP_KEY=secret');
        $this->assertStringEqualsFile("$shop/storage/app/keep.txt", 'uploads');
        $this->assertFileExists("$shop/modules/v.third/manifest.json");

        // The previous version is kept for the way back.
        $this->assertStringEqualsFile($this->dir.'/work/0.2.0/previous/app/Old.php', 'old code');
    }

    public function test_the_rollback_script_puts_the_previous_version_back_once(): void
    {
        $updater = $this->updater();
        $updater->apply($this->release020(), null, '0.1.0');

        $this->assertTrue($updater->rollback($updater->lastPlan(), codeOnly: true));

        $shop = $this->dir.'/shop';
        $this->assertStringEqualsFile("$shop/app/Old.php", 'old code');
        $this->assertFileDoesNotExist("$shop/app/New.php");
        $this->assertStringEqualsFile("$shop/config/torgash.php", 'version 0.1.0');
        $this->assertStringEqualsFile("$shop/modules/t.bundled/old.txt", 'old');
        $this->assertStringEqualsFile("$shop/.env", 'APP_KEY=secret');

        // Twice does not move the restored version out again.
        $this->assertTrue($updater->rollback($updater->lastPlan(), codeOnly: true));
        $this->assertStringEqualsFile("$shop/app/Old.php", 'old code');
    }

    public function test_a_package_downloaded_into_the_work_directory_survives_the_sweep_of_old_runs(): void
    {
        // Where UpdateServer::download() puts it, beside an older run.
        $this->write('work/0.1.5/plan.json', '{}');
        File::ensureDirectoryExists($this->dir.'/work/downloads');
        rename($this->release020(), $zip = $this->dir.'/work/downloads/torgash-0.2.0.zip');

        $this->updater()->apply($zip, null, '0.1.0');

        $this->assertFileExists($this->dir.'/shop/app/New.php');
        $this->assertFileExists($zip);
    }

    public function test_the_way_back_is_the_new_versions_rollback_script(): void
    {
        $updater = $this->updater();
        $updater->apply($this->package('0.2.0', [
            'app/Core/Updates/rollback.php.stub' => '<?php // 0.2.0 rollback',
            'config/torgash.php' => 'version 0.2.0',
        ]), null, '0.1.0');

        $this->assertStringEqualsFile($updater->rollbackScript(), '<?php // 0.2.0 rollback');
    }

    public function test_a_package_signed_by_another_key_is_refused(): void
    {
        $zip = $this->package('0.2.0', ['app/New.php' => 'evil'], secret: Release::generateKeys()['secret']);

        $this->expectExceptionMessage('not signed');
        $this->updater()->apply($zip, null, '0.1.0');
    }

    public function test_a_file_changed_after_signing_is_refused_and_nothing_moves(): void
    {
        $zip = $this->release020();
        $archive = new ZipArchive;
        $archive->open($zip);
        $archive->addFromString('files/app/New.php', 'swapped after signing');
        $archive->close();

        try {
            $this->updater()->apply($zip, null, '0.1.0');
            $this->fail('A tampered package was installed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not what was signed', $e->getMessage());
        }

        $this->assertStringEqualsFile($this->dir.'/shop/app/Old.php', 'old code');
    }

    public function test_only_forwards(): void
    {
        $this->expectExceptionMessage('Only a newer version');
        $this->updater()->apply($this->package('0.1.0', ['app/New.php' => 'x']), null, '0.1.0');
    }

    public function test_a_release_cannot_claim_the_shops_own_paths_or_write_outside_its_own(): void
    {
        try {
            UpdatePackage::open($this->package('0.2.0', ['.env' => 'APP_KEY=mine'], ['.env']));
            $this->fail('A release claimed .env');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('may not replace', $e->getMessage());
        }

        try {
            UpdatePackage::open($this->package('0.3.0', ['storage/app/x.php' => '<?php'], ['app']));
            $this->fail('A release wrote outside its paths');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('may not write', $e->getMessage());
        }
    }
}
