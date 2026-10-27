<?php

namespace Torgash\Banners\Demo;

use App\Core\Demo\DemoPack;
use Torgash\Banners\Fields;
use Torgash\Banners\Models\Banner;
use Torgash\Banners\Models\Slider;
use Torgash\Banners\Models\Template;
use Torgash\Blocks\Models\Block;

/**
 * Its part of the demo shop (PLAN §55.3): the home page's slider — three big
 * slides pointing at the demo's deal, its sale and its blog — with a column
 * of two cards beside it, the two `banners` blocks sharing one row.
 *
 * Read from `database/demo/banners/banners.json`. Banners are made of the
 * templates the module installs (`hero`, `info`; the `slider` and
 * `stack` frames); a template the shop deleted takes its banners with it.
 * The pack has no pictures for them yet, so the slides stand on their
 * colours. Values go through Fields::accept() as the form's do. A zone that
 * already shows the shop's own banners is left alone.
 */
class BannersDemo
{
    public function install(DemoPack $pack): void
    {
        $templates = Template::query()->get()->keyBy('key');
        $made = 0;
        $taken = [];

        foreach ((array) ($pack->json('banners/banners')['sliders'] ?? []) as $sort => $row) {
            $frame = $templates[$row['frame'] ?? ''] ?? null;
            $zone = (string) ($row['zone'] ?? 'storefront.home.top');

            // A zone the shop already shows banners in is the shop's: the
            // dev shop got a second slider on top of its own (2026-10-27).
            // Asked once per zone, before the demo's own blocks are there.
            $taken[$zone] ??= Block::query()->where('zone', $zone)->where('type', 'banners')->where('enabled', true)->exists();

            if (! $frame || $taken[$zone]) {
                continue;
            }

            $slider = Slider::query()->create([
                'name' => $row['name'],
                'frame_id' => $frame->id,
                'settings' => (array) ($row['settings'] ?? []),
                'values' => [],
            ]);

            foreach ((array) ($row['banners'] ?? []) as $position => $item) {
                $template = $templates[$item['template'] ?? ''] ?? null;

                if (! $template) {
                    continue;
                }

                $banner = Banner::query()->create([
                    'name' => $item['name'],
                    'template_id' => $template->id,
                    'values' => $this->values($template, (array) ($item['values'] ?? [])),
                    'enabled' => true,
                    'rules_match' => 'all',
                ]);
                $banner->sliders()->attach($slider->id, ['position' => $position + 1]);
                $made++;
            }

            $this->block($zone, $slider, $sort, $row['row'] ?? null, $row['width'] ?? null);
        }

        $pack->say($made.' banners.');
    }

    /**
     * Each value checked against its template's field, a word kept per
     * language.
     *
     * @return array<string, mixed>
     */
    protected function values(Template $template, array $values): array
    {
        $clean = [];

        foreach ((array) $template->fields as $field) {
            $key = $field['key'] ?? null;

            if (! $key || ! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if (in_array($field['type'] ?? '', Fields::TRANSLATABLE, true) && is_array($value)) {
                $clean[$key] = array_filter(array_map(fn ($word) => Fields::accept($field, $word), $value), fn ($word) => $word !== null && $word !== '');
            } elseif (($accepted = Fields::accept($field, $value)) !== null) {
                $clean[$key] = $accepted;
            }
        }

        return $clean;
    }

    /** The block that places the slider, first in its zone — side by side with another when they share a `row`. */
    protected function block(string $zone, Slider $slider, int $sort, ?string $row, ?string $width): void
    {
        Block::create([
            'zone' => $zone,
            'type' => 'banners',
            'title' => $slider->name,
            'enabled' => true,
            'sort' => -10 + $sort,
            'settings' => ['slider' => (string) $slider->id],
            'row' => $row,
            'width' => $width,
            'rules_match' => 'all',
        ]);
    }
}
