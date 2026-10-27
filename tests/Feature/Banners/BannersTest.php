<?php

namespace Tests\Feature\Banners;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Torgash\Banners\BannersServiceProvider;
use Torgash\Banners\Clicks;
use Torgash\Banners\Fields;
use Torgash\Banners\Listing;
use Torgash\Banners\Models\Banner;
use Torgash\Banners\Models\Slider;
use Torgash\Banners\Models\Template;
use Torgash\Banners\PageStyles;
use Torgash\Banners\Presets;
use Torgash\Banners\Renderer;
use Torgash\Banners\RichLine;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;
use Torgash\Catalog\Models\Product;

/**
 * Banners, sliders and their templates (PLAN §51): a banner is the values of
 * its template's fields; a template is HTML with {tokens} in core's grammar;
 * a frame template draws a slider around banners in its shape; the words
 * keep only a few tags; a template's CSS is printed once per page.
 */
class BannersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_words_keep_a_few_tags_and_lose_the_rest(): void
    {
        $this->assertSame('iPhone 17', RichLine::clean('iPhone 17'));
        $this->assertSame('17 Pro <s>|</s> 17 Pro Max', RichLine::clean('17 Pro <s>|</s> 17 Pro Max'));
        $this->assertSame('<span class="text-gradient">Apple</span> iPhone', RichLine::clean('<span class="text-gradient" onclick="x()">Apple</span> iPhone'));
        $this->assertSame('Hi there', RichLine::clean('Hi<script>alert(1)</script> <a href="javascript:x">there</a>'));
        $this->assertSame('<span style="color: red">A</span>', RichLine::clean('<span style="color: red; background: url(https://evil.test/x.png)">A</span>'));
    }

    public function test_the_presets_are_templates_once_the_module_is_installed(): void
    {
        $keys = Template::query()->pluck('kind', 'key')->all();

        foreach (['hero' => 'banner', 'info' => 'banner', 'picture' => 'banner', 'slider' => 'frame', 'carousel' => 'frame', 'stack' => 'frame', 'slider-aside' => 'frame'] as $key => $kind) {
            $this->assertSame($kind, $keys[$key] ?? null, $key);
        }

        $hero = $this->template('hero');
        $this->assertSame(['wide'], $hero->shapes());
        $this->assertSame(['wide', 'card', 'strip'], $this->template('info')->shapes());
        $this->assertSame('module:hero', $hero->preset);
    }

    public function test_a_template_fills_its_fields_with_brackets_vanishing_around_an_empty_one(): void
    {
        $template = Template::query()->create(['key' => 'promo', 'kind' => 'banner', 'name' => 'Promo', 'css' => '.p{color:red}',
            'html' => ['card' => '<div class="p {zoom}" style="background: {bg}">[<b>{title}</b>][<i>{note}</i>]<img src="x" alt="{alt|optional}">{unknown}</div>'],
            'fields' => [
                ['key' => 'title', 'type' => 'text'], ['key' => 'note', 'type' => 'text'], ['key' => 'alt', 'type' => 'text'],
                ['key' => 'bg', 'type' => 'color', 'default' => '#ffdd2d'], ['key' => 'zoom', 'type' => 'checkbox', 'on' => 'is-zoom'],
            ]]);
        $banner = Banner::query()->create(['name' => 'X', 'template_id' => $template->id,
            'values' => ['title' => ['en' => 'Sale <"20%">'], 'bg' => 'red; } body {', 'zoom' => true]]);

        $html = app(Renderer::class)->banner($banner->load('template'), 'card');

        $this->assertStringContainsString('<style data-banner-template="promo">.p{color:red}</style>', $html);
        $this->assertStringContainsString('data-template="promo" data-shape="card"', $html);
        $this->assertStringContainsString('class="p is-zoom" style="background: #ffdd2d"', $html, 'a colour that is not a #hex is the default');
        $this->assertStringContainsString('<b>Sale &lt;&quot;20%&quot;&gt;</b>', $html, 'a line of text is escaped');
        $this->assertStringNotContainsString('<i>', $html, 'an empty field takes its brackets away');
        $this->assertStringContainsString('alt=""', $html, '|optional keeps its fragment');
        $this->assertStringContainsString('{unknown}', $html, 'a token nobody described is left for its author to see');
        $this->assertSame('', app(Renderer::class)->banner($banner, 'wide'), 'a shape the template does not draw is nothing');

        // The CSS once per page, however many banners use it.
        $this->assertStringNotContainsString('<style', app(Renderer::class)->banner($banner, 'card'));
    }

    public function test_a_card_carries_its_links_and_each_language_keeps_its_own_text(): void
    {
        app(Presets::class)->seed(['card']);
        $card = $this->template('card');
        $this->assertSame(['card'], $card->shapes());
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->post('http://localhost/admin/banners', [
            'name' => 'iPhone 17', 'template_id' => $card->id, 'enabled' => '1',
            'values' => ['title' => 'iPhone 17', 'chips' => [
                ['text' => '17 Pro', 'link' => '/iphone/17-pro/'],
                ['text' => 'Broken', 'link' => 'javascript:alert(1)'],
                ['text' => '17 Pro Max', 'link' => '/iphone/17-pro-max/'],
            ]],
        ])->assertRedirect();

        $banner = Banner::query()->sole();
        $this->assertEquals([['text' => ['en' => '17 Pro'], 'link' => '/iphone/17-pro/'], ['text' => ['en' => '17 Pro Max'], 'link' => '/iphone/17-pro-max/']], $banner->values['chips']);

        // The Russian form: the first row typed, the second left as it was —
        // empty in Russian, which must not take its English text away.
        session(['admin_content_locale' => 'ru']);
        $this->put('http://localhost/admin/banners/'.$banner->id, [
            'name' => 'iPhone 17', 'template_id' => $card->id, 'enabled' => '1',
            'values' => ['title' => 'iPhone 17', 'chips' => [['text' => '17 Про', 'link' => '/iphone/17-pro/'], ['text' => '', 'link' => '/iphone/17-pro-max/']]],
        ])->assertRedirect();

        $chips = $banner->fresh()->values['chips'];
        $this->assertEquals(['en' => '17 Pro', 'ru' => '17 Про'], $chips[0]['text']);
        $this->assertEquals(['en' => '17 Pro Max'], $chips[1]['text']);

        $html = app(Renderer::class)->banner($banner->fresh()->load('template'), 'card');
        $this->assertStringContainsString('<div class="hb-card-chips"><a href="/iphone/17-pro/">17 Pro</a><a href="/iphone/17-pro-max/">17 Pro Max</a></div>', $html);
    }

    public function test_a_sliders_banners_stand_between_the_products_as_cards_or_strips(): void
    {
        $slider = $this->slider('carousel', ['listing' => ['on' => true, 'after' => 2, 'every' => 3, 'rules' => [], 'match' => 'all']]);
        $slider->banners()->attach([
            $this->banner('info', ['title' => ['en' => 'First place']])->id => ['position' => 1],
            $this->banner('info', ['title' => ['en' => 'Second place']])->id => ['position' => 2],
            $this->banner('hero', ['title' => ['en' => 'Wide only']])->id => ['position' => 3],
        ]);
        $listing = app(Listing::class);

        $grid = $listing->inserts(['layout' => 'grid', 'count' => 9]);
        $this->assertSame([2, 5, 8], array_keys($grid), 'after the 2nd, then every 3 — never after the last');
        $this->assertStringContainsString('data-shape="card"', $grid[2]);
        $this->assertStringContainsString('First place', $grid[2]);
        $this->assertStringContainsString('Second place', $grid[5]);
        $this->assertStringContainsString('First place', $grid[8], 'round and round; a template with no card shape sits it out');

        $list = $listing->inserts(['layout' => 'list', 'count' => 9]);
        $this->assertStringContainsString('data-shape="strip"', $list[2]);

        // Each banner once, and only where its listing rules say.
        $slider->update(['settings' => ['listing' => ['on' => true, 'after' => 2, 'every' => 3, 'once' => true, 'rules' => [], 'match' => 'all']]]);
        $this->assertSame([2, 5], array_keys($listing->inserts(['layout' => 'grid', 'count' => 9])));

        $slider->update(['settings' => ['listing' => ['on' => true, 'after' => 2, 'every' => 0,
            'rules' => [['kind' => 'locale', 'operator' => 'in', 'value' => ['ru']]], 'match' => 'all']]]);
        $this->assertSame([], $listing->inserts(['layout' => 'grid', 'count' => 9]));
    }

    public function test_the_catalogue_puts_the_answer_in_its_grid(): void
    {
        $slider = $this->slider('carousel', ['listing' => ['on' => true, 'after' => 1, 'every' => 0, 'rules' => [], 'match' => 'all']]);
        $slider->banners()->attach($this->banner('info', ['title' => ['en' => 'Pay in parts']])->id, ['position' => 1]);

        foreach (['BAN-1', 'BAN-2'] as $sku) {
            Product::create(['sku' => $sku, 'name' => ['en' => 'Phone '.$sku], 'slug' => strtolower($sku),
                'price' => 100_00, 'stock' => 3, 'status' => 'active', 'availability_status' => 'in_stock']);
        }

        $html = $this->get('http://localhost/catalog')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<div class="torgash-listing-insert p-2"><style data-banner-template="info">.*Pay in parts#s', $html);
    }

    public function test_a_slide_carries_a_video_of_its_own_behind_it(): void
    {
        $ffmpeg = new Process(['ffmpeg', '-version']);
        $ffmpeg->run();

        if (! $ffmpeg->isSuccessful()) {
            $this->markTestSkipped('ffmpeg makes the clip this test uploads.');
        }

        $hero = $this->template('hero');
        app(Presets::class)->restore($hero);
        $this->actingAs(User::role('admin')->firstOrFail());
        $clip = storage_path('app/files-tmp/loop-'.uniqid().'.mp4');
        @mkdir(dirname($clip), 0775, true);
        (new Process(['ffmpeg', '-hide_banner', '-nostdin', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x180:rate=10:duration=1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $clip]))->mustRun();

        try {
            $this->post('http://localhost/admin/banners', [
                'name' => 'Video slide', 'template_id' => $hero->id, 'enabled' => '1',
                'values' => ['title' => 'iPhone 17', 'dim' => '1'],
                'images' => ['video' => new UploadedFile($clip, 'Loop iPhone.mp4', 'video/mp4', null, true)],
            ])->assertRedirect()->assertSessionHasNoErrors();

            $banner = Banner::query()->sole();
            $media = $banner->getFirstMedia(Fields::collection('video'));
            $this->assertNotNull($media);
            $this->assertSame('loop-iphone.mp4', $media->file_name);
            $this->assertNull($banner->thumbUrl(), 'a video is not the list\'s picture');

            $html = app(Renderer::class)->banner($banner->load('template'), 'wide');
            $this->assertMatchesRegularExpression('#<video class="hb-hero-video" src="[^"]+loop-iphone\.mp4" muted loop playsinline preload="none" data-autoplay#', $html);
            $this->assertStringContainsString('hb-hero-dim', $html);

            // A file that is not a video is refused, whatever its name.
            $fake = UploadedFile::fake()->createWithContent('clip.mp4', str_repeat('not a video ', 50));
            $this->put('http://localhost/admin/banners/'.$banner->id, [
                'name' => 'Video slide', 'template_id' => $hero->id, 'enabled' => '1', 'values' => ['title' => 'iPhone 17'],
                'images' => ['video' => $fake],
            ])->assertSessionHasErrors();
            $this->assertNotNull($banner->fresh()->getFirstMedia(Fields::collection('video')), 'the video it had stays');
        } finally {
            @unlink($clip);
        }
    }

    public function test_a_click_on_a_banner_is_counted_by_the_day(): void
    {
        $banner = $this->banner('info', ['title' => ['en' => 'Pay later'], 'link' => '/sale']);
        $off = $this->banner('info', ['title' => ['en' => 'Off']], ['enabled' => false]);

        $html = app(Renderer::class)->banner($banner->load('template'), 'card');
        $this->assertStringContainsString('data-banner="'.$banner->id.'"', $html, 'the script finds the banner by it');

        // A beacon: no session, no CSRF token.
        $this->post('http://localhost/banners/click', ['banner' => $banner->id])->assertNoContent();
        $this->post('http://localhost/banners/click', ['banner' => $banner->id])->assertNoContent();
        $this->post('http://localhost/banners/click', ['banner' => $off->id])->assertNoContent();
        $this->post('http://localhost/banners/click', ['banner' => 999999])->assertNoContent();

        $summary = app(Clicks::class)->summary([$banner->id, $off->id]);
        $this->assertSame(['week' => 2, 'month' => 2, 'total' => 2], $summary[$banner->id]);
        $this->assertArrayNotHasKey($off->id, $summary, 'a banner not on the page is not counted');

        $this->actingAs(User::role('admin')->firstOrFail());
        $this->get('http://localhost/admin/banners/'.$banner->id.'/edit')->assertOk()->assertSee(__('Clicks'));
    }

    public function test_a_slider_shows_the_banners_running_for_this_visitor_in_its_order(): void
    {
        $slider = $this->slider('slider', ['autoplay' => 5]);
        $second = $this->banner('hero', ['title' => ['en' => 'Second slide']]);
        $first = $this->banner('hero', ['title' => ['en' => 'First slide']]);
        $later = $this->banner('hero', ['title' => ['en' => 'Not yet']], ['starts_at' => now()->addDay()]);
        $over = $this->banner('hero', ['title' => ['en' => 'Gone']], ['ends_at' => now()->subMinute()]);
        $off = $this->banner('hero', ['title' => ['en' => 'Switched off']], ['enabled' => false]);
        $russian = $this->banner('hero', ['title' => ['en' => 'For Russian readers']], ['rules' => [['kind' => 'locale', 'operator' => 'in', 'value' => ['ru']]]]);
        $card = $this->banner('info', ['title' => ['en' => 'A card in a wide slider']]);

        $slider->banners()->attach([
            $first->id => ['position' => 1], $second->id => ['position' => 2], $later->id => ['position' => 3],
            $over->id => ['position' => 4], $off->id => ['position' => 5], $russian->id => ['position' => 6],
            $card->id => ['position' => 7],
        ]);

        $html = app(Renderer::class)->slider($slider->id);

        $this->assertLessThan(strpos($html, 'Second slide'), strpos($html, 'First slide'));
        foreach (['Not yet', 'Gone', 'Switched off', 'For Russian readers'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }
        // `info` draws a wide shape too; a template that draws none would be skipped.
        $this->assertStringContainsString('A card in a wide slider', $html);
        $this->assertStringContainsString('x-data="torgashSlider(', $html);
        $this->assertStringContainsString('&quot;autoplay&quot;:5', $html);
        $this->assertStringContainsString('aria-label="1 of 3"', $html);
        $this->assertStringContainsString('torgash-slider-dot', $html);

        // Nothing running: nothing at all, so the block around it vanishes too.
        $slider->banners()->sync([]);
        $this->assertSame('', app(Renderer::class)->slider($slider->id));
    }

    public function test_a_banner_whose_template_cannot_draw_the_frames_shape_is_skipped(): void
    {
        $slider = $this->slider('carousel');
        $slider->banners()->attach([
            $this->banner('hero', ['title' => ['en' => 'Only wide']])->id => ['position' => 1],
            $this->banner('picture', ['alt' => ['en' => 'A card picture']])->id => ['position' => 2],
        ]);
        $picture = UploadedFile::fake()->image('p.jpg', 300, 400);
        Banner::query()->latest('id')->first()->addMedia($picture->getRealPath())->preservingOriginal()->toMediaCollection(Fields::collection('image'));

        $html = app(Renderer::class)->slider($slider->id);

        $this->assertStringNotContainsString('Only wide', $html);
        $this->assertStringContainsString('alt="A card picture"', $html);
    }

    public function test_a_column_needs_no_script_and_a_frame_can_hold_a_second_slider_beside_it(): void
    {
        $column = $this->slider('stack');
        $column->banners()->attach([
            $this->banner('info', ['title' => ['en' => 'Pay later']])->id => ['position' => 1],
            $this->banner('info', ['title' => ['en' => 'Instalments']])->id => ['position' => 2],
        ]);

        $alone = app(Renderer::class)->slider($column->id);
        $this->assertStringContainsString('Instalments', $alone);
        $this->assertStringNotContainsString('torgashSlider', $alone);

        $main = $this->slider('slider-aside', [], ['aside_id' => $column->id]);
        $main->banners()->attach($this->banner('hero', ['title' => ['en' => 'iPhone 17']])->id, ['position' => 1]);
        app()->forgetInstance(PageStyles::class);

        $html = app(Renderer::class)->slider($main->id);
        $this->assertMatchesRegularExpression('#class="hf-aside-column">.*Pay later.*Instalments#s', $html);
        $this->assertStringContainsString('iPhone 17', $html);
    }

    public function test_a_block_places_the_slider_and_the_zone_context_reaches_the_banners_rules(): void
    {
        $slider = $this->slider('slider');
        $slider->banners()->attach($this->banner('hero', ['title' => ['en' => 'iPhone Air'], 'background' => '#112233', 'scheme' => 'dark'])->id, ['position' => 1]);

        $block = Block::query()->create(['zone' => 'storefront.home.content', 'type' => 'banners', 'enabled' => true, 'sort' => 1,
            'settings' => ['slider' => (string) $slider->id], 'rules_match' => 'all']);

        $html = app(ZoneRenderer::class)->render(collect([$block]));

        $this->assertStringContainsString('torgash-block-banners', $html);
        $this->assertStringContainsString('iPhone Air', $html);
        $this->assertStringContainsString('--slide-bg: #112233', $html);
        $this->assertStringContainsString('hb-hero-dark', $html);
    }

    public function test_the_admin_makes_a_banner_from_its_templates_fields(): void
    {
        $slider = $this->slider('slider');
        $hero = $this->template('hero');
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->post('http://localhost/admin/banners', [
            'name' => 'iPhone Air',
            'template_id' => $hero->id,
            'enabled' => '1',
            'values' => [
                'eyebrow' => 'New arrival',
                'title' => 'iPhone <b>Air</b><script>x</script>',
                'button' => 'Order now',
                'link' => '/iphone/iphone-air/',
                'background' => '#F5F5F7',
                'scheme' => 'sideways',
            ],
            'sliders' => [$slider->id],
            'images' => ['image' => UploadedFile::fake()->image('air.jpg', 1200, 700)],
        ])->assertRedirect();

        $banner = Banner::query()->sole();
        $this->assertSame('iPhone <b>Air</b>', $banner->values['title']['en']);
        $this->assertSame('#f5f5f7', $banner->values['background']);
        $this->assertArrayNotHasKey('scheme', $banner->values, 'not one of the options: the default stands');
        $this->assertNotNull($banner->getFirstMedia(Fields::collection('image')));
        $this->assertSame([$slider->id], $banner->sliders()->pluck('m_banners_sliders.id')->all());

        // A link that is not an address is refused, not stored into an href.
        $this->put('http://localhost/admin/banners/'.$banner->id, ['name' => 'X', 'template_id' => $hero->id, 'values' => ['link' => 'javascript:alert(1)']])
            ->assertSessionHasErrors('values.link');

        $this->get('http://localhost/admin/banners')->assertOk()->assertSee('iPhone Air');
        $this->get('http://localhost/admin/banners/'.$banner->id.'/edit')->assertOk();
        $this->get('http://localhost/admin/banners/create')->assertOk();
        $this->get('http://localhost/admin/banners/sliders')->assertOk();
        $this->get('http://localhost/admin/banners/sliders/'.$slider->id.'/edit')->assertOk()->assertSee('iPhone Air');
        $this->get('http://localhost/admin/banners/sliders/create')->assertOk();
    }

    public function test_a_copy_starts_off_and_in_no_slider_with_the_same_pictures(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $slider = $this->slider('slider');
        $banner = $this->banner('hero', ['title' => ['en' => 'Original']]);
        $picture = UploadedFile::fake()->image('a.jpg', 600, 300);
        $banner->addMedia($picture->getRealPath())->preservingOriginal()->toMediaCollection(Fields::collection('image'));
        $slider->banners()->attach($banner->id, ['position' => 1]);

        $this->post('http://localhost/admin/banners/'.$banner->id.'/duplicate')->assertRedirect();

        $copy = Banner::query()->whereKeyNot($banner->id)->sole();
        $this->assertFalse($copy->enabled);
        $this->assertSame(0, $copy->sliders()->count());
        $this->assertNotNull($copy->getFirstMedia(Fields::collection('image')));
    }

    public function test_the_slider_keeps_the_dragged_order_and_its_frame(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $a = $this->banner('hero', []);
        $b = $this->banner('hero', []);
        $carousel = $this->template('carousel');

        $this->post('http://localhost/admin/banners/sliders', [
            'name' => 'Main', 'frame_id' => $carousel->id, 'effect' => 'fade', 'autoplay' => 7, 'loop' => '1', 'per_view' => 4, 'per_view_tablet' => 3, 'per_view_phone' => 2,
            'banners' => [$b->id, $a->id],
        ])->assertRedirect();

        $slider = Slider::query()->sole();
        $this->assertSame($carousel->id, $slider->frame_id);
        $this->assertSame([$b->id, $a->id], $slider->banners()->pluck('m_banners_banners.id')->all());
        $this->assertSame(['effect' => 'fade', 'autoplay' => 7, 'loop' => true, 'per_view' => 4, 'per_view_tablet' => 3, 'per_view_phone' => 2, 'drag' => 'snap', 'step' => 'one', 'speed' => 'normal'], $slider->options());
    }

    public function test_a_slider_glides_peeks_and_turns_by_the_view(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $carousel = $this->template('carousel');
        $banner = $this->banner('hero', []);

        $this->post('http://localhost/admin/banners/sliders', [
            'name' => 'Cards', 'frame_id' => $carousel->id, 'per_view' => '4,3', 'per_view_phone' => '1.5',
            'drag' => 'glide', 'step' => 'view', 'speed' => 'slow', 'banners' => [$banner->id, $this->banner('hero', [])->id],
        ])->assertRedirect();

        $options = Slider::query()->sole()->options();
        $this->assertSame(4.3, $options['per_view']);
        $this->assertSame(1.5, $options['per_view_phone']);
        // Not typed: a tablet shows what it showed before it had a field.
        $this->assertSame(4.3, Slider::shapeOptions(['per_view' => 4.3])['per_view_tablet']);
        $this->assertSame(['glide', 'view', 'slow'], [$options['drag'], $options['step'], $options['speed']]);

        // Nonsense falls back rather than reaching the script.
        $this->assertSame([8, 'snap', 'one', 'normal'], array_values(array_intersect_key(
            Slider::shapeOptions(['drag' => 'x', 'step' => 'y', 'speed' => 'z', 'per_view' => 99]),
            array_flip(['drag', 'step', 'speed', 'per_view']),
        )));
    }

    public function test_a_template_is_written_in_the_admin_its_fields_found_in_its_html(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->post('http://localhost/admin/banners/templates', [
            'kind' => 'banner', 'key' => 'promo-card', 'name' => 'Promo card',
            'html' => ['card' => '<style>.x{color:red}</style><div class="x">[<b>{title}</b>]<img src="{cover}"><script>alert(1)</script></div>'],
            'css' => '.y{color:blue}',
            'fields' => [['key' => 'cover', 'type' => 'image', 'label' => 'Cover']],
        ])->assertRedirect();

        $template = Template::query()->where('key', 'promo-card')->sole();
        $this->assertSame(['card' => '<div class="x">[<b>{title}</b>]<img src="{cover}"></div>'], $template->html, 'the <style> moved out, the <script> is gone');
        $this->assertStringContainsString('.x{color:red}', $template->css);
        // The described one first (the form's order), the new token after it as a line of text.
        $this->assertSame([['cover', 'image'], ['title', 'text']], array_map(fn ($field) => [$field['key'], $field['type']], $template->fieldList()));

        // The preview draws it with sample values.
        $this->post('http://localhost/admin/banners/templates/preview', [
            'kind' => 'banner', 'html' => ['card' => '<b>{title}</b>'], 'fields' => [['key' => 'title', 'type' => 'text', 'label' => 'Heading']],
        ])->assertOk()->assertSee('<b>Heading</b>', false);

        // Export and import: one file, a new template under a free key.
        $file = $this->get('http://localhost/admin/banners/templates/'.$template->id.'/export')->assertOk()->streamedContent();
        $this->post('http://localhost/admin/banners/templates/import', ['file' => UploadedFile::fake()->createWithContent('t.json', $file)])->assertRedirect();
        $this->assertSame($template->html, Template::query()->where('key', 'promo-card-2')->sole()->html);

        $this->get('http://localhost/admin/banners/templates')->assertOk()->assertSee('Promo card');
        $this->get('http://localhost/admin/banners/templates/'.$template->id.'/edit')->assertOk();
    }

    public function test_a_preset_is_put_back_and_a_template_in_use_is_not_deleted(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $hero = $this->template('hero');
        // What the preset's files say — the test database's copy may be older than them.
        $original = app(Presets::class)->all()['hero']['html'];
        $hero->update(['html' => ['wide' => '<div>changed</div>'], 'css' => '']);
        $this->banner('hero', []);

        $this->post('http://localhost/admin/banners/templates/'.$hero->id.'/restore')->assertRedirect();
        $this->assertSame($original, $hero->fresh()->html);

        $this->delete('http://localhost/admin/banners/templates/'.$hero->id)->assertSessionHasErrors();
        $this->assertNotNull($hero->fresh());
    }

    public function test_templates_need_their_own_right(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['admin.access', 'banners.manage']);

        $this->actingAs($manager)->get('http://localhost/admin/banners')->assertOk();
        $this->actingAs($manager)->get('http://localhost/admin/banners/templates')->assertForbidden();
    }

    public function test_installing_moves_the_old_banner_blocks_into_sliders(): void
    {
        $block = Block::query()->create(['zone' => 'catalog.product.after_buy_box', 'type' => 'banner', 'title' => 'Pay in parts', 'enabled' => true, 'sort' => 1,
            'settings' => ['heading' => ['en' => 'Pay in parts', 'ru' => 'Покупка частями'], 'text' => ['en' => 'Up to 10 payments'], 'icon' => 'wallet-cards', 'style' => 'primary', 'link' => ''],
            'rules' => [['kind' => 'locale', 'operator' => 'in', 'value' => ['en']]], 'rules_match' => 'all']);

        (new BannersServiceProvider($this->app))->install();

        $block->refresh();
        $this->assertSame('banners', $block->type);
        $slider = Slider::query()->findOrFail((int) $block->settings['slider']);
        $this->assertSame('stack', $slider->frame->key);
        $banner = $slider->banners()->sole();
        $this->assertSame('info', $banner->template->key);
        $this->assertSame(['en' => 'Pay in parts', 'ru' => 'Покупка частями'], $banner->values['title']);
        $this->assertSame('primary', $banner->values['tone']);
        $this->assertSame('wallet-cards', $banner->values['icon']);
        $this->assertSame('locale', $block->rules[0]['kind'], 'the block keeps its own conditions');
    }

    protected function template(string $key): Template
    {
        return Template::query()->where('key', $key)->firstOrFail();
    }

    protected function slider(string $frame, array $settings = [], array $extra = []): Slider
    {
        return Slider::query()->create(['name' => $frame, 'frame_id' => $this->template($frame)->id, 'settings' => $settings, 'values' => [], ...$extra]);
    }

    protected function banner(string $template, array $values, array $extra = []): Banner
    {
        return Banner::query()->create(['name' => 'B', 'template_id' => $this->template($template)->id, 'enabled' => true, 'values' => $values, ...$extra]);
    }
}
