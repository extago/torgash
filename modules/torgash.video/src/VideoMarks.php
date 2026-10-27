<?php

namespace Torgash\Video;

use Illuminate\Support\Facades\DB;

/**
 * "Has this product a video?" for a page of cards — answered for the whole
 * page in one query off `catalog.products.listed`, the way the rating is.
 * A card the page did not announce is asked about on its own, once.
 *
 * A per-request memo: registered as a singleton and reset with the request
 * (PerRequest), never a static.
 */
class VideoMarks
{
    /** @var array<int, bool> */
    protected array $known = [];

    /** @param iterable<object> $products */
    public function prime(iterable $products): void
    {
        $ids = [];

        foreach ($products as $product) {
            $id = (int) ($product->id ?? 0);

            if ($id > 0 && ! isset($this->known[$id])) {
                $ids[] = $id;
                $this->known[$id] = false;
            }
        }

        if ($ids === []) {
            return;
        }

        foreach (DB::table('m_video_videos')->where('owner_type', 'product')->whereIn('owner_id', $ids)->distinct()->pluck('owner_id') as $id) {
            $this->known[(int) $id] = true;
        }
    }

    public function has(int $productId): bool
    {
        if (! isset($this->known[$productId])) {
            $this->known[$productId] = DB::table('m_video_videos')->where('owner_type', 'product')->where('owner_id', $productId)->exists();
        }

        return $this->known[$productId];
    }
}
