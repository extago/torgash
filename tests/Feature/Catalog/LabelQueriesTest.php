<?php

namespace Tests\Feature\Catalog;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Labels;

/**
 * Labels with SQL rules are asked about a grid in ONE query between them, and
 * a product already asked about is not asked again — the home page's eight
 * grids and four such labels used to cost thirty-two queries.
 */
class LabelQueriesTest extends TestCase
{
    public function test_every_sql_label_is_asked_in_one_query_and_only_once_per_product(): void
    {
        $cheap = Label::create(['name' => ['en' => 'Cheap'], 'name_for_admin' => 'LQ cheap', 'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'price', 'operator' => 'lte', 'value' => '50']]]]);
        $dear = Label::create(['name' => ['en' => 'Dear'], 'name_for_admin' => 'LQ dear', 'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'price', 'operator' => 'gte', 'value' => '500']]]]);
        app(Labels::class)->flush();

        $make = fn (string $sku, int $price) => Product::create(['sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku), 'price' => $price, 'stock' => 1, 'status' => 'active']);
        $a = $make('LQ-A', 10_00);
        $b = $make('LQ-B', 100_00);
        $c = $make('LQ-C', 900_00);

        $asked = 0;
        DB::listen(function ($query) use (&$asked) {
            if (str_contains($query->sql, 'union all')) {
                $asked++;
            }
        });

        $labels = Label::forProducts(collect([$a, $b]));
        $this->assertSame(1, $asked, 'both labels in one query');
        $this->assertSame([$cheap->id], array_map(fn (Label $l) => $l->id, $labels[$a->id] ?? []));
        $this->assertArrayNotHasKey($b->id, $labels);

        $labels = Label::forProducts(collect([$b, $c]));
        $this->assertSame(2, $asked, 'only the new product is asked about');
        $this->assertSame([$dear->id], array_map(fn (Label $l) => $l->id, $labels[$c->id] ?? []));

        Label::forProducts(collect([$a, $b, $c]));
        $this->assertSame(2, $asked, 'nothing new, nothing asked');
    }
}
