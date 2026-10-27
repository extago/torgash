<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\Colour;
use App\Core\Themes\ThemeSettings;
use App\Models\Storefront;
use Tests\TestCase;

/**
 * The shop's colours with two palettes (PLAN §52): a colour that names a
 * `dark` value gets a twin field — empty is "automatic", worked out from the
 * light one; a typed colour prints exactly as typed. The text standing on a
 * colour (`on`) is white or dark by contrast. Nothing changed prints nothing.
 */
class ThemeColoursTest extends TestCase
{
    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storefront = Storefront::query()->firstOrFail();
        $this->storefront->update(['theme' => 'default']);
    }

    protected function set(string $key, mixed $value): void
    {
        // The storefront's own value for the page; the shop-wide one for a
        // question asked with no request (and so no current storefront).
        app(SettingsManager::class)->set('theme.default', $key, $value, $this->storefront->id);
        app(SettingsManager::class)->set('theme.default', $key, $value);
        app(ThemeSettings::class)->flush();
    }

    public function test_untouched_colours_print_no_palette(): void
    {
        $this->assertSame(['light' => [], 'dark' => []], app(ThemeSettings::class)->palettes());
        $this->assertStringNotContainsString('torgash-dark"]:not(#_)', $this->get('http://localhost/')->assertOk()->getContent());
    }

    public function test_a_light_colour_brings_its_text_and_an_automatic_dark_twin(): void
    {
        $this->set('bar_color', '#e8eef7');

        $palettes = app(ThemeSettings::class)->palettes();

        $this->assertSame('#e8eef7', $palettes['light']['--color-neutral']);
        $this->assertSame(Colour::DARK_INK, $palettes['light']['--color-neutral-content'], 'a light bar gets dark text');
        // A surface sinks in the dark palette, and its text turns light again.
        $this->assertLessThan(0.3, Colour::oklch($palettes['dark']['--color-neutral'])[0]);
        $this->assertSame(Colour::LIGHT_INK, $palettes['dark']['--color-neutral-content']);

        $html = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('--color-neutral: #e8eef7;', $html);
        $this->assertStringContainsString(':root[data-theme="torgash-dark"]:not(#_)', $html);
        $this->assertStringContainsString(':root:not([data-theme]):not(#_)', $html, 'the system\'s dark preference too');
    }

    public function test_the_dark_tone_paints_the_dark_surfaces_under_any_typed_twin(): void
    {
        $this->set('dark_tone', 'neutral');
        $dark = app(ThemeSettings::class)->palettes()['dark'];
        $this->assertSame('#1d1d20', $dark['--color-base-100']);
        $this->assertSame('#131315', $dark['--torgash-page-200']);
        $this->assertSame([], app(ThemeSettings::class)->palettes()['light'], 'the light scheme is not touched');

        // A dark colour typed for the cards is a decision about one colour.
        $this->set('card_color__dark', '#202020');
        $this->assertSame('#202020', app(ThemeSettings::class)->palettes()['dark']['--color-base-100']);

        // "From the base colour": the default's surfaces turned to its hue.
        $this->set('card_color__dark', '');
        $this->set('primary', '#7c3aed');
        $this->set('dark_tone', 'brand');
        [$L, , $H] = Colour::oklch(app(ThemeSettings::class)->palettes()['dark']['--color-base-200']);
        $this->assertEqualsWithDelta(Colour::oklch('#12161e')[0], $L, 0.01, 'as dark as the default');
        $this->assertEqualsWithDelta(Colour::oklch('#7c3aed')[2], $H, 5, 'leaning violet (8-bit channels move a faint hue a little)');

        $this->set('dark_tone', 'slate');
        $this->assertArrayNotHasKey('--color-base-200', app(ThemeSettings::class)->palettes()['dark'], 'the theme\'s own tone prints nothing');
    }

    public function test_a_typed_dark_colour_prints_exactly_as_typed(): void
    {
        $this->set('primary__dark', '#7c3aed');

        $palettes = app(ThemeSettings::class)->palettes();

        $this->assertSame([], $palettes['light'], 'the light palette is untouched');
        $this->assertSame('#7c3aed', $palettes['dark']['--color-primary']);
    }

    public function test_the_dark_twin_is_cleaned_like_a_colour_and_may_be_empty(): void
    {
        $clean = app(ThemeSettings::class)->clean(['primary__dark' => '', 'bar_color__dark' => '#ABCDEF', 'success__dark' => 'red;}']);

        $this->assertSame('', $clean['primary__dark']);
        $this->assertSame('#abcdef', $clean['bar_color__dark']);
        $this->assertArrayNotHasKey('success__dark', $clean);
        $this->assertArrayNotHasKey('primary__dark', app(ThemeSettings::class)->sections()['colours'] ?? [], 'drawn inside its colour, not as a field');
    }

    /** "Save as a style": the form's look under a name, shop-wide, applied like a preset, deletable. */
    public function test_a_style_is_saved_applied_and_deleted(): void
    {
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->post(route('admin.themes.styles.store', $this->storefront), [
            'name' => 'Forest',
            'values' => ['primary' => '#16a34a', 'primary__dark' => '', 'nonsense' => 'x', 'bar_color' => 'red'],
        ])->assertRedirect();

        $styles = app(SettingsManager::class)->get('theme.default', '_styles', []);
        $this->assertCount(1, $styles);
        $this->assertSame(['primary' => '#16a34a', 'primary__dark' => ''], $styles[0]['values'], 'cleaned like any value');

        app(SettingsManager::class)->set('theme.default', 'font', 'golos', $this->storefront->id);
        $this->post(route('admin.themes.styles.apply', [$this->storefront, $styles[0]['key']]))->assertRedirect();
        $manager = app(SettingsManager::class);
        $this->assertSame('#16a34a', $manager->get('theme.default', 'primary', null, $this->storefront->id));
        $this->assertNotSame('golos', $manager->get('theme.default', 'font', 'finlandica', $this->storefront->id), 'a style is a whole look');

        $this->delete(route('admin.themes.styles.destroy', [$this->storefront, $styles[0]['key']]))->assertRedirect();
        $this->assertSame([], $manager->get('theme.default', '_styles', []));
    }

    public function test_the_configurator_offers_the_themes_colour_combinations(): void
    {
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('admin.themes.customize', $this->storefront))
            ->assertOk()
            ->assertViewHas('colourSchemes', fn (array $schemes) => collect($schemes)->firstWhere('key', 'forest')['colours']['primary'] === '#16a34a');
    }

    /** A background is the theme's, a gradient or a picture we stored — printed as background-image only. */
    public function test_a_background_is_a_gradient_or_our_picture_over_the_zones_colour(): void
    {
        $settings = app(ThemeSettings::class);

        $this->assertSame(['header_background' => 'gradient:#29303d:#454254:135'], $settings->clean(['header_background' => 'gradient:#29303D:#454254:135']));
        $this->assertSame([], $settings->clean(['header_background' => 'gradient:#29303d:#454254:45']), 'only the offered directions');
        $this->assertSame([], $settings->clean(['header_background' => 'image:../../.env']));
        $this->assertSame([], $settings->clean(['header_background' => 'url(javascript:x)']));

        $this->set('header_background', 'gradient:#29303d:#454254:135');
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString('.torgash-header-bar { background-image: linear-gradient(135deg, #29303d, #454254); }', $html);
        $this->assertStringContainsString('torgash-header-bar', $html, 'the header carries the hook');
    }

    /** The admin's forms draw the shop's colours: the storefront's values, read without switching themes. */
    public function test_the_shop_palette_is_what_the_storefront_prints(): void
    {
        $palette = fn () => tap(app(\App\Core\Themes\ShopPalette::class), fn () => app()->forgetInstance(\App\Core\Themes\ShopPalette::class));

        $this->assertSame('#13b3f8', $palette()->colours($this->storefront)['primary']);
        $this->assertSame('', $palette()->styleVars($this->storefront), 'an untouched theme draws its own defaults');

        $this->set('accent', '#16a34a');
        $this->set('button_text', 'black');
        $vars = app(\App\Core\Themes\ShopPalette::class)->styleVars($this->storefront);

        $this->assertStringContainsString('--color-accent:#16a34a', $vars);
        $this->assertStringContainsString('--color-accent-content:'.Colour::ink('#16a34a'), $vars);
        $this->assertStringContainsString('--color-primary-content:#18181b', $vars, 'the buttons\' text the shop chose');
        $this->assertStringNotContainsString('--color-primary:', $vars);
    }

    public function test_a_sale_tinted_with_a_shop_colour_follows_it(): void
    {
        $this->set('primary', '#16a34a');
        app()->forgetInstance(\App\Core\Themes\ShopPalette::class);

        $sale = new \Torgash\Promotions\Models\Promotion(['tint' => 'primary']);

        $this->assertSame((int) round(Colour::oklch('#16a34a')[2]), $sale->hue());
    }

    public function test_colour_arithmetic(): void
    {
        $this->assertEqualsWithDelta(21.0, Colour::contrast('#ffffff', '#000000'), 0.01);
        $this->assertSame(Colour::DARK_INK, Colour::ink('#13b3f8'));
        $this->assertSame(Colour::LIGHT_INK, Colour::ink('#29303d'));

        // A readable shade is asked for, never applied by itself — and reads.
        $this->assertGreaterThanOrEqual(Colour::AA, Colour::contrast(Colour::readableOn('#13b3f8', '#ffffff'), '#ffffff'));
        $this->assertSame('#191b1e', Colour::readableOn('#191b1e', '#ffffff'), 'a colour that reads stays');

        // A text colour's twin rises until it reads on the dark page.
        $this->assertGreaterThanOrEqual(Colour::AA, Colour::contrast(Colour::darkTwin('#191b1e', 'text'), '#1c2230'));
        $this->assertSame('#13b3f8', Colour::darkTwin('#13b3f8', 'brand'), 'a brand colour stays');

        // OKLCH survives the round trip.
        [$L, $C, $H] = Colour::oklch('#7c3aed');
        $this->assertSame('#7c3aed', Colour::fromOklch($L, $C, $H));
    }
}
