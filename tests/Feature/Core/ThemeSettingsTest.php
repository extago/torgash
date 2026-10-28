<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Themes\ThemeSettings;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;

/**
 * A theme's own settings and the configurator (PLAN §37): values per
 * storefront printed as CSS only where they differ from what the stylesheet
 * already draws, a draft only the preview FRAME sees, and presets, sharing
 * and "default" going through the same cleaning.
 */
class ThemeSettingsTest extends TestCase
{
    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storefront = Storefront::query()->firstOrFail();
        $this->storefront->update(['theme' => 'default']);
    }

    public function test_an_untouched_storefront_is_the_theme_as_written(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-theme-settings', $html);
        $this->assertStringContainsString('<meta name="torgash-mode" content="auto">', $html);
    }

    public function test_a_storefronts_value_becomes_css_and_a_font_is_served_from_the_theme(): void
    {
        $this->set('primary', '#e11d48');
        $this->set('font', 'golos');
        $this->set('heading_weight', '500');

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString('--color-primary: #e11d48;', $html);
        $this->assertStringContainsString('font-family: "Golos Text"', $html);
        $this->assertStringContainsString('/themes/default/assets/fonts/golos-text-cyrillic-wght-normal.woff2', $html);
        $this->assertStringContainsString('font-weight: var(--torgash-heading-weight)', $html, 'a changed field brings its rule');

        $this->get('http://localhost/themes/default/assets/fonts/golos-text-cyrillic-wght-normal.woff2')->assertOk();
        $this->get('http://localhost/themes/default/assets/../theme.json')->assertNotFound();
    }

    /** A child theme that only changes defaults: its defaults are drawn, its parent's CSS is not assumed. */
    public function test_a_child_theme_draws_its_own_defaults(): void
    {
        $this->storefront->update(['theme' => 'crystal']);
        \Torgash\Catalog\Models\Category::create(['name' => ['en' => 'Strip root'], 'slug' => 'strip-root', 'enabled' => true]);

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString('torgash-header-icons', $html, 'the light header, crystal\'s default');
        $this->assertStringContainsString('torgash-category-strip', $html);
        $this->assertStringContainsString('font-family: "Golos Text"', $html, 'a default different from the base one prints');
    }

    public function test_a_draft_is_drawn_only_in_the_preview_frame_and_only_for_staff(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.themes.preview', $this->storefront), ['values' => ['primary' => '#7c3aed', 'header' => 'light']])->assertNoContent();

        $framed = $this->actingAs($admin)->get('http://localhost/', ['Sec-Fetch-Dest' => 'iframe'])->assertOk()->getContent();
        $this->assertStringContainsString('--color-primary: #7c3aed;', $framed);
        $this->assertStringContainsString('torgash-header-icons', $framed);

        $tab = $this->actingAs($admin)->get('http://localhost/', ['Sec-Fetch-Dest' => 'document'])->assertOk()->getContent();
        $this->assertStringNotContainsString('#7c3aed', $tab, 'the same storefront in a tab shows what is saved');

        // Opening the configurator again starts from what is saved.
        $this->actingAs($admin)->get(route('admin.themes.customize', $this->storefront))->assertOk();
        $this->assertStringNotContainsString('#7c3aed', $this->actingAs($admin)->get('http://localhost/', ['Sec-Fetch-Dest' => 'iframe'])->getContent());
    }

    /** The phone frame's touch answers (preview-touch.js) are staff's, in a frame, draft or none. */
    public function test_only_the_configurators_frame_answers_as_a_touch_screen(): void
    {
        $admin = $this->admin();

        $this->assertStringContainsString('preview-touch.js', $this->actingAs($admin)->get('http://localhost/', ['Sec-Fetch-Dest' => 'iframe'])->getContent());
        $this->assertStringNotContainsString('preview-touch.js', $this->actingAs($admin)->get('http://localhost/', ['Sec-Fetch-Dest' => 'document'])->getContent());
        $this->assertStringNotContainsString('preview-touch.js', $this->actingAs(User::factory()->create())->get('http://localhost/', ['Sec-Fetch-Dest' => 'iframe'])->getContent(),
            'a customer framing the shop gets no configurator script');
    }

    /** Values reach a <style> tag, so only what a field can answer survives. */
    public function test_only_answers_a_field_can_give_are_kept(): void
    {
        $clean = app(ThemeSettings::class)->clean([
            'primary' => 'red;} body{display:none',
            'width' => '9999',
            'font' => 'golos',
            'header_sticky' => '0',
            'no_such_key' => 'x',
        ]);

        $this->assertSame(['font' => 'golos', 'header_sticky' => false], $clean);
    }

    public function test_save_default_export_and_import(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.themes.save', $this->storefront), ['values' => ['primary' => '#22a34a', 'width' => '1296']])->assertRedirect();
        $this->assertSame('#22a34a', $this->value('primary'));

        $export = $this->actingAs($admin)->get(route('admin.themes.export', $this->storefront))->assertOk()->streamedContent();
        $this->assertSame('#22a34a', json_decode($export, true)['values']['primary']);

        $this->actingAs($admin)->post(route('admin.themes.reset', $this->storefront))->assertRedirect();
        $this->assertSame('#13b3f8', $this->value('primary'), 'back to the theme\'s own');

        $file = UploadedFile::fake()->createWithContent('theme.json', $export);
        $this->actingAs($admin)->post(route('admin.themes.import', $this->storefront), ['file' => $file])->assertRedirect();
        $this->assertSame('#22a34a', $this->value('primary'));
        $this->assertSame('1296', $this->value('width'));
    }

    /** "Share the settings" is an <x-modal>: the screen has no admin layout, so it loads the script that opens one itself. */
    public function test_the_share_window_opens_and_a_wrong_file_is_said_on_the_screen(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.themes.customize', $this->storefront))->assertOk()
            ->assertSee('data-open-dialog="modal-theme-share"', false)
            ->assertSee('/assets/js/actions.js', false);

        foreach (['not json at all', '{"values": {"nonsense": 1}}'] as $content) {
            $file = UploadedFile::fake()->createWithContent('theme.json', $content);
            $this->actingAs($admin)->post(route('admin.themes.import', $this->storefront), ['file' => $file])
                ->assertRedirect(route('admin.themes.customize', $this->storefront))
                ->assertSessionHasErrors('file');
        }
    }

    /** A preset is a whole look; its blocks are laid out only when asked, by the blocks module. */
    public function test_a_preset_replaces_the_look_and_on_request_the_blocks(): void
    {
        $admin = $this->admin();
        $this->set('width', '1696');

        $this->actingAs($admin)->post(route('admin.themes.preset', $this->storefront), ['preset' => 'soft'])->assertRedirect();
        $this->assertSame('#7c3aed', $this->value('primary'));
        $this->assertSame('1500', $this->value('width'), 'what the preset does not say goes back to the default');
        $this->assertSame(0, Block::where('zone', 'storefront.home.products')->where('settings->layout', 'carousel')->count());

        $this->actingAs($admin)->post(route('admin.themes.preset', $this->storefront), ['preset' => 'soft', 'with_blocks' => 1])->assertRedirect();
        $this->assertSame(3, Block::where('zone', 'storefront.home.products')->where('settings->layout', 'carousel')->count());
    }

    public function test_the_themes_screen_lists_themes_and_puts_one_on(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.themes.index'))->assertOk()->assertSee('Crystal')->assertSee('Extago.com');

        $this->actingAs($admin)->post(route('admin.themes.apply'), ['storefront' => $this->storefront->id, 'theme' => 'crystal'])->assertRedirect();
        $this->assertSame('crystal', $this->storefront->fresh()->theme);

        $this->actingAs(User::factory()->create())->get(route('admin.themes.index'))->assertForbidden();
    }
    public function test_the_configurator_saves_in_place_and_names_the_pages_to_look_at(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.themes.save', $this->storefront), ['values' => ['card_image' => 'portrait']])
            ->assertOk()->assertJsonStructure(['message']);
        $this->assertSame('portrait', $this->value('card_image'));

        $screen = $this->actingAs($admin)->get(route('admin.themes.customize', $this->storefront))->assertOk();
        $pages = collect($screen->viewData('previewPages'));

        // Core's own two, the catalogue's from its hook, in a visitor's order.
        $this->assertSame('/', parse_url($pages->first()['url'], PHP_URL_PATH));
        $this->assertSame('/account', parse_url($pages->last()['url'], PHP_URL_PATH));
        $this->assertTrue($pages->contains(fn ($page) => parse_url($page['url'], PHP_URL_PATH) === '/catalog'));
        $this->assertSame('phone', $screen->viewData('sectionMeta')['mobile']['device']);
    }

    public function test_a_number_field_takes_any_number_within_its_bounds(): void
    {
        $clean = app(ThemeSettings::class)->clean(['width' => '1504', 'radius_box' => '40', 'radius_button' => '10.0']);

        $this->assertSame('1504', $clean['width']);
        $this->assertArrayNotHasKey('radius_box', $clean, 'past its max');
        $this->assertSame('10', $clean['radius_button']);
    }

    public function test_a_product_cards_shape_and_the_catalogues_columns(): void
    {
        $this->set('card_image', 'landscape');
        $this->set('card_image_fit', 'cover');
        $this->set('catalog_columns', '5');
        \Torgash\Catalog\Models\Product::create([
            'sku' => 'COLS-1', 'name' => ['en' => 'Columns'], 'slug' => 'cols-1',
            'price' => 100_00, 'stock' => 5, 'status' => 'active',
        ]);

        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('--torgash-card-ratio: 4 / 3', $home);
        $this->assertStringContainsString('.torgash-card figure { aspect-ratio: var(--torgash-card-ratio); }', $home);
        $this->assertStringContainsString('--torgash-card-fit: cover', $home);

        $this->assertStringContainsString('data-cols="5"', $this->get('http://localhost/catalog')->assertOk()->getContent());
    }

    protected function set(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('theme.default', $key, $value, $this->storefront->id);
    }

    protected function value(string $key): mixed
    {
        $schema = app(ThemeSettings::class)->schema();

        return app(SettingsManager::class)->get('theme.default', $key, $schema[$key]['default'] ?? null, $this->storefront->id);
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
