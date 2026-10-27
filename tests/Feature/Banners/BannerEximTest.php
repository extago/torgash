<?php

namespace Tests\Feature\Banners;

use Tests\TestCase;
use Torgash\Banners\Models\Banner;
use Torgash\Banners\Models\Slider;
use Torgash\Banners\Models\Template;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\SchemaRegistry;

/**
 * Banners in the exchange (PLAN §51, step 5): a column per template field —
 * a word one per language — matched by id, so the shop's own file updates its
 * own banners and another shop's makes new ones.
 */
class BannerEximTest extends TestCase
{
    public function test_a_banner_goes_out_and_comes_back(): void
    {
        $hero = Template::query()->where('key', 'hero')->firstOrFail();
        $slider = Slider::query()->create(['name' => 'Home', 'frame_id' => Template::query()->where('key', 'slider')->value('id'), 'settings' => [], 'values' => []]);
        $banner = Banner::query()->create(['name' => 'Air', 'template_id' => $hero->id, 'enabled' => true,
            'values' => ['title' => ['en' => 'iPhone <b>Air</b>', 'ru' => 'Айфон <b>Air</b>'], 'background' => '#112233', 'scheme' => 'dark']]);
        $banner->sliders()->attach($slider->id, ['position' => 1]);

        $columns = collect(app(SchemaRegistry::class)->fieldsFor('banner'))->keyBy('key');
        $out = $columns->map(fn (array $column) => $column['get']($banner->fresh()->load(['template', 'media', 'sliders']), []))->all();

        $this->assertSame('hero', $out['template']);
        $this->assertSame('iPhone <b>Air</b>', $out['f_title_en']);
        $this->assertSame('Айфон <b>Air</b>', $out['f_title_ru']);
        $this->assertSame('#112233', $out['f_background']);
        $this->assertSame('Home', $out['sliders']);

        // Back in: the same id updates; the words are cleaned like the form's;
        // a colour that is not one is not written.
        app(ImportRunner::class)->commit('banner', [['row' => [
            'id' => (string) $banner->id, 'template' => 'hero', 'name' => 'Air 2',
            'f_title_en' => 'iPhone Air <script>x</script>2', 'f_background' => 'red', 'f_scheme' => 'light', 'sliders' => '',
        ]]]);

        $banner->refresh();
        $this->assertSame('Air 2', $banner->name);
        $this->assertSame('iPhone Air 2', $banner->values['title']['en']);
        $this->assertSame('Айфон <b>Air</b>', $banner->values['title']['ru'], 'a language without its column stays');
        $this->assertSame('#112233', $banner->values['background']);
        $this->assertSame('light', $banner->values['scheme']);
        $this->assertSame(0, $banner->sliders()->count(), 'the cell is the list of sliders');

        // An id this shop has no banner by: a new one, in the named template.
        app(ImportRunner::class)->commit('banner', [['row' => ['id' => '999999', 'template' => 'info', 'name' => 'Pay later', 'f_title_en' => 'Pay later', 'sliders' => 'Home, Nowhere']]]);

        $new = Banner::query()->where('name', 'Pay later')->sole();
        $this->assertSame('info', $new->template->key);
        $this->assertSame([$slider->id], $new->sliders()->pluck('m_banners_sliders.id')->all(), 'a slider the shop has not got is skipped');
    }
}
