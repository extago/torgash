<?php

namespace Tests\Feature\Core;

use App\Core\Access\Impersonation;
use App\Core\PageCache\PageCache;
use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderSource;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Guard\Models\AuditEntry;
use Torgash\Pages\Models\Page;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ZoneMatcher;
use Torgash\Wishlist\Models\WishList;
use Torgash\Wishlist\Wishlists;

/**
 * A manager looking at the storefront through a customer's eyes (PLAN §70):
 * the customer's on the storefront, themselves in the admin, and what is the
 * customer's alone stays closed.
 */
class CustomerImpersonationTest extends TestCase
{
    protected const BAR = 'data-impersonation-bar';

    protected User $manager;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['guard.audit.enabled' => true, 'guard.audit.ignore' => [], 'guard.audit.max_per_request' => 200]);
        AuditEntry::query()->delete();

        $role = Role::findOrCreate('phone-support');
        $role->givePermissionTo(['admin.access', 'customers.manage', Impersonation::PERMISSION]);

        $this->manager = User::factory()->create(['name' => 'Mia Manager']);
        $this->manager->assignRole($role);

        $this->customer = User::factory()->create([
            'name' => 'Carl Customer', 'email' => 'carl@impersonation.test', 'password' => Hash::make('old-password'),
        ]);
    }

    protected function card(?User $customer = null): string
    {
        return 'http://localhost/admin/customers/'.($customer ?? $this->customer)->id;
    }

    /**
     * Every request starts as the manager: this application keeps one guard
     * from request to request (php-fpm and a worker do not — WorkerModeTest),
     * and the last request left the customer in it.
     */
    protected function inside(): static
    {
        $this->app->forgetInstance(CartManager::class);

        return $this->actingAs($this->manager)->withSession([
            Impersonation::SESSION => ['manager' => $this->manager->id, 'customer' => $this->customer->id],
        ]);
    }

    public function test_the_button_is_only_for_those_given_the_right(): void
    {
        $this->actingAs($this->manager)->get($this->card())
            ->assertOk()->assertSee('/impersonate"', false)->assertSee('target="_blank"', false);

        $role = Role::findOrCreate('customers-only');
        $role->givePermissionTo(['admin.access', 'customers.manage']);
        $reader = User::factory()->create();
        $reader->assignRole($role);

        $this->actingAs($reader)->get($this->card())->assertOk()->assertDontSee('/impersonate"', false);
        $this->post($this->card().'/impersonate')->assertForbidden();
        $this->assertNull(session(Impersonation::SESSION));
    }

    public function test_a_staff_account_is_never_entered(): void
    {
        $this->actingAs($this->manager)->post($this->card(User::role('admin')->firstOrFail()).'/impersonate')->assertNotFound();
        $this->assertNull(session(Impersonation::SESSION));
    }

    public function test_the_storefront_is_the_customers_and_the_admin_stays_the_managers(): void
    {
        $this->actingAs($this->manager)->post($this->card().'/impersonate')->assertRedirect('http://localhost/account');

        $this->get('http://localhost/account')->assertOk()->assertSee(self::BAR, false)->assertSee('Carl Customer');
        $this->assertSame($this->customer->id, auth()->id());

        $this->actingAs($this->manager)->get('http://localhost/admin/customers')->assertOk()->assertDontSee(self::BAR, false);
        $this->assertSame($this->manager->id, auth()->id());

        // Out of it, in the same session, and the shop is theirs again.
        $this->post('http://localhost/admin/impersonation/stop')->assertRedirect($this->card());
        $this->actingAs($this->manager)->get('http://localhost/account')->assertOk()->assertDontSee(self::BAR, false);
        $this->assertSame($this->manager->id, auth()->id());
    }

    public function test_a_page_at_a_root_address_is_seen_the_same_way(): void
    {
        Page::create(['title' => ['en' => 'About'], 'slug' => 'about-as-customer', 'body' => ['en' => '<p>Text</p>'], 'enabled' => true]);

        $this->inside()->get('http://localhost/about-as-customer')->assertOk()->assertSee(self::BAR, false);
        $this->assertSame($this->customer->id, auth()->id());
    }

    public function test_entering_signs_nobody_in(): void
    {
        Event::fake([Login::class]);

        $this->inside()->get('http://localhost/account')->assertOk();

        Event::assertNotDispatched(Login::class);
    }

    /** @return array<string, array{string, string}> */
    public static function closedToAManager(): array
    {
        return [
            'a new password' => ['PUT', '/account/password'],
            'a new password, the other form' => ['PUT', '/account/password/new'],
            'its form' => ['GET', '/account/password/new'],
            'a new phone' => ['POST', '/account/contact/phone'],
            'its form' => ['GET', '/account/contact/phone'],
            'taking the email away' => ['DELETE', '/account/contact/email'],
            'the name on the account' => ['PUT', '/account/profile'],
            'erasing the account' => ['POST', '/account/privacy/erase'],
            'taking a copy of the data' => ['GET', '/account/privacy/download'],
            'the second factor' => ['GET', '/account/security'],
            'switching it off' => ['DELETE', '/account/security'],
            'a letter they would consent to' => ['POST', '/account/newsletter'],
            'an enquiry in their name' => ['POST', '/enquiry'],
            'a review of the shop' => ['POST', '/reviews'],
            'a comment' => ['POST', '/comments'],
            'a card into their account' => ['POST', '/account/gift-cards'],
        ];
    }

    #[DataProvider('closedToAManager')]
    public function test_what_is_the_customers_alone_stays_closed(string $method, string $path): void
    {
        $this->inside()->from('http://localhost/account/orders')
            ->call($method, 'http://localhost'.$path)
            ->assertRedirect('http://localhost/account/orders');

        $this->actingAs($this->manager)->get('http://localhost/account/orders')->assertSee(__('Only the customer can do this.'));
    }

    public function test_a_refusal_comes_before_the_controller(): void
    {
        $this->inside()->put('http://localhost/account/password', [
            'current_password' => 'old-password', 'password' => 'a-brand-new-one', 'password_confirmation' => 'a-brand-new-one',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('old-password', $this->customer->fresh()->password));
    }

    public function test_a_refusal_speaks_to_htmx_and_to_a_script(): void
    {
        $this->inside()->withHeader('HX-Request', 'true')->post('http://localhost/comments')
            ->assertOk()->assertHeader('HX-Refresh', 'true');

        $this->inside()->withHeader('HX-Request', 'false')->postJson('http://localhost/comments')->assertForbidden();
    }

    /** A module added later is closed until its owner says otherwise, and these are all that are open. */
    public function test_the_changes_a_manager_may_make_are_exactly_these(): void
    {
        $writes = (array) hook_filter('core.impersonation.writes', []);
        sort($writes);

        $this->assertSame([
            'account.addresses.*',
            'age.confirm',
            'cart.*',
            'checkout.one_click',
            'checkout.quote',
            'checkout.store',
            'checkout.summary',
            'compare.*',
            'giftcards.cart.*',
            'giftcards.use',
            'kits.add',
            'kits.quote',
            'locations.save',
            'options.quote',
            'points.line',
            'points.spend',
            'warehouses.point.*',
            'wishlist.remove',
            'wishlist.toggle',
        ], $writes);
    }

    /** A name misspelt in a list would quietly be no rule at all. */
    public function test_every_name_in_the_lists_is_a_real_route(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => (string) $route->getName())->filter();

        foreach (['writes', 'closed', 'own'] as $list) {
            foreach ((array) hook_filter('core.impersonation.'.$list, []) as $pattern) {
                $this->assertTrue($names->contains(fn (string $name) => Str::is($pattern, $name)), $list.': '.$pattern);
            }
        }
    }

    public function test_the_basket_is_the_customers_and_the_managers_own_is_left_alone(): void
    {
        $product = Product::create(['sku' => 'AS-1', 'name' => ['en' => 'Kettle'], 'slug' => 'as-kettle', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);
        $mine = Cart::create(['token' => Cart::newToken(), 'user_id' => $this->manager->id, 'items' => [], 'last_active_at' => now()]);
        $older = Cart::create(['token' => Cart::newToken(), 'user_id' => $this->customer->id, 'items' => [], 'last_active_at' => now()->subWeek()]);
        $theirs = Cart::create(['token' => Cart::newToken(), 'user_id' => $this->customer->id, 'items' => [], 'last_active_at' => now()->subHour()]);

        $this->inside()->withSession(['cart_token' => $mine->token])
            ->post('http://localhost/cart/add/as-kettle', ['qty' => 2])->assertSessionHasNoErrors();

        $this->assertCount(1, $theirs->fresh()->items, 'the one they used last');
        $this->assertCount(0, $older->fresh()->items);
        $this->assertCount(0, $mine->fresh()->items);
        $this->assertSame($this->manager->id, $mine->fresh()->user_id);
        $this->assertSame($mine->token, session('cart_token'));
        $this->assertSame($product->id, $theirs->fresh()->items[0]['product_id']);
    }

    public function test_a_customer_with_no_basket_gets_their_own_and_not_the_managers(): void
    {
        Product::create(['sku' => 'AS-2', 'name' => ['en' => 'Teapot'], 'slug' => 'as-teapot', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);
        $mine = Cart::create(['token' => Cart::newToken(), 'user_id' => $this->manager->id, 'items' => [], 'last_active_at' => now()]);

        $this->inside()->withSession(['cart_token' => $mine->token])->post('http://localhost/cart/add/as-teapot', ['qty' => 1]);

        $this->assertCount(1, Cart::where('user_id', $this->customer->id)->sole()->items);
        $this->assertCount(0, $mine->fresh()->items);
        $this->assertSame($mine->token, session('cart_token'));
    }

    public function test_an_order_is_the_customers_and_says_whose_hand_placed_it(): void
    {
        $this->arrangeOneClick();

        $this->inside()->post('http://localhost/one-click', [
            'product_id' => Product::where('sku', 'AS-3')->value('id'),
            'customer_name' => 'Carl',
            'phone' => '+380 (67) 555-01-28',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame($this->customer->id, $order->user_id);
        $this->assertSame(OrderSource::ADMIN, $order->source);
        $this->assertEmpty($order->client, "the manager's browser says nothing about the customer");

        $line = OrderLog::where('order_id', $order->id)->where('description', 'Placed by :name on behalf of the customer')->sole();
        $this->assertSame($this->manager->id, $line->user_id);
        $this->assertSame('Mia Manager', $line->params['name']);
    }

    protected function arrangeOneClick(): void
    {
        RateLimiter::clear('form:one_click:ip:127.0.0.1');

        Product::create(['sku' => 'AS-3', 'name' => ['en' => 'Mug'], 'slug' => 'as-mug', 'price' => 250_00, 'stock' => 5, 'status' => 'active']);

        $shipping = ShippingMethod::create(['name' => ['en' => 'Arrange'], 'price_mode' => 'quote', 'position' => 90, 'enabled' => true]);
        $shipping->rates()->create(['zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true]);
        $payment = PaymentMethod::create(['name' => ['en' => 'Agree'], 'enabled' => true, 'position' => 90]);

        $settings = app(SettingsManager::class);
        $settings->set('torgash.checkout', 'one_click', true);
        $settings->set('torgash.checkout', 'one_click_shipping_id', (string) $shipping->id);
        $settings->set('torgash.checkout', 'one_click_payment_id', $payment->id);
    }

    public function test_the_prices_are_the_customers(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 20]);
        $this->customer->update(['customer_group_id' => $group->id]);
        Product::create(['sku' => 'AS-5', 'name' => ['en' => 'Lamp'], 'slug' => 'as-lamp', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);

        $wholesale = format_unit_price(80_00);

        $this->actingAs($this->manager)->get('http://localhost/product/as-lamp')->assertOk()->assertDontSee($wholesale);
        $this->inside()->get('http://localhost/product/as-lamp')->assertOk()->assertSee($wholesale);
    }

    public function test_a_list_held_by_the_managers_browser_does_not_land_on_the_customer(): void
    {
        $product = Product::create(['sku' => 'AS-6', 'name' => ['en' => 'Vase'], 'slug' => 'as-vase', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);
        $guest = WishList::create(['token' => WishList::newToken(), 'last_active_at' => now()]);
        $this->app->forgetInstance(Wishlists::class);

        $this->inside()->withSession([Wishlists::SESSION => $guest->token])->post('http://localhost/wishlist/toggle/as-vase');

        $this->assertTrue(WishList::whereKey($guest->id)->whereNull('user_id')->exists(), "the guest's list stays a guest's");
        $this->assertSame($guest->token, session(Wishlists::SESSION));

        $theirs = WishList::where('user_id', $this->customer->id)->sole();
        $this->assertSame([$product->id], DB::table('m_wishlist_items')->where('list_id', $theirs->id)->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_reading_their_enquiry_leaves_the_new_reply_unread(): void
    {
        $enquiry = Enquiry::create(['kind' => 'callback', 'name' => 'Carl', 'phone' => '+380671234567', 'user_id' => $this->customer->id]);

        $this->inside()->get('http://localhost/account/enquiries/'.$enquiry->id)->assertOk();

        $this->assertNull($enquiry->fresh()->customer_seen_at);
    }

    public function test_the_journal_has_both_ends_under_the_managers_name(): void
    {
        $this->actingAs($this->manager)->post($this->card().'/impersonate');
        $this->actingAs($this->manager)->post('http://localhost/admin/impersonation/stop');

        foreach (['entered_as', 'left_as'] as $action) {
            $entry = AuditEntry::where('action', $action)->sole();

            $this->assertSame($this->manager->id, $entry->user_id);
            $this->assertSame('Mia Manager', $entry->user_name);
            $this->assertSame((string) $this->customer->id, $entry->entity_id);
            $this->assertSame('Carl Customer', $entry->entity_title);
        }
    }

    public function test_what_they_change_inside_is_written_under_their_name(): void
    {
        $this->inside()->post('http://localhost/account/addresses', [
            'full_name' => 'Carl', 'address_line' => 'Main st, 1', 'city' => 'Kyiv',
        ])->assertRedirect();

        $this->assertSame(1, $this->customer->addresses()->count());

        $entry = AuditEntry::where('action', 'created')->where('entity_type', \App\Models\Address::class)->sole();
        $this->assertSame($this->manager->id, $entry->user_id);
    }

    public function test_an_ordinary_customers_changes_are_still_no_business_of_the_journal(): void
    {
        $this->actingAs($this->customer)->post('http://localhost/account/addresses', [
            'full_name' => 'Carl', 'address_line' => 'Main st, 1', 'city' => 'Kyiv',
        ])->assertRedirect();

        $this->assertSame(0, AuditEntry::where('entity_type', \App\Models\Address::class)->count());
    }

    public function test_taking_the_right_away_ends_it_on_the_next_page(): void
    {
        Role::findByName('phone-support')->revokePermissionTo(Impersonation::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->manager = $this->manager->fresh();

        $this->inside()->get('http://localhost/account')->assertOk()->assertDontSee(self::BAR, false);

        $this->assertSame($this->manager->id, auth()->id());
        $this->assertNull(session(Impersonation::SESSION));
        $this->assertSame($this->manager->id, AuditEntry::where('action', 'left_as')->sole()->user_id);
    }

    public function test_a_customer_made_staff_is_not_looked_through_any_more(): void
    {
        $this->customer->assignRole('phone-support');

        $this->inside()->get('http://localhost/account')->assertOk()->assertDontSee(self::BAR, false);

        $this->assertSame($this->manager->id, auth()->id());
        $this->assertNull(session(Impersonation::SESSION));
    }

    public function test_a_deleted_customer_ends_it(): void
    {
        $this->inside();
        $this->customer->delete();

        $this->get('http://localhost/account')->assertOk()->assertDontSee(self::BAR, false);

        $this->assertSame($this->manager->id, auth()->id());
        $this->assertNull(session(Impersonation::SESSION));
        $this->assertSame((string) $this->customer->id, AuditEntry::where('action', 'left_as')->sole()->entity_id);
    }

    public function test_somebody_elses_note_in_a_session_opens_nothing(): void
    {
        $other = User::factory()->create();
        $other->assignRole('phone-support');

        $this->actingAs($other)->withSession([
            Impersonation::SESSION => ['manager' => $this->manager->id, 'customer' => $this->customer->id],
        ])->get('http://localhost/account')->assertOk()->assertDontSee(self::BAR, false);

        $this->assertSame($other->id, auth()->id());
    }

    public function test_signing_out_inside_leaves_the_customer_and_nothing_else(): void
    {
        $this->inside()->post('http://localhost/account/logout')->assertRedirect($this->card());

        $this->assertSame($this->manager->id, auth()->id());
        $this->assertNull(session(Impersonation::SESSION));
    }

    public function test_the_customers_language_is_left_alone(): void
    {
        $storefront = \App\Models\Storefront::query()->firstOrFail();
        $storefront->update(['locales' => array_values(array_unique([...($storefront->locales ?? []), 'en', 'ru'])), 'default_locale' => 'en']);
        $this->customer->forceFill(['locale' => 'en'])->saveQuietly();

        $this->inside()->get('http://localhost/account')->assertOk();
        $this->inside()->get('http://localhost/ru/account')->assertOk()->assertSee(self::BAR, false);

        $this->assertSame('en', $this->customer->fresh()->locale);
    }

    public function test_the_managers_own_second_factor_stays_theirs(): void
    {
        $this->inside()->get('http://localhost/two-factor');

        $this->assertSame($this->manager->id, auth()->id());
        $this->assertNotNull(session(Impersonation::SESSION), 'not ended: they are only proving who they are');
    }

    public function test_no_page_seen_through_a_customers_eyes_is_kept(): void
    {
        app(SettingsManager::class)->set('core', PageCache::ENABLED, true);

        $response = $this->inside()->get('http://localhost/')->assertOk()->assertSee(self::BAR, false);

        $this->assertStringContainsString('signed in', (string) $response->headers->get('X-Torgash-Cache'));
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('page_cache')->count());
    }
}
