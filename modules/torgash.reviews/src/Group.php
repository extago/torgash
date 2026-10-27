<?php

namespace Torgash\Reviews;

use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Variations;

/**
 * Which products' reviews are read as ONE product's — the answer every
 * reader of reviews asks first.
 *
 * A review is still written about the product it was written on, and stays
 * on that row for good: `product_id` is what the person bought and what
 * "verified purchase" was worked out against. What the shop decides
 * (`share_variations`, the usual "split reviews for variations" setting
 * turned the other way round) is only how they are READ: with it on, a
 * variation group — the shared `variation_group` word — is one model, and its
 * page, its card's stars, its "best rated" place and its structured data all
 * read every member's reviews. A phone in black and the same phone in white
 * are one phone to anybody reading about it.
 *
 * Switching it is therefore free in both directions: nothing is moved, only
 * the aggregate rows are recounted.
 */
class Group
{
    /** @var array<int, list<int>> */
    protected array $memo = [];

    public static function shared(): bool
    {
        // Read without a storefront: the aggregate row it shapes is one per
        // product for every storefront.
        return (bool) setting('torgash.reviews', 'share_variations', true);
    }

    /**
     * The products whose reviews are this product's — itself alone, or every
     * member of its variation group (hidden, disabled and binned ones too: a
     * review of the white one is not withdrawn because the white one sold out).
     *
     * @return list<int>
     */
    public function ids(int $productId): array
    {
        if (! static::shared()) {
            return [$productId];
        }

        return $this->memo[$productId] ??= $this->members($productId);
    }

    /** @return list<int> */
    protected function members(int $productId): array
    {
        $group = Product::withTrashed()->whereKey($productId)->value('variation_group');

        if ($group === null || trim((string) $group) === '') {
            return [$productId];
        }

        // The bin included: a colour taken off sale is still the model, and
        // what its buyers said about it still stands.
        $ids = Product::withTrashed()->where('variation_group', $group)->orderBy('id')->pluck('id')->map('intval')->all();

        // The memo is filled for the whole group at once: a listing asks for
        // each member in turn.
        foreach ($ids as $id) {
            $this->memo[$id] = $ids;
        }

        return $ids ?: [$productId];
    }

    /**
     * What each member is called inside its group — "Black, 128 GB" — for the
     * line over a review written about ANOTHER member than the page's: the
     * reader is looking at the white one and must not take a remark about the
     * black one's colour as being about this.
     *
     * The answers on the group's AXES only (the characteristics the shop
     * marked and which differ inside it), from catalog's one answer to "what
     * is this group", so the line says what the picker says.
     *
     * @return array<int, string> product id => its answers, members only
     */
    public function labels(Product $product): array
    {
        if (! static::shared() || count($this->ids((int) $product->id)) < 2) {
            return [];
        }

        $variations = app(Variations::class)->for($product, everyStatus: true);
        $axes = array_map(fn (array $axis) => (int) $axis['feature']->id, $variations['axes']);
        $out = [];

        foreach ($variations['values'] as $memberId => $answers) {
            $parts = [];

            foreach ($axes as $featureId) {
                if (isset($answers[$featureId]['label']) && $answers[$featureId]['label'] !== '') {
                    $parts[] = $answers[$featureId]['label'];
                }
            }

            if ($parts !== []) {
                $out[(int) $memberId] = implode(', ', $parts);
            }
        }

        return $out;
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
