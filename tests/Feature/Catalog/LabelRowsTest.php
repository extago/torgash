<?php

namespace Tests\Feature\Catalog;

use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Labels;

/**
 * Labels off the photo — a row over the short description and one under the
 * buy button — and `[feature:code]` in a label's words, which is what turns a
 * label into a pictogram ("RAM 8 GB"); a product without that answer gets no
 * label at all, and a card can be told "not here".
 */
class LabelRowsTest extends TestCase
{
    use MakesVariationAxes;

    public function test_a_label_prints_a_characteristic_in_its_row_and_skips_products_without_it(): void
    {
        [$ram, $answers] = $this->variationAxis('RAM', ['8 GB'], 'plain');
        $ram->update(['code' => 'LR_RAM']);

        $phone = Product::create(['sku' => 'LR-1', 'name' => ['en' => 'Phone'], 'slug' => 'lr-phone', 'price' => 100_00, 'stock' => 3, 'status' => 'active']);
        $jacket = Product::create(['sku' => 'LR-2', 'name' => ['en' => 'Jacket'], 'slug' => 'lr-jacket', 'price' => 100_00, 'stock' => 3, 'status' => 'active']);
        $this->answer($phone, $answers['8 GB']);

        $label = Label::create([
            'name' => ['en' => 'RAM [feature:lr_ram]'], 'name_for_admin' => 'LR pictogram', 'enabled' => true, 'color' => 'neutral',
            'position_list' => 'hidden', 'position_detail' => 'under-buy',
        ]);
        $phone->labels()->sync([$label->id]);
        $jacket->labels()->sync([$label->id]);
        app(Labels::class)->flush();

        $labels = Label::forProducts(collect([$phone->fresh(), $jacket->fresh()]));
        $this->assertCount(1, $labels[$phone->id] ?? []);
        $this->assertArrayNotHasKey($jacket->id, $labels, 'no RAM, no "RAM " label');
        $this->assertSame('RAM', $label->plainName(), 'the token never leaks into a heading');

        $html = $this->get('http://localhost/product/lr-phone')->assertOk()->getContent();
        $this->assertStringContainsString('RAM 8 GB', $html);
        $this->assertSame(1, substr_count($html, 'torgash-label-row'), 'one row, under the buy button');

        $this->assertStringNotContainsString('RAM 8 GB', $this->get('http://localhost/catalog')->assertOk()->getContent(), 'hidden on cards');

        $label->update(['position_detail' => 'above-description']);
        app(Labels::class)->flush();
        $this->assertStringContainsString('RAM 8 GB', $this->get('http://localhost/product/lr-phone')->getContent());
    }
}
