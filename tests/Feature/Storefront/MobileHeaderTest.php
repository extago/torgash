<?php

namespace Tests\Feature\Storefront;

use App\Core\Settings\SettingsManager;
use App\Core\Themes\ThemeSettings;
use App\Models\Storefront;
use Tests\TestCase;

/**
 * The phone's header in its three shapes (theme setting `mobile_header`):
 * the search alone, the search with the shop's sign in it, and the logo with
 * icons whose magnifier unfolds the search.
 */
class MobileHeaderTest extends TestCase
{
    protected function shape(string $shape): string
    {
        $this->get('http://localhost/');
        $themes = app(ThemeSettings::class);
        app(SettingsManager::class)->set($themes->moduleId(), 'mobile_header', $shape, Storefront::query()->orderBy('id')->firstOrFail()->id);

        $html = $this->get('http://localhost/')->assertOk()->getContent();
        preg_match('~<header class="torgash-bar [^"]*lg:hidden.*?</header>~s', $html, $header);

        return $header[0] ?? '';
    }

    public function test_the_default_is_the_search_field_alone(): void
    {
        $header = $this->shape('search');

        $this->assertStringContainsString('name="q"', $header);
        $this->assertStringNotContainsString('torgash-search-mark', $header);
        $this->assertStringNotContainsString('searching', $header);
    }

    public function test_the_mark_stands_in_the_field_and_falls_back_to_a_letter(): void
    {
        $header = $this->shape('search_mark');

        $this->assertStringContainsString('class="torgash-search-mark"', $header);
        $name = Storefront::query()->orderBy('id')->firstOrFail()->name;
        $this->assertStringContainsString('<span aria-hidden="true">'.e(mb_strtoupper(mb_substr($name, 0, 1))).'</span>', $header);
    }

    public function test_the_logo_shape_folds_the_search_behind_a_magnifier_that_is_a_link(): void
    {
        $header = $this->shape('logo');

        $this->assertStringContainsString('x-show="searching"', $header);
        // Without a script the magnifier still leads to the search page.
        $this->assertMatchesRegularExpression('~<a href="[^"]*/search" class="[^"]*btn[^"]*" aria-label="Search"~', $header);
        $this->assertStringNotContainsString('torgash-search-mark', $header);
        $this->assertStringNotContainsString('cart-badge', $header, 'the tab bar has the basket; a second #cart-badge would break its swap');
    }

    /**
     * The phone icon opens the `storefront.mobile.contacts` sheet (seeded from
     * the theme) and stays a tel: link, so without a script it still dials.
     */
    public function test_the_phone_icon_opens_the_contacts_sheet_while_it_has_blocks(): void
    {
        $storefront = Storefront::query()->orderBy('id')->firstOrFail();
        $storefront->update(['contacts' => ['phone' => '+38 (044) 111-22-33']]);

        $this->shape('logo');
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString('<dialog id="torgash-contacts-sheet"', $html);
        $this->assertStringContainsString('href="tel:+380441112233"', $html);
        $this->assertStringContainsString('x-on:click.prevent="document.getElementById(\'torgash-contacts-sheet\').showModal()"', $html);
    }
}
