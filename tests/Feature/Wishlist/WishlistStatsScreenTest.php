<?php

namespace Tests\Feature\Wishlist;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * The favourites screen: products and numbers, never people. A product that
 * is wanted and cannot be bought is listed with its price and word for its
 * status; the name of whoever wished for it appears nowhere.
 */
class WishlistStatsScreenTest extends TestCase
{
    public function test_it_shows_wanted_products_and_never_whose_list_it_is(): void
    {
        $customer = User::create(['name' => 'Zoya Wishful', 'email' => 'zoya-'.Str::random(6).'@example.test', 'password' => bcrypt('x')]);
        $wanted = Product::create(['sku' => 'WSH-'.random_int(1000, 9999), 'name' => ['en' => 'Kettle Omega Wish'], 'slug' => 'kettle-omega-'.random_int(1000, 9999),
            'price' => 12_00, 'stock' => 0, 'status' => 'active', 'availability_status' => 'out_of_stock']);

        $listId = DB::table('m_wishlist_lists')->insertGetId(['token' => Str::random(40), 'user_id' => $customer->id, 'notify_stock' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('m_wishlist_items')->insert(['list_id' => $listId, 'product_id' => $wanted->id, 'price_at_add' => 12_00, 'created_at' => now()]);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/wishlist?days=7')
            ->assertOk()
            ->assertSee('Kettle Omega Wish')
            ->assertSee($wanted->sku)
            ->assertDontSee('Zoya Wishful')
            ->assertDontSee($customer->email);
    }
}
