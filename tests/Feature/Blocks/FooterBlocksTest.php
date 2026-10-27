<?php

namespace Tests\Feature\Blocks;

use App\Models\Storefront;
use Tests\TestCase;
use Torgash\Blocks\ThemeDefaults;
use Torgash\Warehouses\Models\Warehouse;

/**
 * The footer built of blocks (the owner's design, 2026-10-01): advantages, a
 * rating, the contacts, social cards a script of the shop's own can fill,
 * payment marks, the shop's signature and its stores by town — each one
 * escaped, each one drawing nothing when it has nothing to say.
 */
class FooterBlocksTest extends TestCase
{
    protected function block(string $view, array $settings): string
    {
        return view($view, ['settings' => $settings])->render();
    }

    /** The tokens a theme's HTML blocks are written with. */
    public function test_the_tokens_for_a_themes_html(): void
    {
        $this->get('http://localhost/'); // a storefront for {storefront} and the logo

        $html = expand_placeholders('{storefront} © 2015 – {year} {logo_dark} {icon:star} {icon:no-such-icon} {icon:<b>} {mark:telegram} {mark:nope}');

        $this->assertStringContainsString('© 2015 – '.date('Y'), $html);
        $this->assertStringContainsString('<span class="text-primary">.</span>', $html, 'no logo uploaded — the name with its dot');
        $this->assertSame(2, substr_count($html, '<svg'), 'one star, one Telegram mark, nothing for the rest');
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function test_payment_marks_are_known_slugs_or_the_shops_own_pictures(): void
    {
        $html = $this->block('torgash.blocks::blocks.payments', ['items' => "visa\nmastercard\nnotabrand\nMir | /storage/mir.svg\nEvil | javascript:x"]);

        $this->assertSame(2, substr_count($html, '<svg'));
        $this->assertStringContainsString('src="/storage/mir.svg"', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<title>', $html);
    }

    public function test_the_footer_shows_the_storefronts_contacts_and_its_stores_by_town(): void
    {
        $storefront = Storefront::firstOrFail();
        $storefront->update(['contacts' => ['phone' => '+7 800 222-11-30', 'email' => 'info@shop.test', 'hours' => '08:00–20:00', 'address' => 'Moscow, 1A Bagrationovsky']]);

        $this->get('http://localhost/'); // the storefront the blocks read is the request's

        Warehouse::create(['name' => ['en' => 'Savelovsky shop'], 'address' => 'Suschevsky Val 5', 'enabled' => true, 'is_public' => true,
            'hours' => ['mon' => ['10:00', '20:30'], 'tue' => ['10:00', '20:30'], 'wed' => ['10:00', '20:30'], 'thu' => ['10:00', '20:30'], 'fri' => ['10:00', '20:30'], 'sat' => ['10:00', '20:30'], 'sun' => ['10:00', '20:30']]]);
        Warehouse::create(['name' => ['en' => 'Back office'], 'address' => 'Hidden 1', 'enabled' => true, 'is_public' => false]);

        // The contacts as a theme's HTML writes them: a row a shop left empty
        // goes with its fragment.
        $contacts = expand_placeholders('[<p>{phone_actions}</p>][<i>· {hours}</i>][<p>{email_actions}</p>][<b>{address}</b>]');
        $this->assertStringContainsString('href="tel:+78002221130"', $contacts);
        $this->assertStringContainsString('href="mailto:info@shop.test"', $contacts);
        $this->assertStringContainsString('popovertarget=', $contacts);
        $this->assertStringContainsString('08:00–20:00', $contacts);
        $this->assertStringContainsString('Moscow, 1A Bagrationovsky', $contacts);

        $stores = $this->block('torgash.warehouses::blocks.stores', ['heading' => 'Stores in :city']);
        $this->assertStringContainsString('Savelovsky shop', $stores);
        $this->assertStringContainsString('10:00–20:30', $stores);
        $this->assertStringNotContainsString('Back office', $stores);
    }

    public function test_the_theme_lays_the_footer_out_and_an_empty_zone_draws_no_band(): void
    {
        Storefront::firstOrFail()->update(['contacts' => ['phone' => '+7 800 222-11-30']]);
        $defaults = app(ThemeDefaults::class);
        foreach (['before', 'about', 'columns', 'side', 'stores', 'bottom'] as $zone) {
            $defaults->add('storefront.footer.'.$zone);
        }

        // The page answers, with the dialog at its end...
        $page = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('enquiry-callback', $page);

        // ...and the theme's footer blocks, drawn from the rows as they are now
        // (a zone's blocks are read when the application boots, which in a
        // test was before they were laid out).
        $html = '';
        foreach (['before', 'about', 'columns', 'side', 'stores', 'bottom'] as $zone) {
            $html .= app(\Torgash\Blocks\ZoneRenderer::class)->render(
                \Torgash\Blocks\Models\Block::enabled()->where('zone', 'storefront.footer.'.$zone)->orderBy('sort')->orderBy('id')->get(),
            );
        }

        foreach (['tel:+78002221130', 'torgash-block-payments', 'torgash-squircle', 'torgash-rating-glow', 'data-social="telegram"', '© 2015 – '.date('Y')] as $piece) {
            $this->assertStringContainsString($piece, $html, $piece);
        }
        $this->assertStringNotContainsString('{icon:', $html, 'every token in the theme\'s HTML was known');
        $this->assertStringNotContainsString('{mark:', $html);
    }

    /** The shop's own CSS comes last, and cannot close the style block it is in. */
    public function test_the_themes_own_css_is_printed_last_and_cannot_escape(): void
    {
        $this->get('http://localhost/');
        $themes = app(\App\Core\Themes\ThemeSettings::class);
        $this->assertSame(['custom_css' => 'body{color:red}'], array_intersect_key($themes->clean(['custom_css' => 'body{color:red}']), ['custom_css' => 1]));

        app(\App\Core\Settings\SettingsManager::class)->set($themes->moduleId(), 'custom_css', '.x{color:red}</style><script>alert(1)</script>', Storefront::firstOrFail()->id);

        $html = $this->get('http://localhost/')->getContent();
        $this->assertStringContainsString('<style data-theme-custom>.x{color:red}<\/style><script>alert(1)</script></style>', $html);
    }
}
