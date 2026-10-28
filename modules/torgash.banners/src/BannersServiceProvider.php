<?php

namespace Torgash\Banners;

use App\Core\Modules\ModuleLifecycle;
use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Torgash\Banners\Models\Banner;
use Torgash\Banners\Models\Slider;
use Torgash\Banners\Models\Template;
use Torgash\Blocks\BlockTypes;
use Torgash\Blocks\Models\Block;

/**
 * Banners, sliders and their templates (PLAN §51). The storefront meets them
 * through ONE block type, `banners` — a slider wherever a block can stand —
 * so no zone, page or module has heard of a banner.
 */
class BannersServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    public function register(): void
    {
        $this->app->singleton(Presets::class);
        $this->app->singleton(Renderer::class);
        $this->app->singleton(PageStyles::class);
        $this->app->singleton(Templates::class);
        // What a page has printed (a template's CSS, the script) belongs to
        // that page, and the templates it read may be edited before the next:
        // a reused application starts the next one afresh.
        PerRequest::forget($this->app, PageStyles::class, Templates::class);
    }

    public function boot(): void
    {
        // A banner's dates switch it on and off with nothing written: a page
        // kept ready for guests is kept only until the next of them (PLAN
        // §61). Its clicks are counted where no page shows them.
        page_cache()
            ->changes(fn () => page_cache()->nextDateIn('m_banners_banners'))
            ->unseen('m_banners_clicks');

        // Its part of the demo shop (PLAN §55.3), after the pages and sales its slides point at.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'banners' => ['label' => 'Banners', 'after' => ['catalog', 'promotions', 'pages', 'blog'], 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\BannersDemo::class)->install($pack)],
        ]);

        Route::middleware(['web', 'auth', 'can:banners.manage'])
            ->prefix('admin/banners')
            ->name('admin.banners.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // Banners between the products of a catalogue listing (step 4): the
        // catalogue asks, the sliders that say "between products" answer.
        hook_listen('catalog.listing.inserts', fn (array $inserts, array $context) => $inserts + app(Listing::class)->inserts($context));

        // A click on a banner's link, told by a beacon (step 5). Outside `web`:
        // a beacon carries no CSRF token and needs no session; throttled per address.
        Route::post('banners/click', Http\ClickController::class)
            ->middleware('throttle:60,1')
            ->name('banners.click');

        // Banners in the exchange (step 5): one row each, a column per template field.
        (new BannerExim)->register();

        BlockTypes::register('banners', [
            'description' => 'A slider of banners, or a single banner — made in Content → Banners, each with its own dates and conditions.',
            'fits' => ['wide', 'narrow'],
            'label' => 'Banners',
            'icon' => 'gallery-horizontal-end',
            'view' => 'torgash.banners::blocks.banners',
            'schema' => [
                ['key' => 'slider', 'type' => 'select', 'label' => 'Slider',
                    // A closure: the shop's own sliders, read only when a form asks.
                    'options' => fn () => Slider::query()->orderBy('name')->get(['id', 'name'])
                        ->map(fn (Slider $slider) => ['value' => (string) $slider->id, 'label' => $slider->name])->all(),
                    'help' => 'Sliders and their banners are made in Content → Banners.'],
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading',
                    'help' => 'Optional. Above the slider; in a row of tabs this is the tab.'],
            ],
        ]);
    }

    /**
     * The presets become templates; then the `banner` block type of
     * torgash.blocks, gone into this module (PLAN §51.3), is moved: every
     * such block becomes an `info` banner in a column of its own, and the
     * block a `banners` block showing it — same zone, place and conditions.
     */
    public function install(): void
    {
        app(Presets::class)->seed();

        if (! class_exists(Block::class) || ! Schema::hasTable('m_blocks')) {
            return;
        }

        $info = Template::query()->where('key', 'info')->first();
        $column = Template::query()->where('key', 'stack')->first();

        if (! $info || ! $column) {
            return;
        }

        foreach (Block::query()->where('type', 'banner')->get() as $block) {
            $settings = (array) $block->settings;
            $heading = array_filter((array) ($settings['heading'] ?? []));
            $name = $block->title ?: (string) (pick_translation($heading) ?: 'Banner');
            $tone = in_array($settings['style'] ?? 'dark', ['dark', 'primary', 'accent', 'light'], true) ? $settings['style'] : 'dark';

            $banner = Banner::query()->create([
                'name' => $name,
                'template_id' => $info->id,
                'enabled' => true,
                'values' => array_filter([
                    'title' => $heading ?: null,
                    'text' => array_filter((array) ($settings['text'] ?? [])) ?: null,
                    'button' => array_filter((array) ($settings['button'] ?? [])) ?: null,
                    'link' => ($settings['link'] ?? '') ?: null,
                    'icon' => ($settings['icon'] ?? '') ?: null,
                    'tone' => $tone,
                ], fn ($value) => $value !== null),
            ]);

            $slider = Slider::query()->create(['name' => $name, 'frame_id' => $column->id, 'settings' => [], 'values' => []]);
            $slider->banners()->attach($banner->id, ['position' => 1]);

            $block->forceFill(['type' => 'banners', 'settings' => ['slider' => (string) $slider->id]])->save();
        }
    }

    /**
     * The blocks that showed a slider go with the sliders — a block of a
     * type nobody draws any more is a row the shop can do nothing with — and
     * every banner through its model, so its pictures go with it.
     */
    public function uninstall(): void
    {
        if (class_exists(Block::class) && Schema::hasTable('m_blocks')) {
            Block::query()->where('type', 'banners')->get()->each->delete();
        }

        if (Schema::hasTable('m_banners_banners')) {
            Banner::query()->get()->each->delete();
        }
    }
}
