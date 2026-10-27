<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Models\Storefront;
use Tests\TestCase;

/**
 * The default theme's footer (layouts/partials/footer): a layout and a
 * palette that combine freely, the columns' widths, the lines, the bottom
 * line and menus folding on a phone — all theme settings, section "Footer".
 */
class ThemeFooterTest extends TestCase
{
    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storefront = Storefront::query()->firstOrFail();
        $this->storefront->update(['theme' => 'default']);
    }

    public function test_the_default_is_the_dark_footer_in_columns(): void
    {
        $footer = $this->footer();

        $this->assertStringContainsString('torgash-footer-columns', $footer);
        $this->assertStringContainsString('torgash-bar', $footer);
        $this->assertStringContainsString('torgash-footer-fold', $footer);
        $this->assertStringContainsString('torgash-footer-bottom', $footer);
        $this->assertStringNotContainsString('torgash-footer-bottom-centred', $footer);
    }

    public function test_every_layout_draws_in_either_palette(): void
    {
        foreach (['columns', 'compact', 'centred'] as $layout) {
            foreach (['dark', 'light'] as $palette) {
                $this->set('footer_layout', $layout);
                $this->set('footer_palette', $palette);

                $footer = $this->footer();

                $this->assertStringContainsString('torgash-footer-'.$layout, $footer, "$layout/$palette");
                $palette === 'light'
                    ? $this->assertStringNotContainsString('torgash-bar bg-neutral', $footer, "$layout/$palette: the page's own palette")
                    : $this->assertStringContainsString('torgash-bar bg-neutral', $footer, "$layout/$palette");
            }
        }

        // Centred centres the bottom line whatever its own setting says.
        $this->assertStringContainsString('torgash-footer-bottom-centred', $this->footer());
    }

    public function test_the_finer_settings_reach_the_markup(): void
    {
        $this->set('footer_dividers', false);
        $this->set('footer_bottom', 'centred');
        $this->set('footer_fold_menus', false);

        $footer = $this->footer();

        $this->assertStringNotContainsString('lg:divide-x', $footer);
        $this->assertStringNotContainsString('border-white/5', $footer);
        $this->assertStringContainsString('torgash-footer-bottom-centred', $footer);
        $this->assertStringNotContainsString('torgash-footer-fold', $footer);
    }

    public function test_the_compact_footer_has_no_advantages_band(): void
    {
        $columns = $this->footer();
        $this->set('footer_layout', 'compact');
        $compact = $this->footer();

        $this->assertStringContainsString('torgash-squircle', $columns, 'the theme\'s advantages band');
        $this->assertStringNotContainsString('torgash-squircle', $compact);
    }

    protected function footer(): string
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();
        $start = strpos($html, '<footer');

        $this->assertNotFalse($start, 'a footer is drawn');

        return substr($html, $start, strpos($html, '</footer>', $start) - $start);
    }

    protected function set(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('theme.default', $key, $value, $this->storefront->id);
        app()->forgetInstance(\App\Core\Themes\ThemeSettings::class);
    }
}
