<?php

namespace Torgash\Checkout\Demo;

use App\Core\Demo\DemoPack;
use Torgash\Catalog\Demo\CatalogDemo;
use Torgash\Checkout\Models\Coupon;

/**
 * Its part of the demo shop (PLAN §55.3): a coupon to try at checkout,
 * `DEMO10`. Read from `database/demo/checkout/coupons.json`; a code the shop
 * already has is left alone, a minimum order in US dollars is converted as
 * the catalogue's prices are.
 */
class CheckoutDemo
{
    public function install(DemoPack $pack): void
    {
        $money = app(CatalogDemo::class);
        $made = 0;

        foreach ($pack->json('checkout/coupons') as $row) {
            $code = strtoupper((string) ($row['code'] ?? ''));

            if ($code === '' || Coupon::whereRaw('upper(code) = ?', [$code])->exists()) {
                continue;
            }

            Coupon::create([
                'code' => $code,
                'type' => $row['type'] ?? 'percent',
                'value' => (int) ($row['value'] ?? 0),
                'min_subtotal' => isset($row['min_subtotal']) ? $money->minor($pack, (float) $row['min_subtotal']) : null,
                'enabled' => true,
            ]);
            $made++;
        }

        $pack->say($made.' coupons.');
    }
}
