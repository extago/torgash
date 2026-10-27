<?php

namespace Tests\Feature\Promotions;

use App\Models\CustomerGroup;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Models\Promotion;

/**
 * The admin screen saves a promotion's whole ordered tier list at once —
 * same idiom as a shipping rate's rows, no per-row id to reconcile.
 */
class PromotionAdminTest extends TestCase
{
    public function test_creating_a_promotion_saves_its_ordered_tiers(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $category = Category::create(['name' => ['en' => 'Admin cat'], 'slug' => 'promo-admin-cat']);

        $this->actingAs($admin)->post('http://localhost/admin/promotions', [
            'name' => 'Admin-made sale',
            'zone' => 'catalog',
            'combine' => 'replace',
            'priority' => 5,
            'enabled' => '1',
            'tiers' => [
                [
                    'match' => 'all',
                    'discount_type' => 'percent',
                    'discount_value' => '50',
                    'rules' => [
                        ['kind' => 'category', 'operator' => 'cat_in', 'ids' => [(string) $category->id]],
                    ],
                ],
                [
                    'match' => 'all',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.00',
                    'rules' => [],
                ],
            ],
        ])->assertRedirect();

        $promotion = Promotion::with('tiers')->latest('id')->firstOrFail();

        $this->assertSame('Admin-made sale', $promotion->t('name', 'en'));
        $this->assertSame(5, $promotion->priority);
        $this->assertCount(2, $promotion->tiers);

        $first = $promotion->tiers[0];
        $this->assertSame(0, $first->position);
        $this->assertSame('percent', $first->discount_type);
        $this->assertSame(50, $first->discount_value);
        $this->assertSame([$category->id], $first->rules()[0]['ids']);

        $second = $promotion->tiers[1];
        $this->assertSame('fixed', $second->discount_type);
        $this->assertSame(500, $second->discount_value, 'entered in major units, stored in minor');
        $this->assertSame([], $second->rules());
    }

    public function test_the_screen_is_staff_only(): void
    {
        $this->get('http://localhost/admin/promotions')->assertRedirect();
    }

    /**
     * Reordering is editing: the order decides which tier wins, so the sitewide
     * fallback moving above the narrow discount changes what every customer
     * pays. The screen reorders the array and resubmits it; `position` is
     * written from the index, which is what makes that the whole of it.
     */
    public function test_a_resubmitted_order_of_tiers_is_the_saved_order(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $promotion = Promotion::create(['name' => ['en' => 'Reorder me'], 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create(['position' => 0, 'conditions' => ['match' => 'all', 'rules' => []], 'discount_type' => 'percent', 'discount_value' => 10]);
        $promotion->tiers()->create(['position' => 1, 'conditions' => ['match' => 'all', 'rules' => []], 'discount_type' => 'percent', 'discount_value' => 40]);

        $this->actingAs($admin)->put('http://localhost/admin/promotions/'.$promotion->id, [
            'name' => 'Reorder me',
            'zone' => 'catalog',
            'combine' => 'replace',
            'priority' => 0,
            'enabled' => '1',
            'tiers' => [
                ['match' => 'all', 'discount_type' => 'percent', 'discount_value' => '40', 'rules' => []],
                ['match' => 'all', 'discount_type' => 'percent', 'discount_value' => '10', 'rules' => []],
            ],
        ])->assertRedirect();

        $tiers = $promotion->fresh()->tiers;

        $this->assertSame(40, $tiers[0]->discount_value);
        $this->assertSame(0, $tiers[0]->position);
        $this->assertSame(10, $tiers[1]->discount_value);
        $this->assertSame(1, $tiers[1]->position);
    }

    public function test_a_cart_promotion_saves_usage_limits_scope_and_side_effect_tiers(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $category = Category::create(['name' => ['en' => 'Scoped cat'], 'slug' => 'promo-scoped-cat']);
        $group = CustomerGroup::create(['name' => ['en' => 'Admin VIP']]);
        $gift = Product::create([
            'sku' => 'ADMIN-GIFT', 'name' => ['en' => 'Admin gift'], 'slug' => 'admin-gift',
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);

        $this->actingAs($admin)->post('http://localhost/admin/promotions', [
            'name' => 'Admin cart sale',
            'zone' => 'cart',
            'combine' => 'replace',
            'priority' => 0,
            'enabled' => '1',
            'usage_limit' => '5',
            'once_per_customer' => '1',
            'tiers' => [
                [
                    'match' => 'all', 'discount_type' => 'percent', 'discount_value' => '15',
                    'discount_scope_type' => 'category', 'discount_scope_ids' => [(string) $category->id],
                    'rules' => [],
                ],
                [
                    'match' => 'all', 'discount_type' => 'give_group', 'give_group_id' => (string) $group->id,
                    'rules' => [],
                ],
                [
                    'match' => 'all', 'discount_type' => 'free_product',
                    'free_product_id' => (string) $gift->id, 'free_qty' => '3',
                    'rules' => [],
                ],
            ],
        ])->assertRedirect();

        $promotion = Promotion::with('tiers')->latest('id')->firstOrFail();

        $this->assertSame(5, $promotion->usage_limit);
        $this->assertTrue($promotion->once_per_customer);

        // jsonb does not keep key order (sorts by length, then bytewise —
        // see CLAUDE.md), so compare fields rather than the whole array.
        $scoped = $promotion->tiers[0];
        $this->assertSame('category', $scoped->scope()['type']);
        $this->assertSame([$category->id], $scoped->scope()['ids']);

        $giveGroup = $promotion->tiers[1];
        $this->assertSame('give_group', $giveGroup->discount_type);
        $this->assertSame($group->id, $giveGroup->give_group_id);

        $freeProduct = $promotion->tiers[2];
        $this->assertSame('free_product', $freeProduct->discount_type);
        $this->assertSame($gift->id, $freeProduct->free_product_id);
        $this->assertSame(3, $freeProduct->free_qty);
    }
}
