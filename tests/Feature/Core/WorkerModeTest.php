<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\WebWorkers;
use App\Core\Themes\ThemeManager;
use App\Models\Storefront;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\Concerns\RunsInOctaneWorker;
use Tests\TestCase;

/**
 * Requests served by ONE booted application, the way FrankenPHP's worker mode
 * serves them (Octane's real Worker, socket faked — RunsInOctaneWorker).
 *
 * Every test here is a request that changes something a worker shares with
 * the next request, followed by that next request. Under php-fpm each would
 * pass trivially; these are the leaks only a worker has. Measured before the
 * fixes (2026-10-21): a hook listener added by a request stayed subscribed, a
 * storefront's theme stayed active for whatever came next, a theme's
 * translation paths piled up, and a setting changed elsewhere was never read
 * again by a worker that had read it once.
 */
class WorkerModeTest extends TestCase
{
    use RunsInOctaneWorker;

    public function test_pages_alternate_their_language_title_and_nonce(): void
    {
        $responses = $this->serveInWorker([
            $this->workerGet('http://localhost/'),
            $this->workerGet('http://localhost/ru'),
            $this->workerGet('http://localhost/catalog'),
            $this->workerGet('http://localhost/'),
        ]);

        $this->assertSame(['en', 'ru', 'en', 'en'], array_map(fn ($r) => $this->lang($r->getContent()), $responses));
        $this->assertNotSame($this->title($responses[2]->getContent()), $this->title($responses[3]->getContent()), 'the catalogue\'s title must not stay for the home page');
        $this->assertSame($this->title($responses[0]->getContent()), $this->title($responses[3]->getContent()));

        $nonces = array_map(fn ($r) => $this->nonce($r->getContent()), $responses);
        $this->assertCount(4, array_unique($nonces), 'a CSP nonce is per request');
    }

    public function test_a_hook_listener_added_by_a_request_does_not_outlive_it(): void
    {
        $this->workerRoute('/__worker/listen', function () {
            hook_listen('worker.test.hook', fn ($value) => $value + 1);

            return (string) hook_filter('worker.test.hook', 0);
        });
        $this->workerRoute('/__worker/ask', fn () => (string) hook_filter('worker.test.hook', 0));

        $responses = $this->serveInWorker([
            $this->workerGet('http://localhost/__worker/listen'),
            $this->workerGet('http://localhost/__worker/ask'),
            $this->workerGet('http://localhost/__worker/listen'),
        ]);

        $this->assertSame(['1', '0', '1'], array_map(fn ($r) => $r->getContent(), $responses));
    }

    public function test_a_storefronts_theme_does_not_stay_for_the_next_request(): void
    {
        Storefront::create([
            'code' => 'crystal-shop', 'name' => 'Crystal', 'domains' => ['crystal.test'], 'theme' => 'crystal',
            'default_locale' => 'en', 'locales' => ['en'], 'default_currency' => 'USD', 'currencies' => ['USD'],
            'is_default' => false, 'enabled' => true,
        ]);

        $this->workerRoute('/__worker/theme', fn () => app(ThemeManager::class)->active().'|'.(int) collect(View::getFinder()->getPaths())->contains(fn ($path) => str_contains(str_replace('\\', '/', $path), 'themes/crystal')));

        $responses = $this->serveInWorker([
            $this->workerGet('http://crystal.test/'),
            $this->workerGet('http://localhost/__worker/theme'),
        ]);

        $this->assertSame(200, $responses[0]->getStatusCode());
        $this->assertSame('default|0', $responses[1]->getContent(), 'a request outside any storefront draws in the default theme again');
    }

    public function test_translation_paths_a_request_adds_are_taken_away(): void
    {
        $this->workerRoute('/__worker/add-lang', function () {
            app('translator')->addJsonPath(base_path('themes/crystal/lang'));

            return (string) count(app('translator')->getLoader()->jsonPaths());
        });
        $this->workerRoute('/__worker/count-lang', fn () => (string) count(app('translator')->getLoader()->jsonPaths()));

        $responses = $this->serveInWorker([
            $this->workerGet('http://localhost/__worker/count-lang'),
            $this->workerGet('http://localhost/__worker/add-lang'),
            $this->workerGet('http://localhost/__worker/count-lang'),
        ]);

        $this->assertSame($responses[0]->getContent(), $responses[2]->getContent());
        $this->assertNotSame($responses[0]->getContent(), $responses[1]->getContent());
    }

    public function test_a_setting_changed_by_another_worker_is_read_again(): void
    {
        app(SettingsManager::class)->set('core', 'store_name', 'Before');
        $this->workerRoute('/__worker/setting', fn () => (string) setting('core', 'store_name'));

        $first = $this->serveInWorker([$this->workerGet('http://localhost/__worker/setting')]);

        // Written behind this worker's back — as another worker of the same
        // server would, through its own SettingsManager.
        DB::table('settings')->where('module', 'core')->where('key', 'store_name')->update(['value' => json_encode('After')]);

        $second = $this->serveInWorker([$this->workerGet('http://localhost/__worker/setting')]);

        $this->assertSame(['Before', 'After'], [$first[0]->getContent(), $second[0]->getContent()]);
    }

    /**
     * Measured on the live shop (2026-10-23): a zone switched off in the
     * admin stayed on the storefront — the blocks were read when the worker
     * booted and handed to the zone's listener.
     */
    public function test_a_zone_switched_off_and_a_block_edited_reach_a_running_worker(): void
    {
        $zone = 'storefront.footer.before';
        DB::table('m_blocks')->where('zone', $zone)->delete();
        $block = \Torgash\Blocks\Models\Block::create([
            'zone' => $zone, 'type' => 'html', 'settings' => ['html' => '<p>BAND-ONE</p>'],
            'enabled' => true, 'sort' => 1, 'rules_match' => 'all',
        ]);
        $this->workerRoute('/__worker/zone', fn () => hook_render($zone));

        $first = $this->serveInWorker([$this->workerGet('http://localhost/__worker/zone')]);

        // Edited, and the zone switched off — both behind the worker's back.
        DB::table('m_blocks')->where('id', $block->id)->update(['settings' => json_encode(['html' => '<p>BAND-TWO</p>'])]);
        $edited = $this->serveInWorker([$this->workerGet('http://localhost/__worker/zone')]);
        app(SettingsManager::class)->set('torgash.blocks', 'disabled_zones', [$zone]);
        $off = $this->serveInWorker([$this->workerGet('http://localhost/__worker/zone')]);

        $this->assertStringContainsString('BAND-ONE', $first[0]->getContent());
        $this->assertStringContainsString('BAND-TWO', $edited[0]->getContent());
        $this->assertStringNotContainsString('BAND', $off[0]->getContent());
    }

    public function test_changed_code_and_a_new_storefront_language_tell_the_workers(): void
    {
        $stamp = fn () => is_file(storage_path(WebWorkers::STAMP)) ? file_get_contents(storage_path(WebWorkers::STAMP)) : '';
        $before = $stamp();

        WebWorkers::restart();
        $afterRestart = $stamp();
        $this->assertNotSame($before, $afterRestart, 'the worker loop retires a worker once this changes');

        // The route table depends on the storefronts' languages, and is built
        // once per worker: a new language needs a fresh one.
        $storefront = Storefront::query()->where('is_default', true)->firstOrFail();
        $storefront->update(['locales' => [...$storefront->locales, 'uk']]);
        $this->assertNotSame($afterRestart, $stamp());

        // A change that does not touch the routes does not retire anybody.
        $afterLocales = $stamp();
        $storefront->update(['name' => 'Renamed']);
        $this->assertSame($afterLocales, $stamp());
    }

    public function test_the_front_controller_is_the_worker_and_ships_with_every_update(): void
    {
        // Through index.php, which every release has always owned — a new file
        // of its own could not be installed by any shop (see the next test).
        $this->assertContains('public/index.php', \App\Core\Updates\Distribution::FILES);
        $this->assertStringContainsString('FrankenPhpWorker::wanted()', file_get_contents(public_path('index.php')));
        $this->assertFalse(\App\Core\Support\FrankenPhpWorker::wanted(), 'never under php-fpm, the console or the tests');
    }

    /**
     * What a release may own is decided by the INSTALLED code
     * (Distribution::ownable), so a path added here is a path no shop can take
     * — 0.2.1 added public/frankenphp-worker.php and was refused by every shop
     * on 0.2.0 (the first live one, 2026-10-22). Growing this list needs a
     * release that only teaches the shops the new path, shipped before the one
     * that uses it.
     */
    public function test_the_paths_a_release_owns_do_not_grow(): void
    {
        $this->assertSame(['app', 'config', 'database', 'lang', 'resources', 'routes', 'vendor', 'data/geo', 'public/assets'], \App\Core\Updates\Distribution::DIRS);
        $this->assertSame([
            'artisan', 'composer.json', 'composer.lock', 'bootstrap/app.php', 'bootstrap/providers.php',
            'public/index.php', 'public/.htaccess', 'public/favicon.ico',
        ], \App\Core\Updates\Distribution::FILES);
    }

    /** A route on the worker's own application — its router is shared by every request, as in production. */
    protected function workerRoute(string $uri, \Closure $action): void
    {
        $router = $this->octaneApp()['router'];
        $router->get($uri, $action);
        $router->getRoutes()->refreshNameLookups();
    }

    protected function lang(string $html): ?string
    {
        return preg_match('/<html[^>]*lang="([^"]+)"/', $html, $m) ? $m[1] : null;
    }

    protected function title(string $html): ?string
    {
        return preg_match('/<title>(.*?)<\/title>/s', $html, $m) ? trim($m[1]) : null;
    }

    protected function nonce(string $html): ?string
    {
        return preg_match('/nonce="([^"]+)"/', $html, $m) ? $m[1] : null;
    }
}
