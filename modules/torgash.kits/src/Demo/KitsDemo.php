<?php

namespace Torgash\Kits\Demo;

use App\Core\Demo\DemoPack;
use Torgash\Catalog\Demo\CatalogDemo;
use Torgash\Catalog\Models\Product;
use Torgash\Kits\Models\Kit;

/**
 * Its part of the demo shop (PLAN §55.3): sets on the products people buy
 * accessories with — extended ones with a discount on the parts (a phone's
 * case and charger, a console's second controller) and simple ones that are
 * only "bought with it" (a camera's memory card).
 *
 * Read from `database/demo/kits/kits.json`: products by SKU, a fixed discount
 * in US dollars converted as the catalogue's prices are. A kit whose main
 * product the pack did not make is skipped, and so is a part.
 */
class KitsDemo
{
    public function install(DemoPack $pack): void
    {
        $money = app(CatalogDemo::class);
        $made = 0;

        foreach ($pack->json('kits/kits') as $sort => $row) {
            $main = Product::where('sku', (string) ($row['main'] ?? ''))->first();

            if (! $main) {
                continue;
            }

            $type = in_array($row['type'] ?? null, Kit::TYPES, true) ? $row['type'] : 'simple';

            $kit = Kit::create([
                'code' => $row['code'] ?? null,
                'admin_name' => $row['admin_name'] ?? null,
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'main_product_id' => $main->id,
                'type' => $type,
                'placement' => 'after_buy_box',
                'sort' => $sort,
                'enabled' => true,
            ]);

            foreach ((array) ($row['slots'] ?? []) as $slotSort => $slot) {
                $part = Product::where('sku', (string) ($slot['product'] ?? ''))->first();

                if (! $part) {
                    continue;
                }

                // Only an extended kit may give money away (Kit::isExtended()).
                $discount = $type === 'extended' ? ($slot['discount'] ?? 'none') : 'none';

                $kit->slots()->create([
                    'kind' => 'product',
                    'product_id' => $part->id,
                    'qty' => (int) ($slot['qty'] ?? 1),
                    'discount_type' => $discount,
                    'discount_percent' => $discount === 'percent' ? (float) ($slot['percent'] ?? 0) : null,
                    'discount_amount' => in_array($discount, ['fixed', 'to_price'], true) ? $money->minor($pack, (float) ($slot['amount'] ?? 0)) : 0,
                    'sort' => $slotSort,
                    'enabled' => true,
                ]);
            }

            $made++;
        }

        $pack->say($made.' kits.');
    }
}
