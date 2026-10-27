<?php

namespace Tests\Feature\Core;

use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Reviews\Models\Review;

/**
 * Customers and their groups in the admin's common shape — search (a phone
 * too), tabs by group, the group switched in place and for several at once,
 * a card that edits the account and stays open — and the seam: core names
 * no module, checkout answers with the customer's orders and money PAID,
 * enquiries with their threads, the wishlist with what they saved. An account
 * is made here and deleted here, and deleting one leaves the shop's records.
 */
class CustomersScreenTest extends TestCase
{
    protected User $admin;

    protected CustomerGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        $this->group = CustomerGroup::create(['name' => ['en' => 'Screen club'], 'discount_percent' => 5]);
    }

    protected function customer(string $name, array $extra = []): User
    {
        return User::factory()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@screen.test'] + $extra);
    }

    public function test_search_reads_a_phone_and_tabs_narrow_by_group(): void
    {
        $inGroup = $this->customer('Zeta Club', ['customer_group_id' => $this->group->id]);
        $this->customer('Zeta Plain');
        $inGroup->addresses()->create(['label' => 'Home', 'full_name' => 'Zeta', 'phone' => '+380 67 555 12 34', 'address_line' => 'x', 'city' => 'Kyiv', 'postal_code' => '1', 'is_default' => true]);

        $this->actingAs($this->admin)->get('http://localhost/admin/customers?q=555 12')
            ->assertOk()->assertSee('Zeta Club')->assertDontSee('Zeta Plain');
        $this->get('http://localhost/admin/customers?q=Zeta&group='.$this->group->id)
            ->assertSee('Zeta Club')->assertDontSee('Zeta Plain');
        $this->get('http://localhost/admin/customers?q=Zeta&group=none')
            ->assertSee('Zeta Plain')->assertDontSee('Zeta Club');
    }

    public function test_staff_are_not_customers_here(): void
    {
        $this->actingAs($this->admin)->get('http://localhost/admin/customers/'.$this->admin->id)->assertNotFound();
        $this->get('http://localhost/admin/customers?q='.urlencode($this->admin->email))->assertSee('No customers match this search.');
    }

    public function test_group_in_place_and_in_bulk(): void
    {
        $one = $this->customer('Bulk One');
        $two = $this->customer('Bulk Two');

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/customers/{$one->id}/group", ['customer_group_id' => $this->group->id], ['HX-Request' => 'true'])
            ->assertOk()->assertSee('customer-group-'.$one->id, false)->assertSee('Screen club');
        $this->assertSame($this->group->id, $one->fresh()->customer_group_id);

        $this->patch("http://localhost/admin/customers/{$one->id}/group", ['customer_group_id' => ''], ['HX-Request' => 'true'])->assertOk();
        $this->assertNull($one->fresh()->customer_group_id);

        $this->post('http://localhost/admin/customers/bulk', ['action' => 'group', 'ids' => [$one->id, $two->id], 'customer_group_id' => $this->group->id])->assertRedirect();
        $this->assertSame(2, User::where('customer_group_id', $this->group->id)->whereIn('id', [$one->id, $two->id])->count());
    }

    public function test_the_card_edits_the_account_and_stays_open(): void
    {
        $customer = $this->customer('Card Person');

        $this->actingAs($this->admin)->put('http://localhost/admin/customers/'.$customer->id, [
            'name' => 'Card Renamed', 'email' => 'card.renamed@screen.test', 'customer_group_id' => $this->group->id,
        ])->assertRedirect(route('admin.customers.edit', $customer));

        $customer->refresh();
        $this->assertSame('Card Renamed', $customer->name);
        $this->assertSame($this->group->id, $customer->customer_group_id);

        $this->put('http://localhost/admin/customers/'.$customer->id, [
            'name' => 'Card Renamed', 'email' => $this->admin->email,
        ])->assertSessionHasErrors('email');
    }

    public function test_checkout_answers_with_orders_and_money_paid(): void
    {
        $customer = $this->customer('Order Person');
        Order::create([
            'order_number' => 'TG-SCR-1', 'user_id' => $customer->id,
            'customer_name' => 'Order Person', 'email' => $customer->email,
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => 'USD', 'subtotal' => 20_00, 'shipping_cost' => 0, 'total' => 20_00,
            'status' => 'open', 'payment_state' => PaymentState::PAID, 'paid_total' => 20_00,
            'stock_deducted' => false, 'items' => [],
        ]);

        $this->actingAs($this->admin)->get('http://localhost/admin/customers?q=Order+Person')
            ->assertOk()->assertSee(route('admin.orders.index', ['customer' => $customer->id]), false);
        $this->get('http://localhost/admin/customers/'.$customer->id)
            ->assertOk()->assertSee('TG-SCR-1')->assertSee(admin_price(20_00, 'USD'));
        $this->get('http://localhost/admin/orders?customer='.$customer->id)
            ->assertOk()->assertSee('TG-SCR-1');
    }

    public function test_one_group_at_most_joins_new_registrations(): void
    {
        $other = CustomerGroup::create(['name' => ['en' => 'Other'], 'discount_percent' => 0, 'is_default' => true]);

        $this->actingAs($this->admin)->post("http://localhost/admin/customer-groups/{$this->group->id}/default")->assertRedirect();
        $this->assertTrue($this->group->fresh()->is_default);
        $this->assertFalse($other->fresh()->is_default);

        // Pressing the chosen one again means "nobody by default".
        $this->post("http://localhost/admin/customer-groups/{$this->group->id}/default")->assertRedirect();
        $this->assertFalse($this->group->fresh()->is_default);
    }

    protected function order(User $customer, string $number): Order
    {
        return Order::create([
            'order_number' => $number, 'user_id' => $customer->id,
            'customer_name' => $customer->name, 'email' => $customer->email,
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => 'USD', 'subtotal' => 20_00, 'shipping_cost' => 0, 'total' => 20_00,
            'status' => 'open', 'payment_state' => PaymentState::PAID, 'paid_total' => 20_00,
            'stock_deducted' => false, 'items' => [],
        ]);
    }

    protected function product(string $name): Product
    {
        return Product::create(['sku' => 'CST-'.Str::random(6), 'name' => ['en' => $name], 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'price' => 12_00, 'stock' => 3, 'status' => 'active']);
    }

    protected function enquiry(string $name, string $email, string $text, ?int $userId = null): Enquiry
    {
        return Enquiry::create(['kind' => 'message', 'name' => $name, 'email' => $email, 'user_id' => $userId,
            'fields' => ['values' => ['message' => $text], 'labels' => ['message' => 'Message']]]);
    }

    public function test_the_shop_makes_an_account_and_it_is_a_customer(): void
    {
        $this->actingAs($this->admin)->get('http://localhost/admin/customers/create')->assertOk()->assertSee('New customer');

        $this->post('http://localhost/admin/customers', [
            'name' => 'Made Here', 'email' => 'made.here@screen.test', 'customer_group_id' => $this->group->id, 'password' => 'secret-pass',
        ])->assertRedirect();

        $made = User::where('email', 'made.here@screen.test')->firstOrFail();
        $this->assertTrue($made->roles()->doesntExist(), 'a customer has no role');
        $this->assertSame($this->group->id, $made->customer_group_id);
        $this->assertTrue(Hash::check('secret-pass', $made->password));

        // No password typed: the account exists and nothing guessable opens it.
        $this->post('http://localhost/admin/customers', ['name' => 'No Pass', 'email' => 'no.pass@screen.test'])->assertRedirect();
        $this->assertNotEmpty(User::where('email', 'no.pass@screen.test')->value('password'));

        $this->post('http://localhost/admin/customers', ['name' => 'Twin', 'email' => 'made.here@screen.test'])->assertSessionHasErrors('email');

        // The card sets a new one; a blank box keeps it.
        $this->put('http://localhost/admin/customers/'.$made->id, ['name' => 'Made Here', 'email' => $made->email, 'password' => ''])->assertRedirect();
        $this->assertTrue(Hash::check('secret-pass', $made->fresh()->password));
        $this->put('http://localhost/admin/customers/'.$made->id, ['name' => 'Made Here', 'email' => $made->email, 'password' => 'another-pass'])->assertRedirect();
        $this->assertTrue(Hash::check('another-pass', $made->fresh()->password));
    }

    public function test_deleting_says_what_stays_and_leaves_the_shop_records(): void
    {
        $customer = $this->customer('Leaving Person');
        $customer->addresses()->create(['label' => 'Home', 'full_name' => 'L', 'phone' => '1', 'address_line' => 'x', 'city' => 'Kyiv', 'postal_code' => '1', 'is_default' => true]);
        $order = $this->order($customer, 'TG-DEL-1');
        $enquiry = $this->enquiry('Leaving Person', $customer->email, 'Bye', $customer->id);
        $product = $this->product('Leaving Kettle');
        $review = Review::create(['product_id' => $product->id, 'user_id' => $customer->id, 'author_name' => 'Leaving Person',
            'body' => 'Fine', 'rating' => 5, 'status' => Review::PUBLISHED, 'verified' => false]);
        $listId = DB::table('m_wishlist_lists')->insertGetId(['token' => Str::random(40), 'user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('m_wishlist_items')->insert(['list_id' => $listId, 'product_id' => $product->id, 'price_at_add' => 12_00, 'created_at' => now()]);

        // Each owner says what happens to its own rows, before anything is touched.
        $this->actingAs($this->admin)->get('http://localhost/admin/customers/'.$customer->id)->assertOk()
            ->assertSee('1 saved address is deleted.')
            ->assertSee('1 order stays, under the name and address it was placed with.')
            ->assertSee('1 enquiry stays in the inbox')
            ->assertSee('1 review stays on the site')
            ->assertSee('The wishlist of 1 product is deleted.');

        $this->delete('http://localhost/admin/customers/'.$customer->id)->assertRedirect(route('admin.customers.index'));

        $this->assertNull(User::find($customer->id));
        $this->assertSame(0, DB::table('addresses')->where('user_id', $customer->id)->count());
        $this->assertNull($order->fresh()->user_id);
        $this->assertSame('Leaving Person', $order->fresh()->customer_name);
        $this->assertNull($enquiry->fresh()->user_id);
        $this->assertNull($review->fresh()->user_id, 'a review with no foreign key lets go of the account');
        $this->assertSame('Leaving Person', $review->fresh()->author_name);
        $this->assertSame(0, DB::table('m_wishlist_lists')->where('id', $listId)->count());
    }

    public function test_the_screens_need_their_own_permission(): void
    {
        $customer = $this->customer('Guarded Person');
        $role = \Spatie\Permission\Models\Role::findOrCreate('warehouse-screen');
        $role->syncPermissions(['admin.access']);
        $manager = User::create(['name' => 'Stock Keeper', 'email' => 'keeper@screen.test', 'password' => 'secret123']);
        $manager->assignRole($role);

        $this->actingAs($manager)->get('http://localhost/admin/customers')->assertForbidden();
        $this->delete('http://localhost/admin/customers/'.$customer->id)->assertForbidden();
        $this->get('http://localhost/admin/customer-groups')->assertForbidden();
        $this->assertNotNull($customer->fresh());

        $role->givePermissionTo('customers.manage');
        $this->actingAs($manager->fresh())->get('http://localhost/admin/customers')->assertOk();
    }

    public function test_staff_are_not_deleted_here_and_several_go_at_once(): void
    {
        $this->actingAs($this->admin)->delete('http://localhost/admin/customers/'.$this->admin->id)->assertNotFound();

        $one = $this->customer('Bulk Gone One');
        $two = $this->customer('Bulk Gone Two');

        $this->post('http://localhost/admin/customers/bulk', ['action' => 'delete', 'ids' => [$one->id, $two->id, $this->admin->id]])->assertRedirect();
        $this->assertSame(0, User::whereIn('id', [$one->id, $two->id])->count());
        $this->assertNotNull($this->admin->fresh(), 'a staff id in the selection is ignored');
    }

    public function test_the_card_shows_enquiries_and_the_wishlist(): void
    {
        $customer = $this->customer('Asking Person');
        $mine = $this->enquiry('Asking Person', $customer->email, 'Where is the blue kettle', $customer->id);
        // Same address, no account: never stitched to the card by email.
        $this->enquiry('Somebody', $customer->email, 'Guest wrote this');
        $product = $this->product('Wished Omega Kettle');
        $listId = DB::table('m_wishlist_lists')->insertGetId(['token' => Str::random(40), 'user_id' => $customer->id, 'notify_price' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('m_wishlist_items')->insert(['list_id' => $listId, 'product_id' => $product->id, 'price_at_add' => 15_00, 'created_at' => now()]);

        $this->actingAs($this->admin)->get('http://localhost/admin/customers/'.$customer->id)->assertOk()
            ->assertSee('#'.$mine->id)->assertSee('Where is the blue kettle')->assertDontSee('Guest wrote this')
            ->assertSee('Wished Omega Kettle')->assertSee(admin_price(15_00, shop_currency()));

        $this->get('http://localhost/admin/customers?q=Asking+Person')->assertOk()
            ->assertSee('1 enquiry')->assertSee(route('admin.enquiries.index', ['customer' => $customer->id, 'view' => 'all']));

        // The inbox, narrowed to the account, names who it is narrowed to.
        $this->get('http://localhost/admin/enquiries?view=all&customer='.$customer->id)->assertOk()
            ->assertSee('Customer: Asking Person')->assertSee('Where is the blue kettle')->assertDontSee('Guest wrote this');
    }
}
