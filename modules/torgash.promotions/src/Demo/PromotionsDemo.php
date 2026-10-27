<?php

namespace Torgash\Promotions\Demo;

use App\Core\Demo\DemoPack;
use Torgash\Catalog\Demo\CatalogDemo;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Models\Promotion;
use Torgash\Promotions\Models\PromotionTier;

/**
 * Its part of the demo shop (PLAN §55.3): a deal of the day with its timer,
 * a week-long sale on a category and free delivery for a big cart — each
 * with its page in /sale.
 *
 * Read from `database/demo/promotions/promotions.json`. "Every day" is a
 * schedule window of the whole day, so the deal's timer always counts to
 * midnight and the demo never goes stale; `days` is a sale with an end date
 * that many days from the install. Products by SKU, categories by slug, a
 * subtotal in US dollars converted as the catalogue's prices are.
 */
class PromotionsDemo
{
    public function install(DemoPack $pack): void
    {
        $money = app(CatalogDemo::class);
        $made = 0;

        foreach ($pack->json('promotions/promotions') as $priority => $row) {
            $promotion = Promotion::create([
                'slug' => $row['slug'],
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'zone' => $row['zone'] ?? Promotion::ZONE_CATALOG,
                'combine' => Promotion::REPLACE,
                'priority' => $priority,
                'enabled' => true,
                'published' => true,
                'show_countdown' => true,
                'starts_at' => now()->startOfDay(),
                'ends_at' => isset($row['days']) ? now()->addDays((int) $row['days'])->endOfDay() : null,
                'schedule' => ! empty($row['every_day']) ? ['windows' => [['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '00:00', 'to' => '00:00']]] : null,
                'tint' => $row['tint'] ?? null,
                'badge' => $row['badge'] ?? 'auto',
                'badge_icon' => $row['badge_icon'] ?? null,
            ]);

            foreach ((array) ($row['tiers'] ?? []) as $position => $tier) {
                $rules = [];

                if ($ids = Product::whereIn('sku', (array) ($tier['products'] ?? []))->pluck('id')->all()) {
                    $rules[] = ['kind' => 'product', 'operator' => 'prod_in', 'ids' => $ids];
                }
                if ($ids = Category::whereIn('slug', (array) ($tier['categories'] ?? []))->pluck('id')->all()) {
                    $rules[] = ['kind' => 'category', 'operator' => 'cat_in', 'ids' => $ids];
                }
                if (isset($tier['subtotal'])) {
                    $rules[] = ['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => $money->minor($pack, (float) $tier['subtotal'])];
                }

                $promotion->tiers()->create([
                    'position' => $position,
                    'conditions' => ['match' => 'all', 'rules' => $rules],
                    'discount_type' => $tier['discount'] ?? 'percent',
                    'discount_value' => ($tier['discount'] ?? 'percent') === PromotionTier::FREE_SHIPPING ? null : (int) ($tier['value'] ?? 0),
                ]);
            }

            $made++;
        }

        $pack->say($made.' promotions.');
    }
}
