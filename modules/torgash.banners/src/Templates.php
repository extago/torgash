<?php

namespace Torgash\Banners;

use Illuminate\Database\Eloquent\Collection;
use Torgash\Banners\Models\Banner;
use Torgash\Banners\Models\Slider;
use Torgash\Banners\Models\Template;

/**
 * The template table, read once per request.
 *
 * Every slider asked for its frame and its banners' templates on its own, so
 * a home page of three sliders read the same few rows six times. Templates
 * are the shop's handful of designs; reading them all at once and handing
 * each slider and banner the shared object costs one query a page.
 *
 * A registered singleton forgotten between requests (PerRequest), never a
 * static — a template edited in the admin must reach the next visitor.
 */
class Templates
{
    /** @var Collection<int, Template>|null by id */
    protected ?Collection $all = null;

    public function find(?int $id): ?Template
    {
        if (! $id) {
            return null;
        }

        return ($this->all ??= Template::query()->get()->keyBy('id'))->get($id);
    }

    /** Hang the frame on a slider and the templates on its banners, from the memo. */
    public function attach(Slider $slider): Slider
    {
        $slider->setRelation('frame', $this->find($slider->frame_id));

        if ($slider->relationLoaded('banners')) {
            $slider->banners->each(fn (Banner $banner) => $banner->setRelation('template', $this->find($banner->template_id)));
        }

        return $slider;
    }
}
