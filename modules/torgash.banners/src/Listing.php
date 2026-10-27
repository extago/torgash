<?php

namespace Torgash\Banners;

use App\Core\Display\DisplayRules;
use Torgash\Banners\Models\Banner;
use Torgash\Banners\Models\Slider;

/**
 * Banners between the products of a catalogue listing (PLAN §51, step 4) —
 * the answer to the catalogue's `catalog.listing.inserts`.
 *
 * A slider says "between products" in its settings: after which product,
 * again every so many, and where (its own display rules, asked with the
 * listing's context — "the category being viewed is iPhone"). Its banners
 * take those places one by one, in the slider's order: the first after the
 * sixth product, the second after the twelfth. In a grid each stands as a
 * CARD in a cell of its own; in the list view, as a STRIP — the same banner
 * in its template's other shape, and a banner whose template has no such
 * shape sits that view out rather than breaking the grid.
 *
 * Two queries for the page, however many sliders say "between products".
 */
class Listing
{
    public function __construct(protected Renderer $renderer) {}

    /**
     * @param  array<string, mixed>  $context  category, filter_page, layout, page, count
     * @return array<int, string> html by how many products come before it
     */
    public function inserts(array $context): array
    {
        $count = (int) ($context['count'] ?? 0);
        $shape = ($context['layout'] ?? 'grid') === 'list' ? 'strip' : 'card';
        $rules = app(DisplayRules::class);
        $inserts = [];

        $sliders = Slider::query()
            ->whereRaw("(settings->'listing'->>'on')::boolean is true")
            ->with(['banners' => fn ($query) => $query->running()->with('media')])
            ->orderBy('id')
            ->get()
            ->each(fn (Slider $slider) => app(Templates::class)->attach($slider));

        foreach ($sliders as $slider) {
            $listing = self::settings((array) $slider->settings);

            if (! $rules->passes($listing['rules'], $context, $listing['match'])) {
                continue;
            }

            $banners = $slider->banners
                ->filter(fn (Banner $banner) => $banner->template && $banner->template->draws($shape) && $banner->isVisible($context))
                ->values();

            if ($banners->isEmpty()) {
                continue;
            }

            $turn = 0;

            // After the N-th product, then every M; never after the last one —
            // a banner closing the page looks like the end of the catalogue.
            for ($after = $listing['after']; $after < $count; $after += $listing['every']) {
                // A place another slider took first stays its.
                if (! isset($inserts[$after])) {
                    $inserts[$after] = $this->renderer->banner($banners[$turn % $banners->count()], $shape);
                    $turn++;
                }

                if ($listing['every'] === 0 || ($listing['once'] && $turn >= $banners->count())) {
                    break;
                }
            }
        }

        ksort($inserts);

        return $inserts;
    }

    /**
     * A slider's "between products", made whole.
     *
     * @return array{on: bool, after: int, every: int, once: bool, rules: list<array<string, mixed>>, match: string}
     */
    public static function settings(array $settings): array
    {
        $listing = (array) ($settings['listing'] ?? []);

        return [
            'on' => (bool) ($listing['on'] ?? false),
            'after' => max(1, min(100, (int) ($listing['after'] ?? 6))),
            'every' => max(0, min(100, (int) ($listing['every'] ?? 0))),
            // Each banner once, rather than round and round down a long page.
            'once' => (bool) ($listing['once'] ?? false),
            'rules' => array_values((array) ($listing['rules'] ?? [])),
            'match' => ($listing['match'] ?? DisplayRules::MATCH_ALL) === DisplayRules::MATCH_ANY ? DisplayRules::MATCH_ANY : DisplayRules::MATCH_ALL,
        ];
    }
}
