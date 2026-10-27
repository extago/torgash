<?php

namespace Torgash\Landings;

use App\Core\Display\DisplayRules;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Landings\Models\Landing;

/**
 * The product form's "Landings" tab: every landing, whether THIS product
 * shows it and why, and the shop's answer for this one product —
 * `auto` (as the landing's own conditions say), `picked` (here too), or
 * `excluded` (never here).
 */
class ProductTab
{
    public const ANSWERS = ['auto', 'picked', 'excluded'];

    /** @return array{product: Product, rows: list<array<string, mixed>>} */
    public static function data(Product $product): array
    {
        $links = DB::table('m_landing_products')->where('product_id', $product->id)->pluck('excluded', 'landing_id')
            ->map(fn ($excluded) => (bool) $excluded)->all();
        $picking = DB::table('m_landing_products')->where('excluded', false)->distinct()->pluck('landing_id')->flip()->all();
        $context = app(DisplayRules::class)->context(['product' => $product]);
        $rows = [];

        foreach (Landing::query()->orderBy('placement')->orderBy('sort')->orderBy('id')->get() as $landing) {
            $excluded = $links[$landing->id] ?? null;
            $applies = $landing->appliesTo($excluded, isset($picking[$landing->id]), $context);

            $rows[] = [
                'landing' => $landing,
                'answer' => match ($excluded) { null => 'auto', false => 'picked', true => 'excluded' },
                'applies' => $applies,
                'shown' => $applies && $landing->isRunning(),
                'why' => match (true) {
                    $excluded === true => __('left out here'),
                    $excluded === false => __('picked for this product'),
                    ($landing->rules ?? []) === [] && isset($picking[$landing->id]) => __('only for the products picked'),
                    ($landing->rules ?? []) === [] => __('every product'),
                    $applies => __('its conditions match'),
                    default => __('its conditions do not match'),
                },
            ];
        }

        return ['product' => $product, 'rows' => $rows];
    }

    /** @param  array<int|string, string>  $answers  landing id => auto|picked|excluded */
    public static function save(Product $product, array $answers): void
    {
        $known = Landing::query()->pluck('id')->flip()->all();

        foreach ($answers as $landingId => $answer) {
            $landingId = (int) $landingId;

            if (! isset($known[$landingId]) || ! in_array($answer, self::ANSWERS, true)) {
                continue;
            }

            $answer === 'auto'
                ? DB::table('m_landing_products')->where(['landing_id' => $landingId, 'product_id' => $product->id])->delete()
                : DB::table('m_landing_products')->updateOrInsert(
                    ['landing_id' => $landingId, 'product_id' => $product->id],
                    ['excluded' => $answer === 'excluded'],
                );
        }
    }
}
