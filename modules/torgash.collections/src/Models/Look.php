<?php

namespace Torgash\Collections\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Collection as Rows;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Torgash\Catalog\Models\Product;

/**
 * A look: one picture of a collection with points on the products in it.
 * A point is {x, y, product_id} — x and y in percent, so the points stay
 * where they belong at any width of the picture.
 */
class Look extends Model
{
    use HasTranslations;

    protected $table = 'm_collection_looks';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['caption' => 'array', 'points' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Look $look) => Storage::disk('public')->delete($look->image));
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function imageUrl(): string
    {
        return Storage::disk('public')->url($this->image);
    }

    /**
     * The points with their products, a product the storefront may not show
     * (hidden, gone) dropped — for a set of looks in ONE query with the cards' data.
     *
     * @param  iterable<Look>  $looks
     * @return array<int, list<array{x: float, y: float, product: Product}>> look id => points
     */
    public static function pointsFor(iterable $looks): array
    {
        $looks = collect($looks);
        $ids = $looks->flatMap(fn (Look $l) => array_column((array) $l->points, 'product_id'))->map(fn ($id) => (int) $id)->unique()->values()->all();
        /** @var Rows<int, Product> $products */
        $products = $ids === [] ? new Rows : Product::listed()->withCardData()->whereIn('m_catalog_products.id', $ids)->get()->keyBy('id');

        $out = [];
        foreach ($looks as $look) {
            $out[$look->id] = [];
            foreach ((array) $look->points as $point) {
                if ($product = $products->get((int) ($point['product_id'] ?? 0))) {
                    $out[$look->id][] = [
                        'x' => max(0, min(100, (float) ($point['x'] ?? 0))),
                        'y' => max(0, min(100, (float) ($point['y'] ?? 0))),
                        'product' => $product,
                    ];
                }
            }
        }

        return $out;
    }
}
