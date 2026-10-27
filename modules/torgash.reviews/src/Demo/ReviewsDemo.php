<?php

namespace Torgash\Reviews\Demo;

use App\Core\Demo\DemoPack;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Reviews\Models\Criterion;
use Torgash\Reviews\Models\Review;
use Torgash\Reviews\Moderate;

/**
 * Its part of the demo shop (PLAN §55.3): two or three published reviews on
 * the products a visitor is most likely to open, scored by the shop's
 * criteria plus three of the demo's own (attached to categories), a few
 * with the shop's reply — so a card has its stars, a product page its
 * spread and averages, and "best rated first" has something to sort.
 *
 * Read from `database/demo/reviews/*.json`. A demo review is written the way
 * an IMPORTED one is: a name and no account, never `verified` (that is the
 * shop's own finding about its own orders). The text is in the shop's
 * content language when the pack has it, English otherwise; dates go back
 * `days_ago`. Publishing goes through Moderate, which recounts the average.
 */
class ReviewsDemo
{
    public function install(DemoPack $pack): void
    {
        $locale = array_key_exists(content_base_locale(), ['en' => 1, 'ru' => 1]) ? content_base_locale() : 'en';
        $criteria = $this->criteria($pack);
        $made = 0;

        foreach ($pack->json('reviews/reviews') as $row) {
            $product = Product::where('sku', (string) ($row['product'] ?? ''))->first();

            if (! $product) {
                continue;
            }

            foreach ((array) ($row['reviews'] ?? []) as $item) {
                $at = now()->subDays((int) ($item['days_ago'] ?? 1))->setTime(random_int(9, 22), random_int(0, 59));
                $words = fn (string $key) => isset($item[$key]) ? ($item[$key][$locale] ?? $item[$key]['en'] ?? null) : null;

                $review = Review::create([
                    'product_id' => $product->id,
                    'author_name' => (string) $item['author'],
                    'body' => (string) $words('text'),
                    'pros' => $words('pros'),
                    'cons' => $words('cons'),
                    'rating' => (int) $item['rating'],
                    'recommend' => in_array($item['recommend'] ?? null, Review::RECOMMEND, true) ? $item['recommend'] : null,
                    'status' => Review::PENDING,
                    'locale' => $locale,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);

                foreach ((array) ($item['scores'] ?? []) as $code => $value) {
                    if (isset($criteria[$code])) {
                        $review->ratings()->create(['criterion_id' => $criteria[$code], 'value' => (int) $value]);
                    }
                }

                app(Moderate::class)->decide($review, Review::PUBLISHED);

                if ($reply = $words('reply')) {
                    $answered = $at->copy()->addHours(random_int(2, 20))->min(now());
                    Review::create([
                        'product_id' => $product->id,
                        'parent_id' => $review->id,
                        'author_name' => __('Shop'),
                        'body' => $reply,
                        'status' => Review::PUBLISHED,
                        'is_staff' => true,
                        'locale' => $locale,
                        'created_at' => $answered,
                        'updated_at' => $answered,
                    ]);
                }

                $made++;
            }
        }

        $pack->say($made.' reviews.');
    }

    /**
     * The demo's own criteria, attached to their categories, and the shop's
     * existing ones — every code a review may score, to its id.
     *
     * @return array<string, int>
     */
    protected function criteria(DemoPack $pack): array
    {
        foreach ($pack->json('reviews/criteria') as $sort => $row) {
            if (Criterion::where('code', $row['code'])->exists()) {
                continue;
            }

            $criterion = Criterion::create(['code' => $row['code'], 'name' => $row['name'], 'sort' => 10 + $sort, 'enabled' => true]);

            foreach (Category::whereIn('slug', (array) ($row['categories'] ?? []))->pluck('id') as $categoryId) {
                $criterion->categories()->create(['category_id' => $categoryId]);
            }
        }

        return Criterion::query()->where('enabled', true)->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    }
}
