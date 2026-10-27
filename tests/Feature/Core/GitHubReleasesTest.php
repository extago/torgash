<?php

namespace Tests\Feature\Core;

use App\Core\Updates\GitHubReleases;
use App\Core\Updates\UpdateServer;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * GitHub's Releases as an update source — for a shop that cannot reach the
 * delivery service but can reach GitHub (the first live shop, in Russia). A
 * release offers its update package; drafts, pre-releases and a release with
 * no package are not offered; the sum comes from GitHub's digest or the
 * published .sha256; and the package's own signature is still what decides
 * whether it is installed (UpdatePackage, not here).
 */
class GitHubReleasesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'torgash.version' => '0.2.0',
            'torgash.updates.server' => 'https://uds.test/uds/torgash/',
            'torgash.updates.github' => '',
        ]);
    }

    public function test_an_address_names_a_github_repository_or_does_not(): void
    {
        $this->assertSame(['extago', 'torgash'], GitHubReleases::repository('https://github.com/extago/torgash'));
        $this->assertSame(['extago', 'torgash'], GitHubReleases::repository('https://github.com/extago/torgash.git/'));
        $this->assertNull(GitHubReleases::repository('https://extago.com/uds/torgash/'));
    }

    public function test_newer_releases_with_a_package_are_offered_oldest_first(): void
    {
        $this->releases();

        $found = app(GitHubReleases::class)->check('extago', 'torgash', '0.2.0');

        $this->assertSame('0.2.2', $found['latest']);
        $this->assertSame(['0.2.1', '0.2.2'], array_column($found['updates'], 'version'));
        $this->assertSame(str_repeat('a', 64), $found['updates'][0]['sha256'], 'GitHub\'s own digest');
        $this->assertSame(str_repeat('b', 64), $found['updates'][1]['sha256'], 'the published .sha256 when there is no digest');
        $this->assertSame('Worker mode', $found['updates'][0]['notes']);
    }

    public function test_the_shop_turns_to_github_when_its_server_does_not_answer(): void
    {
        config(['torgash.updates.github' => 'extago/torgash']);
        $this->releases(['uds.test/*' => Http::response('', 504)]);

        $found = app(UpdateServer::class)->check();

        $this->assertArrayNotHasKey('error', $found);
        $this->assertSame('https://github.com/extago/torgash', $found['source']);
        $this->assertSame('0.2.2', app(UpdateServer::class)->offer()['version']);
    }

    public function test_the_update_address_itself_may_be_a_github_repository(): void
    {
        app(UpdateServer::class)->setAddress('https://github.com/extago/torgash');
        $this->releases();

        $found = app(UpdateServer::class)->check();

        $this->assertSame('0.2.2', $found['latest']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'uds.test'));
    }

    public function test_a_download_with_no_announced_sum_is_refused(): void
    {
        Http::fake(['*' => Http::response('PK…')]);

        $this->expectException(RuntimeException::class);
        app(UpdateServer::class)->download(['version' => '0.2.1', 'download' => 'https://github.com/x/y/releases/download/v0.2.1/torgash-0.2.1.zip', 'sha256' => '']);
    }

    /** A releases listing as GitHub's API gives it (fields trimmed to what is read). */
    protected function releases(array $more = []): void
    {
        $asset = fn (string $name, ?string $digest = null) => array_filter([
            'name' => $name,
            'url' => 'https://api.github.com/repos/extago/torgash/releases/assets/'.crc32($name),
            'browser_download_url' => 'https://github.com/extago/torgash/releases/download/'.$name,
            'digest' => $digest,
        ]);

        Http::fake($more + [
            'api.github.com/repos/extago/torgash/releases*' => Http::response([
                ['tag_name' => 'v0.3.0', 'draft' => true, 'prerelease' => false, 'assets' => [$asset('torgash-0.3.0.zip', 'sha256:'.str_repeat('c', 64))]],
                ['tag_name' => 'v0.2.3-beta', 'draft' => false, 'prerelease' => true, 'assets' => []],
                ['tag_name' => 'v0.2.2', 'draft' => false, 'prerelease' => false, 'body' => 'Waybills',
                    'assets' => [$asset('torgash-0.2.2.zip'), $asset('torgash-0.2.2.zip.sha256'), $asset('torgash-0.2.2-install.zip')]],
                ['tag_name' => 'v0.2.1', 'draft' => false, 'prerelease' => false, 'body' => 'Worker mode',
                    'assets' => [$asset('torgash-0.2.1.zip', 'sha256:'.str_repeat('a', 64))]],
                ['tag_name' => 'v0.1.9', 'draft' => false, 'prerelease' => false, 'assets' => [$asset('torgash-0.1.9.zip', 'sha256:'.str_repeat('d', 64))]],
                ['tag_name' => 'v0.2.5', 'draft' => false, 'prerelease' => false, 'assets' => [$asset('torgash-0.2.5-install.zip')]],
            ]),
            'github.com/extago/torgash/releases/download/torgash-0.2.2.zip.sha256' => Http::response(str_repeat('b', 64).'  torgash-0.2.2.zip'),
        ]);
    }
}
