<?php

namespace Torgash\Banners;

use App\Core\Content\Placeholders;
use Illuminate\Support\Facades\Blade;
use Torgash\Banners\Models\Banner;
use Torgash\Banners\Models\Slider;
use Torgash\Banners\Models\Template;

/**
 * Banners and sliders as the page draws them (PLAN §51): a banner is its
 * template's HTML for one shape with its own values in the tokens; a slider
 * is its frame's HTML with the engine's pieces in its tokens — {slides} (the
 * strip Embla moves), {items} (all of them, no script), {dots}, {prev},
 * {next}, {counter}, {progress}, {aside} (a second slider) — and the frame's
 * own fields.
 *
 * A template's CSS is printed once per page, before the first thing drawn
 * with it, and only on pages that draw it (`PageStyles`). Two queries for a
 * slider, whatever it holds.
 */
class Renderer
{
    public function __construct(protected PageStyles $styles) {}

    /**
     * @param  array<string, mixed>  $context  the zone's — what the display rules ask about
     */
    public function slider(int $sliderId, array $context = [], int $depth = 0): string
    {
        $slider = Slider::query()
            ->with(['banners' => fn ($query) => $query->running()->with('media')])
            ->find($sliderId);

        $frame = $slider ? app(Templates::class)->attach($slider)->frame : null;

        if (! $slider || ! $frame || ! $frame->isFrame()) {
            return '';
        }

        $shape = $frame->shape ?: 'wide';
        $banners = $slider->banners
            ->filter(fn (Banner $banner) => $banner->template && $banner->template->draws($shape) && $banner->isVisible($context))
            ->values();

        if ($banners->isEmpty()) {
            return '';
        }

        $drawn = $banners->map(fn (Banner $banner, int $i) => $this->banner($banner, $shape, $i === 0))->all();
        // A slider beside a slider, one level deep: a frame cannot nest itself for ever.
        $aside = $depth === 0 && $slider->aside_id && $slider->aside_id !== $slider->id ? $this->slider($slider->aside_id, $context, 1) : '';

        return $this->frame($frame, $drawn, $slider->options(), $aside, (array) $slider->values);
    }

    /**
     * A frame around banners already drawn — the storefront's slider, and the
     * template editor's preview with sample slides.
     *
     * @param  list<string>  $drawn
     * @param  array<string, mixed>  $options  `Slider::options()`
     * @param  array<string, mixed>  $values  the frame's fields
     */
    public function frame(Template $frame, array $drawn, array $options, string $aside = '', array $values = []): string
    {
        $count = count($drawn);
        $turns = $frame->turns();

        $pieces = [
            'slides' => $turns ? $this->strip($drawn) : '',
            'items' => implode("\n", $drawn),
            'dots' => $turns && $count > 1 ? $this->dots($count) : '',
            'prev' => $turns && $count > 1 ? $this->arrow('prev') : '',
            'next' => $turns && $count > 1 ? $this->arrow('next') : '',
            'counter' => $turns && $count > 1 ? '<span class="torgash-slider-counter" x-text="(selected + 1) + \' / \' + snaps">1 / '.$count.'</span>' : '',
            'progress' => $turns && $count > 1 ? '<span class="torgash-slider-progress" aria-hidden="true"><span x-bind:style="\'width:\' + progress + \'%\'"></span></span>' : '',
            'aside' => $aside,
        ];

        $fields = [];

        foreach ($frame->fieldList() as $field) {
            $fields[$field['key']] = $field;
        }

        $inner = self::expand((string) (($frame->html ?? [])['frame'] ?? ''), function (string $name, string $modifiers) use ($pieces, $fields, $values) {
            if (array_key_exists($name, $pieces)) {
                return $pieces[$name];
            }

            return isset($fields[$name]) ? Fields::render($fields[$name], $values, null, $modifiers) : null;
        });

        $style = '--per-view: '.$options['per_view'].'; --per-view-tablet: '.$options['per_view_tablet'].'; --per-view-phone: '.$options['per_view_phone'];

        if ($turns) {
            $this->pushScript();

            $open = '<section class="torgash-slider" data-frame="'.e($frame->key).'" style="'.$style.'"'
                .' x-data="torgashSlider('.e(json_encode($options)).')"'
                .' aria-roledescription="'.e(__('carousel')).'" aria-label="'.e(__('Banners')).'">';
            $close = '</section>';
        } else {
            $open = '<div class="torgash-banners" data-frame="'.e($frame->key).'" style="'.$style.'">';
            $close = '</div>';
        }

        return $this->styles->take($frame).$open.$inner.$close;
    }

    /**
     * One banner in one shape, wrapped so its template's CSS can address it
     * (`[data-template=hero] .title`) without touching anybody else's.
     */
    public function banner(Banner $banner, string $shape, bool $first = false): string
    {
        $template = $banner->template;

        if (! $template || ! $template->draws($shape)) {
            return '';
        }

        $this->pushClicks();

        return $this->styles->take($template)
            .'<div class="torgash-banner" data-banner="'.$banner->id.'" data-template="'.e($template->key).'" data-shape="'.$shape.'">'
            .$this->fill($template, (string) $template->html[$shape], (array) $banner->values, $banner, $first)
            .'</div>';
    }

    /** A template's HTML with the values in it — the banner's, or a preview's samples. */
    public function fill(Template $template, string $html, array $values, ?Banner $record, bool $first = false): string
    {
        $fields = [];

        foreach ($template->fieldList() as $field) {
            $fields[$field['key']] = $field;
        }

        return self::expand($html, function (string $name, string $modifiers) use ($fields, $values, $record, $first) {
            // The first slide's picture is the page's largest: fetched at once, the rest when near.
            if ($name === 'loading') {
                return $first ? 'fetchpriority="high"' : 'loading="lazy"';
            }

            return isset($fields[$name]) ? Fields::render($fields[$name], $values, $record, $modifiers) : null;
        });
    }

    /**
     * Core's grammar (`Placeholders::expandWith`) plus one modifier of the
     * templates' own: `{alt|optional}` may be empty without taking the
     * `[…]` around it away — a picture's description must not decide
     * whether the picture is drawn.
     *
     * @param  \Closure(string, string): ?string  $resolve  name, modifiers → text
     */
    public static function expand(string $html, \Closure $resolve): string
    {
        $gap = "\u{E000}";

        $out = Placeholders::expandWith($html, function (array $match) use ($resolve, $gap) {
            $modifiers = strtolower($match[3] ?? '');
            $value = $resolve(strtolower($match[1]), $modifiers);

            return $value === '' && str_contains($modifiers, '|optional') ? $gap : $value;
        });

        return str_replace($gap, '', $out);
    }

    /** @param list<string> $drawn */
    protected function strip(array $drawn): string
    {
        $count = count($drawn);
        $slides = '';

        foreach ($drawn as $i => $html) {
            $slides .= '<div class="torgash-slide'.($i === 0 ? ' is-selected' : '').'" role="group" aria-roledescription="'.e(__('slide')).'"'
                .' aria-label="'.e(__(':number of :total', ['number' => $i + 1, 'total' => $count])).'">'.$html.'</div>';
        }

        return '<div class="torgash-slider-viewport" x-ref="viewport"><div class="torgash-slider-track">'.$slides.'</div></div>';
    }

    protected function dots(int $count): string
    {
        $dots = '';

        for ($i = 0; $i < $count; $i++) {
            $dots .= '<button type="button" class="torgash-slider-dot" x-show="'.$i.' < snaps" x-bind:class="selected === '.$i.' && \'is-active\'"'
                .' x-bind:aria-current="selected === '.$i.'" aria-label="'.e(__('Slide :number', ['number' => $i + 1])).'" x-on:click="go('.$i.')"></button>';
        }

        return '<div class="torgash-slider-dots" x-cloak x-show="ready">'.$dots.'</div>';
    }

    protected function arrow(string $way): string
    {
        $icon = Blade::render('<x-icon :name="$name" size="md" />', ['name' => $way === 'prev' ? 'chevron-left' : 'chevron-right']);

        return '<button type="button" class="torgash-slider-arrow torgash-slider-'.$way.'" x-cloak x-show="ready"'
            .' x-bind:disabled="!can'.ucfirst($way).'"'
            .' aria-label="'.e($way === 'prev' ? __('Previous slide') : __('Next slide')).'" x-on:click="'.$way.'()">'.$icon.'</button>';
    }

    /** What every banner needs — the click counter, a video that plays while on screen — once, on a page that shows one. */
    protected function pushClicks(): void
    {
        if (! $this->styles->once('clicks-script')) {
            return;
        }

        app('view')->startPush('scripts', '<script nonce="'.csp_nonce().'" src="'.e(module_asset('torgash.banners', 'banners.js')).'"'
            .' data-url="'.e(route('banners.click')).'" defer></script>');
    }

    /** The slider's script, once, at the end of the page — before Alpine starts. */
    protected function pushScript(): void
    {
        if (! $this->styles->once('slider-script')) {
            return;
        }

        app('view')->startPush('scripts', '<script nonce="'.csp_nonce().'" src="'.e(module_asset('torgash.banners', 'slider.js')).'"></script>');
    }
}
