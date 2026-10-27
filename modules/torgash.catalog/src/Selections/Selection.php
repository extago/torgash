<?php

namespace Torgash\Catalog\Selections;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Sorting\Sorts;

/**
 * A product block's settings, turned into products — the one place that reads
 * them, so the block's view, its tab label and a test all get the same answer.
 *
 * The settings: `source` (see Sources), `sort` ('' = the source's own order,
 * else a key of the Sorts registry), `limit`, and `products` (the typed SKUs
 * the hand-picked source reads).
 *
 * `$context` is what the zone the block stands in was rendered with — on the
 * product page, `product`. A source that answers "for THIS product" (similar
 * products) reads it, and narrows to nothing where there is none.
 */
class Selection
{
    public const MAX_LIMIT = 48;

    public function __construct(protected Sources $sources, protected Sorts $sorts) {}

    /** @return Collection<int, Product> */
    public function products(array $settings, array $context = []): Collection
    {
        $resolved = $this->sources->resolve((string) ($settings['source'] ?? ''));

        if (! $resolved) {
            return new Collection;
        }

        [$source, $arg] = $resolved;

        $query = ($source['apply'])(Product::listed(), $arg, $settings, $context);
        $sort = (string) ($settings['sort'] ?? '');

        $query = $sort === '' && is_callable($source['order'])
            // Still closed with `id`: the source's own order may tie.
            ? ($source['order'])($query, $arg, $settings, $context)->orderBy('m_catalog_products.id', 'desc')
            : $this->sorts->applyKey($query, $sort === '' ? $this->sorts->default() : $sort);

        return $query->withCardData()->limit($this->limit($settings))->get();
    }

    /** The block's own heading, else what its source is called. */
    public function heading(array $settings): string
    {
        $own = trim((string) ($settings['heading'] ?? ''));

        if ($own !== '') {
            return $own;
        }

        $resolved = $this->sources->resolve((string) ($settings['source'] ?? ''));

        return $resolved && is_callable($resolved[0]['heading'])
            ? trim((string) ($resolved[0]['heading'])($resolved[1]))
            : '';
    }

    /** The page where the whole set lives, when the source has one. */
    public function url(array $settings): ?string
    {
        $resolved = $this->sources->resolve((string) ($settings['source'] ?? ''));

        return $resolved && is_callable($resolved[0]['url'])
            ? (($resolved[0]['url'])($resolved[1]) ?: null)
            : null;
    }

    public function limit(array $settings): int
    {
        return max(1, min(self::MAX_LIMIT, (int) ($settings['limit'] ?? 8)));
    }

    /**
     * The typed SKUs, in order, each once. Commas as well as lines: a list
     * copied out of a spreadsheet row arrives that way.
     *
     * @return list<string>
     */
    public static function skus(array $settings): array
    {
        $parts = preg_split('/[\r\n,;]+/', (string) ($settings['products'] ?? '')) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($sku) => $sku !== '')));
    }
}
