<?php

namespace Torgash\Landings;

use App\Core\Display\DisplayRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Landings\Models\Landing;

/**
 * Which landings a product's page shows, by placement.
 *
 * The table is small and every product page asks, so the enabled landings
 * are read once per request (a registered singleton, forgotten between
 * requests — PerRequest), and a product costs one query: its own rows in
 * `m_landing_products`.
 *
 * A member of staff who may edit landings can look at one on any product
 * before it is switched on: `?landing_preview=<id>` puts it on the page
 * whatever its switch, dates, rules or picked products say.
 */
class Landings
{
    /** @var Collection<int, Landing>|null */
    protected ?Collection $enabled = null;

    /** @var array<int, true>|null landing id => it has products picked by hand */
    protected ?array $picking = null;

    /** @var array<int, array<string, list<Landing>>> product id => placement => landings */
    protected array $for = [];

    /** @return array<string, list<Landing>> placement => landings, in their order */
    public function for(Product $product): array
    {
        return $this->for[$product->id] ??= $this->work($product);
    }

    /** @return list<Landing> */
    public function at(Product $product, string $placement): array
    {
        return $this->for($product)[$placement] ?? [];
    }

    /** @return array<string, list<Landing>> */
    protected function work(Product $product): array
    {
        $rows = DB::table('m_landing_products')->where('product_id', $product->id)->pluck('excluded', 'landing_id')
            ->map(fn ($excluded) => (bool) $excluded)->all();
        $context = app(DisplayRules::class)->context(['product' => $product]);
        $preview = $this->previewed();

        $shown = $this->enabled()
            ->filter(fn (Landing $landing) => $landing->isRunning()
                && $landing->appliesTo($rows[$landing->id] ?? null, isset($this->picking()[$landing->id]), $context))
            ->keyBy('id');

        if ($preview !== null) {
            $shown[$preview->id] = $preview;
        }

        $out = [];

        foreach ($shown->sortBy([['sort', 'asc'], ['id', 'asc']]) as $landing) {
            $out[$landing->placement][] = $landing;
        }

        return $out;
    }

    /** @return Collection<int, Landing> */
    protected function enabled(): Collection
    {
        return $this->enabled ??= Landing::query()->where('enabled', true)->get();
    }

    /** @return array<int, true> */
    protected function picking(): array
    {
        return $this->picking ??= DB::table('m_landing_products')->where('excluded', false)->distinct()
            ->pluck('landing_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    protected function previewed(): ?Landing
    {
        $id = (int) request()->query('landing_preview', 0);

        if ($id <= 0 || ! auth()->user()?->can('landings.manage')) {
            return null;
        }

        return Landing::find($id);
    }

    public function flush(): void
    {
        $this->enabled = null;
        $this->picking = null;
        $this->for = [];
    }
}
