<?php

namespace Tests\Feature\Wishlist;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Wishlist\Models\WishList;
use Torgash\Wishlist\Wishlists;

/**
 * Favourites (PLAN §35.3, step 1): a guest keeps a list as a basket is kept,
 * signing in pours it into the account's, the heart toggles and the header
 * counts, and the page filters by the catalogue's own rules.
 */
class WishlistTest extends TestCase
{
    public function test_a_guest_toggles_the_heart_and_the_header_counts(): void
    {
        $product = $this->product('WL-1', 'Wishkettle');

        $answer = $this->post(route('wishlist.toggle', 'wl-1'), [], ['HX-Request' => 'true'])->assertOk()->getContent();

        $this->assertStringContainsString('aria-pressed="true"', $answer);
        $this->assertMatchesRegularExpression('/id="wishlist-counter"[^>]*hx-swap-oob="true"/', $answer);
        $this->assertStringContainsString('<span class="badge badge-sm badge-primary">1</span>', $answer);

        $list = WishList::firstOrFail();
        $this->assertNull($list->user_id);
        $this->assertSame([$product->id], $list->items()->pluck('product_id')->all());
        $this->assertSame(100_00, (int) $list->items()->value('price_at_add'), 'the price it had when it was put there');

        $this->post(route('wishlist.toggle', 'wl-1'), [], ['HX-Request' => 'true'])->assertOk()->assertSee('aria-pressed="false"', false);
        $this->assertSame(0, $list->items()->count());
    }

    /** An add says so in core's corner stack; a removal and the switched-off setting say nothing. */
    public function test_an_add_answers_with_a_corner_notice(): void
    {
        $this->product('WL-14', 'Wishkettle');
        $toggle = fn () => $this->post(route('wishlist.toggle', 'wl-14'), [], ['HX-Request' => 'true'])->assertOk()->getContent();

        $added = $toggle();
        $this->assertStringContainsString('id="torgash-notices" hx-swap-oob="afterbegin"', $added);
        $this->assertStringContainsString('Added to your wishlist', $added);
        $this->assertStringContainsString('href="'.route('wishlist.index').'"', $added);
        $this->assertStringContainsString('data-just-toggled', $added);

        $this->assertStringNotContainsString('torgash-notices', $toggle());

        app(SettingsManager::class)->set('torgash.wishlist', 'notice_on_add', false);
        $this->assertStringNotContainsString('torgash-notices', $toggle());

        // The stack itself is on every storefront page, printed by core.
        $this->get('http://localhost/')->assertOk()->assertSee('id="torgash-notices" class="torgash-notices"', false);
    }

    public function test_looking_creates_no_list(): void
    {
        $this->product('WL-2', 'Wishkettle');

        $this->get('http://localhost/')->assertOk()->assertSee('id="wishlist-counter"', false);
        $this->get(route('wishlist.index'))->assertOk();

        $this->assertSame(0, WishList::count());
    }

    public function test_without_javascript_the_heart_posts_and_comes_back(): void
    {
        $this->product('WL-3', 'Wishkettle');

        $this->from(route('catalog.product', 'wl-3'))->post(route('wishlist.toggle', 'wl-3'))->assertRedirect(route('catalog.product', 'wl-3'));
        $this->assertSame(1, DB::table('m_wishlist_items')->count());
    }

    /** Signing in pours the guest's list into the account's — without duplicates. */
    public function test_signing_in_merges_the_guest_list_into_the_account(): void
    {
        $kettle = $this->product('WL-4', 'Wishkettle');
        $lamp = $this->product('WL-5', 'Wishlamp');
        $user = User::factory()->create();
        $account = WishList::create(['token' => WishList::newToken(), 'user_id' => $user->id]);
        DB::table('m_wishlist_items')->insert(['list_id' => $account->id, 'product_id' => $kettle->id, 'price_at_add' => 1, 'created_at' => now()]);

        $this->post(route('wishlist.toggle', 'wl-4'))->assertRedirect();
        $this->post(route('wishlist.toggle', 'wl-5'))->assertRedirect();
        $this->assertSame(2, WishList::count());

        auth()->login($user);

        $this->assertSame(1, WishList::count(), 'the guest row is gone');
        $this->assertEqualsCanonicalizing([$kettle->id, $lamp->id], $account->items()->pluck('product_id')->all());
        $this->assertSame(1, (int) $account->items()->where('product_id', $kettle->id)->value('price_at_add'), 'the account kept its own price');
    }

    public function test_a_new_account_takes_the_guest_list_as_it_is(): void
    {
        $this->product('WL-6', 'Wishkettle');
        $this->post(route('wishlist.toggle', 'wl-6'))->assertRedirect();
        $user = User::factory()->create();

        auth()->login($user);

        $this->assertSame($user->id, WishList::firstOrFail()->user_id);
        $this->assertSame(1, app(Wishlists::class)->count());
    }

    public function test_the_heart_is_on_cards_and_under_the_buy_button(): void
    {
        $this->product('WL-7', 'Wishkettle');

        $this->get('http://localhost/catalog?sort=newest')->assertOk()->assertSee('action="'.route('wishlist.toggle', 'wl-7').'"', false);
        $this->get(route('catalog.product', 'wl-7'))->assertOk()->assertSee('Add to wishlist')->assertSee('name="labelled"', false);

        app(SettingsManager::class)->set('torgash.wishlist', 'card_button', false);
        $this->get('http://localhost/catalog?sort=newest')->assertOk()->assertDontSee('action="'.route('wishlist.toggle', 'wl-7').'"', false);
    }

    public function test_the_page_filters_by_the_catalogues_own_rules(): void
    {
        $parts = Category::create(['name' => ['en' => 'Wish parts'], 'slug' => 'wish-parts', 'enabled' => true]);
        $screens = Category::create(['name' => ['en' => 'Wish screens'], 'slug' => 'wish-screens', 'enabled' => true, 'parent_id' => $parts->id]);
        $screen = $this->product('WL-8', 'Wishscreen', ['list_price' => 200_00]);
        $screen->categories()->attach($screens->id);
        $cable = $this->product('WL-9', 'Wishcable', ['stock' => 0, 'availability_status' => 'out_of_stock']);

        foreach (['wl-8', 'wl-9'] as $slug) {
            $this->post(route('wishlist.toggle', $slug))->assertRedirect();
        }

        $page = fn (string $query = '') => strip_tags($this->get(route('wishlist.index').$query)->assertOk()->getContent());

        $all = $page();
        $this->assertStringContainsString('Wishscreen', $all);
        $this->assertStringContainsString('Wishcable', $all);
        // A category is its subcategories; the path under the name.
        $this->assertStringNotContainsString('Wishcable', $page('?category='.$parts->id));
        $this->assertStringContainsString('Wish screens', $all);
        $this->assertStringNotContainsString('Wishscreen', $page('?q=cable'));
        $this->assertStringContainsString('Wishcable', $page('?q=WL-9'));
        $this->assertStringNotContainsString('Wishcable', $page('?in_stock=1'));
        $this->assertStringNotContainsString('Wishcable', $page('?on_sale=1'));
        $this->assertStringContainsString('Wishscreen', $page('?on_sale=1'));
    }

    public function test_a_row_is_removed_and_the_list_cleared(): void
    {
        $this->product('WL-10', 'Wishkettle');
        $this->product('WL-11', 'Wishlamp');
        $this->post(route('wishlist.toggle', 'wl-10'));
        $this->post(route('wishlist.toggle', 'wl-11'));

        $removed = $this->post(route('wishlist.remove', 'wl-10'), [], ['HX-Request' => 'true'])->assertOk()->getContent();
        $this->assertStringContainsString('hx-swap-oob="true"', $removed);
        $this->assertSame(1, DB::table('m_wishlist_items')->count());

        $this->post(route('wishlist.clear'))->assertRedirect(route('wishlist.index'));
        $this->assertSame(0, DB::table('m_wishlist_items')->count());
    }

    /** On its own page the list's rows carry the basket's button, never a heart. */
    public function test_the_page_rows_offer_the_basket_and_a_remove(): void
    {
        $this->product('WL-12', 'Wishkettle');
        $this->post(route('wishlist.toggle', 'wl-12'));

        $this->get(route('wishlist.index'))->assertOk()
            ->assertSee(route('cart.add', 'wl-12'), false)
            ->assertSee('action="'.route('wishlist.remove', 'wl-12').'"', false)
            ->assertDontSee('action="'.route('wishlist.toggle', 'wl-12').'"', false);
    }

    public function test_a_signed_in_customer_finds_it_in_the_profile_with_a_count(): void
    {
        $this->product('WL-13', 'Wishkettle');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('wishlist.toggle', 'wl-13'));

        $this->actingAs($user)->get(route('account.dashboard'))->assertOk()
            ->assertSee(route('wishlist.index'), false);
        $this->actingAs($user)->get(route('wishlist.index'))->assertOk()
            ->assertSee(route('account.orders'), false);
    }

    public function test_old_guest_lists_go_and_accounts_stay(): void
    {
        $user = User::factory()->create();
        WishList::create(['token' => 'old-guest', 'last_active_at' => now()->subDays(200)]);
        WishList::create(['token' => 'old-account', 'user_id' => $user->id, 'last_active_at' => now()->subDays(200)]);
        WishList::create(['token' => 'new-guest', 'last_active_at' => now()]);

        $this->artisan('schedule:test', ['--name' => 'wishlist:prune-guests'])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['old-account', 'new-guest'], WishList::pluck('token')->all());
    }

    /** @param  array<string, mixed>  $extra */
    protected function product(string $sku, string $name, array $extra = []): Product
    {
        return Product::create($extra + [
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 10, 'status' => 'active', 'created_at' => now()->addYear(),
        ]);
    }
}
