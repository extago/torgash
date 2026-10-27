<?php

namespace Tests\Feature\Promotions;

use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Models\Promotion;

/**
 * A "Black Friday" scenario — one sale with several discount depths (a narrow
 * brand-in-category cut, a sitewide fallback) as ONE promotion with ordered
 * tiers, plus the cross-promotion combine modes (replace/stack_add/
 * stack_multiply) that decide what happens when a SECOND promotion also
 * matches the same product.
 */
class CatalogPromotionTest extends TestCase
{
    protected Category $electronics;

    protected Feature $brand;

    protected FeatureValue $brandX;

    protected FeatureValue $brandY;

    protected function setUp(): void
    {
        parent::setUp();

        $this->electronics = Category::create(['name' => ['en' => 'Electronics'], 'slug' => 'promo-electronics']);
        $this->brand = Feature::create(['name' => ['en' => 'Brand'], 'code' => 'PROMO-BRAND', 'input_type' => 'select']);
        $this->brandX = $this->brand->values()->create(['value' => ['en' => 'BrandX']]);
        $this->brandY = $this->brand->values()->create(['value' => ['en' => 'BrandY']]);
    }

    /** Tiers are tried top to bottom; the first match wins for a given product. */
    public function test_a_narrow_tier_beats_the_sitewide_fallback_in_the_same_promotion(): void
    {
        $inCategoryBrandX = $this->product('BF-1', 1000_00, $this->electronics, $this->brandX);
        $inCategoryBrandY = $this->product('BF-2', 1000_00, $this->electronics, $this->brandY);
        $elsewhere = $this->product('BF-3', 1000_00, null, null);

        $promotion = Promotion::create(['name' => ['en' => 'Black Friday'], 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [
                ['kind' => 'category', 'operator' => 'cat_in', 'ids' => [$this->electronics->id]],
                ['kind' => 'feature', 'operator' => 'feature_is', 'feature_id' => $this->brand->id, 'value' => $this->brandX->id],
            ]],
            'discount_type' => 'percent', 'discount_value' => 50,
        ]);
        $promotion->tiers()->create([
            'position' => 1,
            'conditions' => null,
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $this->assertSame(500_00, $inCategoryBrandX->fresh()->effectivePrice(), 'the narrow 50% tier should have won');
        $this->assertSame(900_00, $inCategoryBrandY->fresh()->effectivePrice(), 'falls through to the sitewide 10% tier');
        $this->assertSame(900_00, $elsewhere->fresh()->effectivePrice(), 'a promotion with no conditions on its fallback tier matches everything');
    }

    /**
     * The one catalog condition that asks about the visitor rather than the
     * product. "Wholesale gets 30% off the catalogue" is the commonest reason a
     * shop runs a catalog-zone promotion at all, and until now it could only be
     * expressed in the cart — where the customer only finds out at the end.
     */
    public function test_a_catalog_promotion_can_be_for_one_customer_group_only(): void
    {
        $product = $this->product('BF-GROUP', 1000_00, null, null);

        $wholesale = \App\Models\CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 0]);
        $retail = \App\Models\CustomerGroup::create(['name' => ['en' => 'Retail'], 'discount_percent' => 0]);

        $promotion = Promotion::create(['name' => ['en' => 'Wholesale week'], 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [
                ['kind' => 'usergroup', 'operator' => 'group_in', 'ids' => [$wholesale->id]],
            ]],
            'discount_type' => 'percent', 'discount_value' => 30,
        ]);

        $buyer = fn (\App\Models\CustomerGroup $group) => \App\Models\User::create([
            'name' => 'Buyer', 'email' => 'buyer'.$group->id.'@example.test',
            'password' => 'secret', 'customer_group_id' => $group->id,
        ]);

        $this->assertSame(700_00, $product->fresh()->effectivePrice($buyer($wholesale)));
        $this->assertSame(1000_00, $product->fresh()->effectivePrice($buyer($retail)), 'another group pays the list price');
        $this->assertSame(1000_00, $product->fresh()->effectivePrice(), 'a guest belongs to no group and sees no wholesale price');
    }

    public function test_a_disabled_promotion_does_not_apply(): void
    {
        $product = $this->product('BF-4', 1000_00, null, null);
        $this->everythingPromotion(10, enabled: false);

        $this->assertSame(1000_00, $product->fresh()->effectivePrice());
    }

    public function test_replace_mode_promotions_compete_and_cheapest_wins(): void
    {
        $product = $this->product('BF-5', 1000_00, null, null);
        $this->everythingPromotion(10);
        $this->everythingPromotion(30);

        $this->assertSame(700_00, $product->fresh()->effectivePrice());
    }

    /** "10% + 10%" reads as 20% off the original — summed, not compounded. */
    public function test_stack_add_sums_discounts(): void
    {
        $product = $this->product('BF-6', 1000_00, null, null);
        $this->everythingPromotion(10, combine: Promotion::STACK_ADD);
        $this->everythingPromotion(10, combine: Promotion::STACK_ADD);

        $this->assertSame(800_00, $product->fresh()->effectivePrice());
    }

    /** The real "double discount": each applies to what the previous one left. */
    public function test_stack_multiply_compounds(): void
    {
        $product = $this->product('BF-7', 1000_00, null, null);
        $this->everythingPromotion(10); // replace candidate: 900.00
        $this->everythingPromotion(10, combine: Promotion::STACK_MULTIPLY);

        $this->assertSame(810_00, $product->fresh()->effectivePrice());
    }

    protected function everythingPromotion(int $percent, bool $enabled = true, string $combine = Promotion::REPLACE): Promotion
    {
        $promotion = Promotion::create([
            'name' => ['en' => "Promo {$percent}%"], 'zone' => 'catalog', 'combine' => $combine, 'enabled' => $enabled,
        ]);
        $promotion->tiers()->create(['position' => 0, 'conditions' => null, 'discount_type' => 'percent', 'discount_value' => $percent]);

        return $promotion;
    }

    protected function product(string $sku, int $price, ?Category $category, ?FeatureValue $brandValue): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => 5, 'status' => 'active',
        ]);

        if ($category) {
            $product->categories()->attach($category);
        }
        if ($brandValue) {
            $product->featureAssignments()->create(['feature_id' => $this->brand->id, 'feature_value_id' => $brandValue->id]);
        }

        return $product;
    }
}
