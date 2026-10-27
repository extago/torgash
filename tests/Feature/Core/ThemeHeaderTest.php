<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Themes\ThemeSettings;
use App\Models\Storefront;
use Tests\TestCase;

/**
 * The default theme's header, two axes: the layout (`header`: two rows or
 * three) and the palette (`header_palette`: the dark bar or the page's
 * colours). It used to be ONE choice — "dark" (two rows, dark) or "light"
 * (three rows, light) — and a shop that chose then keeps what it chose:
 * `renamed` reads the old answer, `follows` gives the new palette from it.
 */
class ThemeHeaderTest extends TestCase
{
    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storefront = Storefront::query()->firstOrFail();
        $this->storefront->update(['theme' => 'default']);
    }

    public function test_the_default_is_two_rows_on_the_dark_bar(): void
    {
        $header = $this->header();

        $this->assertStringContainsString('torgash-header-main', $header, 'two rows');
        $this->assertStringContainsString('torgash-bar bg-neutral', $header);
    }

    public function test_every_layout_draws_in_either_palette(): void
    {
        foreach (['two_rows' => 'torgash-header-main', 'three_rows' => 'torgash-header-icons'] as $layout => $mark) {
            foreach (['dark', 'light'] as $palette) {
                $this->set('header', $layout);
                $this->set('header_palette', $palette);

                $header = $this->header();

                $this->assertStringContainsString($mark, $header, "$layout/$palette");
                $palette === 'dark'
                    ? $this->assertStringContainsString('torgash-bar bg-neutral', $header, "$layout/$palette")
                    : $this->assertStringNotContainsString('torgash-bar bg-neutral', $header, "$layout/$palette");
            }
        }
    }

    public function test_a_choice_made_before_the_split_is_kept(): void
    {
        $this->set('header', 'light');
        $header = $this->header();
        $this->assertStringContainsString('torgash-header-icons', $header, '"light" was three rows');
        $this->assertStringNotContainsString('torgash-bar bg-neutral', $header, '…in the page\'s colours');
        $this->assertSame('three_rows', app(ThemeSettings::class)->get('header'));
        $this->assertSame('light', app(ThemeSettings::class)->get('header_palette'));

        $this->set('header', 'dark');
        $header = $this->header();
        $this->assertStringContainsString('torgash-header-main', $header, '"dark" was two rows');
        $this->assertStringContainsString('torgash-bar bg-neutral', $header, '…on the dark bar');

        // A palette chosen since wins over what the old answer implied.
        $this->set('header', 'light');
        $this->set('header_palette', 'dark');
        $this->assertStringContainsString('torgash-bar bg-neutral', $this->header());
    }

    public function test_the_contacts_stand_after_the_search_or_up_in_the_strip(): void
    {
        // The theme's contacts block hides itself without a phone.
        $this->storefront->update(['contacts' => ['phone' => '+380 44 393 31 47']]);

        foreach (['two_rows', 'three_rows'] as $layout) {
            $this->set('header', $layout);

            $this->set('header_contacts', 'main');
            $header = $this->header();
            $this->assertSame(1, substr_count($header, 'popovertarget="contacts-menu"'), "$layout: drawn once");
            $this->assertGreaterThan(strpos($header, 'role="search"'), strpos($header, 'contacts-menu'), "$layout: after the search");

            $this->set('header_contacts', 'strip');
            $header = $this->header();
            $this->assertSame(1, substr_count($header, 'popovertarget="contacts-menu"'), "$layout: drawn once");
            $this->assertLessThan(strpos($header, 'role="search"'), strpos($header, 'contacts-menu'), "$layout: in the strip above");
        }
    }

    public function test_an_old_file_or_preset_is_read_with_the_new_names(): void
    {
        $this->assertSame(['header' => 'three_rows'], app(ThemeSettings::class)->clean(['header' => 'light']));
    }

    public function test_crystal_is_three_light_rows_by_default(): void
    {
        $this->storefront->update(['theme' => 'crystal']);

        $header = $this->header();

        $this->assertStringContainsString('torgash-header-icons', $header);
        $this->assertStringNotContainsString('torgash-bar bg-neutral', $header);
    }

    protected function header(): string
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();
        $start = strpos($html, '<div class="hidden lg:contents">');

        $this->assertNotFalse($start, 'the desktop header is drawn');

        // Up to the phone's own header, which is the dark bar whatever this says.
        return substr($html, $start, strpos($html, 'torgash-header-bar lg:hidden', $start) - $start);
    }

    protected function set(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('theme.default', $key, $value, $this->storefront->id);
        app()->forgetInstance(ThemeSettings::class);
    }
}
