<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Assortment;
use Torgash\Catalog\Support\CategoryTree;

/**
 * A closed assortment (PLAN §46): a product or a category only some customer
 * groups may see — not listed, not searchable, not in the sitemap and not
 * sold to anybody else; its own address says "sign in" or "not found", as
 * the shop chooses. A closed category closes everything under it.
 */
class ClosedAssortmentTest extends TestCase
{
    protected CustomerGroup $wholesale;

    protected Product $open;

    protected Product $closed;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->wholesale = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 0]);
        $this->open = Product::create(['sku' => 'CA-OPEN', 'name' => ['en' => 'Open kettle'], 'slug' => 'ca-open', 'price' => 10_00, 'stock' => 5, 'status' => 'active']);
        $this->closed = Product::create(['sku' => 'CA-BOX', 'name' => ['en' => 'Kettle box of 50'], 'slug' => 'ca-box', 'price' => 300_00, 'stock' => 5, 'status' => 'active',
            'customer_groups' => ['group:'.$this->wholesale->id]]);
    }

    public function test_an_outsider_does_not_find_it_and_is_asked_to_sign_in_on_its_page(): void
    {
        $this->get('http://localhost/catalog')->assertOk()->assertSee('Open kettle')->assertDontSee('Kettle box of 50');
        $this->get('http://localhost/sitemap.xml')->assertOk()->assertDontSee('ca-box');

        // Found by nobody outside the group — the results page and the live window.
        app(\Torgash\Search\Indexer::class)->all();
        // One product found, so the search goes straight to it — the open one.
        $this->get('http://localhost/search?q=kettle')->assertRedirectContains('ca-open');
        $this->get('http://localhost/search/suggest?q=kettle')->assertOk()->assertDontSee('Kettle box of 50');

        $this->get('http://localhost/product/ca-box')->assertStatus(403)
            ->assertSee('Kettle box of 50')
            ->assertSee(__('Available to :who.', ['who' => 'Wholesale']))
            ->assertDontSee(format_price(300_00))
            ->assertSee('noindex', false);
        $this->get('http://localhost/product/ca-box.md')->assertNotFound();

        $this->post('http://localhost/cart/add/ca-box', ['qty' => 1]);
        $this->assertSame(0, app(\Torgash\Cart\CartManager::class)->items()->count(), 'not sold to an outsider');
    }

    public function test_the_group_sees_and_buys_it(): void
    {
        $buyer = User::factory()->create(['customer_group_id' => $this->wholesale->id]);

        $this->actingAs($buyer)->get('http://localhost/catalog')->assertOk()->assertSee('Kettle box of 50');
        $this->get('http://localhost/product/ca-box')->assertOk()->assertSee('Kettle box of 50');
        $this->post('http://localhost/cart/add/ca-box', ['qty' => 1])->assertOk();
    }

    public function test_the_shop_may_say_it_does_not_exist_at_all(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'closed_assortment', 'hide');

        $this->get('http://localhost/product/ca-box')->assertNotFound();
    }

    public function test_a_closed_category_closes_everything_under_it(): void
    {
        $root = Category::create(['name' => ['en' => 'Wholesale range'], 'slug' => 'ca-range', 'status' => 'active', 'customer_groups' => ['registered']]);
        $child = Category::create(['name' => ['en' => 'Boxes'], 'slug' => 'ca-boxes', 'status' => 'active', 'parent_id' => $root->id]);
        $inside = Product::create(['sku' => 'CA-IN', 'name' => ['en' => 'Mug crate'], 'slug' => 'ca-in', 'price' => 50_00, 'stock' => 5, 'status' => 'active']);
        $inside->categories()->attach($child->id);
        app(CategoryTree::class)->flush();

        $this->get('http://localhost/catalog')->assertDontSee('Mug crate');
        $this->get('http://localhost/catalog/ca-boxes')->assertStatus(403)->assertSee(__('Available to :who.', ['who' => __('customers with an account')]));
        $this->assertFalse(app(Assortment::class)->categoryOpen($child));

        // Any account opens it.
        $this->actingAs(User::factory()->create());
        app()->forgetInstance(Assortment::class);
        $this->get('http://localhost/catalog/ca-boxes')->assertOk()->assertSee('Mug crate');
    }

    public function test_staff_see_everything_and_the_form_sets_who_sees_it(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/product/ca-box')->assertOk();
        $this->get('http://localhost/admin/catalog/products/'.$this->open->id.'/edit')->assertOk()->assertSee(__('Who sees it'));
        $this->assertTrue(app(Assortment::class)->productOpen($this->closed));

        // The category form keeps who sees it — and its status beside it.
        $this->post('http://localhost/admin/catalog/categories', [
            'name' => 'Club shelf', 'slug' => 'ca-club', 'status' => 'active',
            'customer_groups_set' => 1, 'customer_groups' => ['group:'.$this->wholesale->id, 'bogus'],
        ])->assertRedirect();
        $made = Category::where('slug', 'ca-club')->firstOrFail();
        $this->assertSame(['group:'.$this->wholesale->id], $made->customer_groups, 'only words the form offers');
        $this->assertSame('active', $made->status);
    }
}
