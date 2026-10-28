<?php

namespace Torgash\Landings;

use App\Core\Display\DisplayRules;
use App\Core\Display\RuleKinds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Landings\Models\Landing;

/**
 * The product a landing is looked at on before it goes live
 * (`?landing_preview=`): one it is FOR, so what staff see is what that
 * product's customer will — not simply the catalogue's first product, which
 * is where it used to open whatever the landing was for.
 *
 * A product picked by hand first; else the first one its conditions about
 * the PRODUCT take (the "what" group: category, label, stock, what the
 * product has) — narrowed in SQL as far as the kinds say how
 * (DisplayRules::narrow()) and asked one by one after that; never one left
 * out. The conditions about the moment and the visitor (dates, weekdays,
 * town, language) say nothing about which product, and the preview shows
 * the landing whatever they say. A landing that takes no product yet is
 * looked at on any, and says so (`for` is false).
 */
class PreviewProduct
{
    /** How many candidates are asked at most, once SQL has narrowed them. */
    public const ASKED = 200;

    /** @return array{product: ?Product, for: bool} */
    public function for(Landing $landing): array
    {
        $links = DB::table('m_landing_products')->where('landing_id', $landing->id)->get(['product_id', 'excluded']);
        $picked = $links->where('excluded', false)->pluck('product_id')->all();
        $excluded = $links->where('excluded', true)->pluck('product_id')->all();

        $product = ($picked !== [] ? $this->candidates()->whereIn('id', $picked)->orderBy('id')->first() : null)
            ?? $this->byConditions($landing, $excluded, $picked !== []);

        return $product !== null
            ? ['product' => $product, 'for' => true]
            : ['product' => $this->candidates()->whereNotIn('id', $excluded)->orderBy('id')->first() ?? $this->candidates()->orderBy('id')->first(), 'for' => false];
    }

    protected function byConditions(Landing $landing, array $excluded, bool $anyPicked): ?Product
    {
        $rules = array_values(array_filter((array) ($landing->rules ?? []), 'is_array'));
        $match = $landing->rules_match ?? DisplayRules::MATCH_ALL;

        // No conditions: every product — unless some are picked, and then
        // only those (Landing::appliesTo()), none of which could be shown.
        if ($rules === []) {
            return $anyPicked ? null : $this->candidates()->whereNotIn('id', $excluded)->orderBy('id')->first();
        }

        // A condition whose module is gone hides the landing everywhere.
        if ($match !== DisplayRules::MATCH_ANY && array_filter($rules, fn (array $rule) => ! RuleKinds::has((string) ($rule['kind'] ?? ''))) !== []) {
            return null;
        }

        $about = array_values(array_filter($rules, fn (array $rule) => (RuleKinds::find($rule['kind'] ?? null)['group'] ?? null) === 'what'));
        $rulesEngine = app(DisplayRules::class);
        $query = $rulesEngine->narrow($this->candidates()->whereNotIn('id', $excluded), $about, $match);

        // The catalogue's own kinds read these two; asked of a chunk at once.
        return $query->with(['categories', 'labels'])->lazyById(50)->take(self::ASKED)
            ->first(fn (Product $product) => $rulesEngine->passes($about, ['product' => $product], $match));
    }

    /** What has a page to look at: the product page opens active and hidden products alike. */
    protected function candidates(): Builder
    {
        return Product::query()->reachable();
    }
}
