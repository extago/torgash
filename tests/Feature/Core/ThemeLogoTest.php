<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Themes\ThemeSettings;
use App\Models\Storefront;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Torgash\Blocks\BlocksServiceProvider;
use Torgash\Blocks\Models\Block;

/**
 * The shop's logo in the visitor's colour scheme and in the footer, the
 * shop's icon, and the one gutter every band across the page keeps to (the
 * owner, 2026-10-28: the footer's own logo per scheme, the phone menu's
 * logo dark on dark, the header 8px off the page under it).
 */
class ThemeLogoTest extends TestCase
{
    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storefront = Storefront::query()->firstOrFail();
        $this->storefront->update(['theme' => 'default']);
    }

    public function test_a_dark_bar_draws_one_logo_the_pages_colours_one_per_scheme(): void
    {
        $this->picture('logo');
        $this->picture('logo_on_dark');

        $page = $this->page();
        $this->assertSame(['' => 'logo_on_dark'], $this->logos($page, '//*[contains(@class, "torgash-header-main")]'), 'the bar is dark in both schemes');
        $this->assertSame(['light' => 'logo', 'dark' => 'logo_on_dark'], $this->logos($page, '//aside[@role="dialog"]'), 'the phone menu is the page\'s colours');

        $this->set('header_palette', 'light');
        $this->assertSame(['light' => 'logo', 'dark' => 'logo_on_dark'], $this->logos($this->page(), '//*[contains(@class, "torgash-header-main")]'));

        $this->set('logo_on_dark', '');
        $this->assertSame(['' => 'logo'], $this->logos($this->page(), '//aside[@role="dialog"]'), 'one picture: nothing to switch');
    }

    public function test_the_footer_has_a_pair_of_its_own(): void
    {
        Block::create(['zone' => 'storefront.footer.bottom', 'type' => 'html', 'title' => 'Sign', 'enabled' => true, 'sort' => 99,
            'settings' => ['html' => '<div data-sign>{logo_dark}</div>'], 'rules_match' => 'all']);
        Block::create(['zone' => 'storefront.page.top', 'type' => 'html', 'title' => 'Top', 'enabled' => true, 'sort' => 1,
            'settings' => ['html' => '<div data-top>{logo_dark}</div>'], 'rules_match' => 'all']);
        $this->app->register(new BlocksServiceProvider($this->app), true);
        $this->picture('logo');
        $this->picture('logo_on_dark');

        $this->assertSame(['' => 'logo_on_dark'], $this->logos($this->page(), '//*[@data-sign]'), 'none of its own: as before');

        $this->picture('footer_logo');
        $page = $this->page();
        $this->assertSame(['' => 'footer_logo'], $this->logos($page, '//*[@data-sign]'), 'in the dark scheme too while that one is empty');
        $this->assertSame(['' => 'logo_on_dark'], $this->logos($page, '//*[@data-top]'), 'only in the footer');

        $this->picture('footer_logo_dark');
        $this->assertSame(['light' => 'footer_logo', 'dark' => 'footer_logo_dark'], $this->logos($this->page(), '//*[@data-sign]'));
    }

    public function test_the_shops_icon_is_in_the_head(): void
    {
        $this->assertStringNotContainsString('rel="icon"', $this->page());

        $this->picture('favicon');
        $page = $this->page();
        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]+/favicon-A{16}\.png">#', $page);
        $this->assertMatchesRegularExpression('#<link rel="apple-touch-icon" href="[^"]+/favicon-A{16}\.png">#', $page);

        $this->picture('favicon', 'svg');
        $page = $this->page();
        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]+/favicon-A{16}\.svg" type="image/svg\+xml">#', $page);
        $this->assertStringNotContainsString('apple-touch-icon', $page, 'a phone\'s home screen takes no SVG');
    }

    /**
     * The colour scheme is written on <html> by the theme's head on every
     * page it draws, the closed one too — so a dark-scheme rule keyed on
     * data-theme holds for a visitor whose system chose it — and the script
     * runs under the page's content security policy.
     */
    public function test_the_colour_scheme_is_written_by_the_themes_head_on_every_page(): void
    {
        $open = $this->get('http://localhost/')->assertOk();
        $this->storefront->update(['closed' => true, 'closed_token' => 'secret-preview-token']);
        $closed = $this->get('http://localhost/');

        foreach (['open' => $open, 'closed' => $closed] as $which => $response) {
            $page = (string) $response->getContent();
            $this->assertSame(1, preg_match_all('/<script nonce="([^"]+)">\s*\(function \(\) \{\s*var root = document\.documentElement/', $page, $found), $which.': written once');
            $this->assertStringContainsString("'nonce-".$found[1][0]."'", (string) $response->headers->get('Content-Security-Policy'), $which.': allowed to run');
            $this->assertStringContainsString("system.addEventListener('change'", $page, $which.': "auto" follows the system');
        }
    }

    /** A band that runs across the page puts its edge where every other one does. */
    public function test_every_band_across_the_page_keeps_to_the_one_gutter(): void
    {
        $files = collect([base_path('themes'), base_path('resources/views'), ...glob(base_path('modules/*/views'))])
            ->flatMap(fn (string $dir) => File::allFiles($dir))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php') && ! str_contains(str_replace('\\', '/', $file->getPathname()), '/admin/'));

        $bands = 0;
        foreach ($files as $file) {
            preg_match_all('/(["\'])[^"\'\n]*max-w-\(--torgash-container\)[^"\'\n]*\1/', $file->getContents(), $found);

            foreach ($found[0] as $classes) {
                $bands++;
                $this->assertStringContainsString('px-(--torgash-gutter)', $classes, $file->getRelativePathname().': '.$classes);
            }
        }

        $this->assertGreaterThan(8, $bands, 'the header\'s rows, the page and the footer at the least');
    }

    protected function page(): string
    {
        return $this->get('http://localhost/')->assertOk()->getContent();
    }

    /** @return array<string, string> scheme ('' — both) => the setting whose picture stands there */
    protected function logos(string $page, string $region): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$page, LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);
        $root = $xpath->query($region)->item(0);
        $this->assertNotNull($root, 'drawn: '.$region);

        $found = [];
        foreach ($xpath->query('.//img[contains(concat(" ", @class, " "), " torgash-logo ")]', $root) as $img) {
            preg_match('/\btorgash-logo-(light|dark)\b/', $img->getAttribute('class'), $scheme);
            preg_match('#/theme-images/\d+/([a-z_]+)-[A-Za-z0-9]{16}\.#', $img->getAttribute('src'), $key);
            $found[$scheme[1] ?? ''] = $key[1] ?? $img->getAttribute('src');
        }

        return $found;
    }

    protected function picture(string $key, string $extension = 'png'): void
    {
        $this->set($key, 'theme-images/'.$this->storefront->id.'/'.$key.'-'.str_repeat('A', 16).'.'.$extension);
    }

    protected function set(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('theme.default', $key, $value, $this->storefront->id);
        app()->forgetInstance(ThemeSettings::class);
    }
}
