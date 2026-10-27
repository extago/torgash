<?php

namespace Tests\Feature\Catalog;

use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Variations;

/**
 * What a variation group is switched by, and who falls out of it
 * (Support\Variations): only GLOBAL characteristics marked as variation axes,
 * only those that differ, and of two products with the same answers the older
 * one alone is offered.
 */
class VariationsTest extends TestCase
{
    use MakesVariationAxes;

    protected function product(string $sku, array $extra = [], ?string $created = null): Product
    {
        $product = Product::create($extra + [
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku), 'price' => 100_00,
            'stock' => 5, 'status' => 'active', 'variation_group' => 'vt-phone',
        ]);

        if ($created) {
            $product->forceFill(['created_at' => $created])->saveQuietly();
        }

        return $product;
    }

    public function test_only_marked_characteristics_that_differ_are_axes(): void
    {
        [$color, $colors] = $this->variationAxis('VT Colour', ['black', 'white']);
        [, $memory] = $this->variationAxis('VT Memory', ['128', '256']);
        // Differs across the group, but the shop never said it may switch one.
        [, $material] = $this->variationAxis('VT Material', ['glass', 'metal'], 'filter');

        $a = $this->product('VT-A', ['features' => ['finish' => 'matte']]);
        $b = $this->product('VT-B', ['features' => ['finish' => 'gloss']]);

        $this->answer($a, $colors['black']);
        $this->answer($b, $colors['white']);
        // Both the same memory: marked, but it switches nothing here.
        $this->answer($a, $memory['128']);
        $this->answer($b, $memory['128']);
        $this->answer($a, $material['glass']);
        $this->answer($b, $material['metal']);

        $axes = app(Variations::class)->for($a)['axes'];

        $this->assertSame([$color->id], array_map(fn ($axis) => $axis['feature']->id, $axes), 'not memory (same), not material (not marked), not the free-form finish');
        $this->assertSame(['black', 'white'], array_column($axes[0]['options'], 'label'));
        $this->assertTrue($axes[0]['options'][0]['active']);
        $this->assertSame($b->id, $axes[0]['options'][1]['product']->id);
    }

    public function test_the_same_answers_as_an_older_product_leave_the_newer_one_out(): void
    {
        [, $colors] = $this->variationAxis('VT2 Colour', ['black', 'white']);

        $older = $this->product('VT2-OLD', created: '2026-01-01 10:00:00');
        $newer = $this->product('VT2-NEW', created: '2026-03-01 10:00:00');
        $white = $this->product('VT2-WHITE', created: '2026-02-01 10:00:00');

        $this->answer($older, $colors['black']);
        $this->answer($newer, $colors['black']);
        $this->answer($white, $colors['white']);

        $result = app(Variations::class)->for($white);

        $this->assertSame([$newer->id => $older->id], $result['duplicates']);
        $this->assertFalse($result['offered']->contains('id', $newer->id));
        $black = collect($result['axes'][0]['options'])->firstWhere('label', 'black');
        $this->assertSame($older->id, $black['product']->id, 'the picker leads to the one that stays');
    }

    /** Switching one axis keeps the others: black 256 → white lands on white 256. */
    public function test_switching_one_axis_keeps_the_others(): void
    {
        [, $colors] = $this->variationAxis('VT3 Colour', ['black', 'white']);
        [, $memory] = $this->variationAxis('VT3 Memory', ['128', '256']);

        $products = [];
        foreach (['black' => ['128', '256'], 'white' => ['128', '256']] as $color => $sizes) {
            foreach ($sizes as $size) {
                $products[$color.$size] = $p = $this->product('VT3-'.$color.$size);
                $this->answer($p, $colors[$color]);
                $this->answer($p, $memory[$size]);
            }
        }

        $axes = app(Variations::class)->for($products['black256'])['axes'];
        $whiteOption = collect($axes[0]['options'])->firstWhere('label', 'white');

        $this->assertSame($products['white256']->id, $whiteOption['product']->id);
    }

    public function test_the_storefront_draws_the_characteristics_own_names(): void
    {
        [, $colors] = $this->variationAxis('VT4 Colour', ['black', 'white']);
        $a = $this->product('VT4-A');
        $b = $this->product('VT4-B');
        $this->answer($a, $colors['black']);
        $this->answer($b, $colors['white']);

        $this->get('http://localhost/product/vt4-a')->assertOk()
            ->assertSee('VT4 Colour')
            ->assertSee('href="'.route('catalog.product', 'vt4-b').'"', false);
    }
}
