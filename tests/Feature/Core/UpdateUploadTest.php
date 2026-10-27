<?php

namespace Tests\Feature\Core;

use App\Core\Updates\InstallUpdate;
use App\Core\Updates\Release;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * An update installed from a file (System → Install from a file), for a shop
 * that cannot download from the update server: the package is checked when
 * it arrives — signed by the makers, newer than this code — and installed by
 * the same InstallUpdate job as an offered one.
 */
class UpdateUploadTest extends TestCase
{
    protected string $dir;

    protected array $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/upload-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->dir);
        $this->keys = Release::generateKeys();
        config(['torgash.updates.public_keys' => [$this->keys['public']]]);
        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        foreach (['torgash-9.9.9.zip', 'torgash-0.0.1.zip'] as $name) {
            @unlink(storage_path(InstallUpdate::INCOMING.'/'.$name));
        }
        parent::tearDown();
    }

    protected function package(string $version, ?string $secret = null): UploadedFile
    {
        file_put_contents($this->dir.'/New.php', 'new code');
        $zip = $this->dir."/torgash-$version.zip";
        Release::pack($zip, $version, ['app'], ['app/New.php' => $this->dir.'/New.php'], $secret ?? $this->keys['secret']);

        return new UploadedFile($zip, basename($zip), 'application/zip', null, true);
    }

    public function test_a_signed_newer_package_is_kept_and_offered_for_install(): void
    {
        $this->post('http://localhost/admin/system/updates/upload', ['package' => $this->package('9.9.9')])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertFileExists(storage_path(InstallUpdate::INCOMING.'/torgash-9.9.9.zip'));
        $this->get('http://localhost/admin/system')->assertOk()->assertSee('torgash-9.9.9.zip', false);
    }

    public function test_a_package_signed_by_anybody_else_is_refused_and_not_kept(): void
    {
        $this->post('http://localhost/admin/system/updates/upload', ['package' => $this->package('9.9.9', Release::generateKeys()['secret'])])
            ->assertSessionHas('error');

        $this->assertFileDoesNotExist(storage_path(InstallUpdate::INCOMING.'/torgash-9.9.9.zip'));
        $this->assertSame([], glob(storage_path(InstallUpdate::INCOMING.'/upload-*.zip')) ?: [], 'no temporary file left behind');
    }

    public function test_a_package_that_is_not_newer_is_refused(): void
    {
        $this->post('http://localhost/admin/system/updates/upload', ['package' => $this->package('0.0.1')])
            ->assertSessionHas('error');

        $this->assertFileDoesNotExist(storage_path(InstallUpdate::INCOMING.'/torgash-0.0.1.zip'));
    }

    public function test_installing_a_waiting_package_runs_the_update_job_on_it(): void
    {
        $this->post('http://localhost/admin/system/updates/upload', ['package' => $this->package('9.9.9')]);
        Queue::fake();

        $this->post('http://localhost/admin/system/updates/install-file', ['name' => 'torgash-9.9.9.zip'])->assertRedirect();

        Queue::assertPushed(InstallUpdate::class, fn (InstallUpdate $job) => $job->package === storage_path(InstallUpdate::INCOMING.'/torgash-9.9.9.zip'));
    }

    public function test_only_a_plain_file_name_inside_the_folder_is_accepted(): void
    {
        $this->post('http://localhost/admin/system/updates/install-file', ['name' => '../../.env'])->assertNotFound();
    }
}
