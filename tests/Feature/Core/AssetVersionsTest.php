<?php

namespace Tests\Feature\Core;

use App\Core\Assets\AssetVersions;
use App\Core\Assets\StyleBuilder;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * An update has to reach the customer's browser, not only the server: every
 * file's address carries its version, a year of caching is granted only to
 * the address naming the current one, and the stylesheet can be rebuilt on
 * the shop's own server without ever leaving it half-written (PLAN §45.2–3).
 */
class AssetVersionsTest extends TestCase
{
    public function test_a_public_file_carries_its_version_and_it_moves_when_the_file_changes(): void
    {
        $url = asset_url('/assets/css/app.css');
        $this->assertMatchesRegularExpression('#^/assets/css/app\.css\?v=[0-9a-f]{10}$#', $url);

        $file = public_path('assets/js/zz-version-probe.js');
        file_put_contents($file, 'one');
        try {
            $first = app(AssetVersions::class)->public('/assets/js/zz-version-probe.js');
            app(AssetVersions::class)->forget();
            file_put_contents($file, 'two, longer');
            $this->assertNotSame($first, app(AssetVersions::class)->public('/assets/js/zz-version-probe.js'));
        } finally {
            @unlink($file);
        }

        // A file that is not there keeps a plain address rather than breaking the page.
        $this->assertSame('/assets/nothing.css', asset_url('assets/nothing.css'));
    }

    public function test_a_module_file_is_kept_a_year_only_under_its_current_version(): void
    {
        $url = module_asset('torgash.editor', 'editor.css');
        $this->assertStringContainsString('v=', $url);

        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->get(strtok($url, '?'))->assertOk()->assertHeader('Cache-Control', 'no-cache, public');
        $this->get(strtok($url, '?').'?v=0000000000')->assertOk()->assertHeader('Cache-Control', 'no-cache, public');
    }

    public function test_a_theme_file_is_kept_a_year_only_under_its_current_version(): void
    {
        $url = theme_asset('default', 'preview.jpg');

        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->get(strtok($url, '?'))->assertOk()->assertHeader('Cache-Control', 'no-cache, public');
    }

    public function test_no_template_writes_an_asset_address_by_hand(): void
    {
        $offenders = [];
        $finder = Finder::create()->files()->name('*.php')
            ->in([resource_path('views'), base_path('themes'), base_path('modules'), app_path()]);

        foreach ($finder as $file) {
            foreach (preg_split('/\R/', $file->getContents()) as $n => $line) {
                $bare = preg_match('#(["\'])/assets/#', $line) && ! str_contains($line, 'asset_url(');
                // A base address a script appends names to is the one allowed use.
                $byRoute = preg_match("#route\\('(module|theme)\\.asset'#", $line) && ! str_contains($line, 'dirname(')
                    && ! str_ends_with($file->getRealPath(), 'helpers.php');
                // ...and the same by its path: `url('/modules/<id>/assets/…')`.
                // A base folder (no file name after assets/) is allowed.
                $byPath = preg_match("#url\\(\\s*['\"]/(modules|themes)/[^'\"]+/assets/[^'\"]+['\"]#", $line) === 1;
                if ($bare || $byRoute || $byPath) {
                    $offenders[] = $file->getRelativePathname().':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Use asset_url(), module_asset() or theme_asset(): an address without a version keeps an old file in browsers for a year.');
    }

    public function test_this_platform_has_a_pinned_builder(): void
    {
        $platform = app(StyleBuilder::class)->platform();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) (config('torgash.styles.sha256')[$platform] ?? ''), "No sha256 pinned for $platform");
    }

    public function test_a_downloaded_builder_that_does_not_match_its_sum_is_not_kept(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('not the builder')]);
        $styles = app(StyleBuilder::class);

        try {
            $styles->fetch();
            $this->fail('A builder with the wrong checksum was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }

        $this->assertFileDoesNotExist($styles->downloadPath());
        $this->assertFileDoesNotExist($styles->downloadPath().'.part');
    }

    public function test_a_failed_build_leaves_the_stylesheet_alone(): void
    {
        $target = public_path(StyleBuilder::TARGET);
        $before = hash_file('sha256', $target);

        // PHP itself as a "builder": it runs, writes no stylesheet, and must not blank the shop.
        config(['torgash.styles.builder' => PHP_BINARY]);

        try {
            app(StyleBuilder::class)->build();
            $this->fail('A build that produced nothing was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be built', $e->getMessage());
        }

        $this->assertSame($before, hash_file('sha256', $target));
        $this->assertSame([], glob($target.'.*.tmp'));
    }
}
