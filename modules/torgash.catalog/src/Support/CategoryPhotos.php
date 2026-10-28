<?php

namespace Torgash\Catalog\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Stock\AvailabilityStatuses;

/**
 * A picture for a category that has none of its own: the photo of the most
 * popular product in it (its whole subtree) that the visitor can order right
 * now — a phone's tile shows a phone, and the picture follows what sells
 * without anybody uploading one.
 *
 * The visitor's products (`Product::listed()`), orderable in the sense of the
 * "In stock only" box (a preorder counts, a discontinued product with stock
 * left does not), and with a photo: a product without one cannot lend it, so
 * the next most popular does. One query for all the categories asked about,
 * one more for those products' photos.
 */
class CategoryPhotos
{
    /**
     * @param  iterable<Category|int>  $categories
     * @param  int  $width  the width the picture is drawn at, for the thumbnail to fetch
     * @return array<int, string>  category id => picture address; a category nothing lends one to is absent
     */
    public function of(iterable $categories, int $width): array
    {
        [$owners, $members] = CategoryCounts::subtrees($categories);

        if ($owners === []) {
            return [];
        }

        $withPhoto = Product::listed()->where(function (Builder $query) {
            $query->whereHas('media', fn (Builder $media) => $media->where('collection_name', 'gallery'));

            // A picture on another site counts only while the shop shows them.
            if (setting('torgash.catalog', 'external_images', false)) {
                $query->orWhere(fn (Builder $url) => $url->whereNotNull('image_url')->where('image_url', '<>', ''));
            }
        });
        $candidates = app(AvailabilityStatuses::class)->applyOrderable($withPhoto)
            ->select('m_catalog_products.id', 'm_catalog_products.popularity')
            ->toBase();

        $picked = DB::query()
            ->fromRaw('unnest(?::bigint[], ?::bigint[]) as tree(owner, member)', [
                '{'.implode(',', $owners).'}',
                '{'.implode(',', $members).'}',
            ])
            ->join('m_catalog_category_product as cp', 'cp.category_id', '=', 'tree.member')
            ->joinSub($candidates, 'p', 'p.id', '=', 'cp.product_id')
            ->selectRaw('distinct on (tree.owner) tree.owner as owner, p.id as id')
            ->orderBy('tree.owner')
            ->orderByDesc('p.popularity')
            ->orderBy('p.id')
            ->pluck('id', 'owner');

        if ($picked->isEmpty()) {
            return [];
        }

        $products = Product::query()->with('media')->whereIn('id', $picked->unique()->values())->get()->keyBy('id');
        $photos = [];

        foreach ($picked as $owner => $id) {
            if ($url = $products->get((int) $id)?->primaryImageUrl($width)) {
                $photos[(int) $owner] = $url;
            }
        }

        return $photos;
    }
}
