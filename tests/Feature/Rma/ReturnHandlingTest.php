<?php

namespace Tests\Feature\Rma;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\RecordPayment;
use Torgash\Payments\Models\Binding;
use Torgash\Rma\Models\ReturnRequest;
use Torgash\Rma\State;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Warehouses\Models\Warehouse;

/**
 * Handling a return in the admin: the four acts, and everything each of them is
 * not allowed to do on its own.
 *
 * What is pinned here is mostly the boundaries between modules, because that is
 * where a returns module goes wrong. Stock goes back through checkout's own
 * code (and not at all for a warranty write-off); money goes through checkout's
 * Refund, which asks a gateway where there is one and refuses to invent a
 * payment where the shop was never recorded as paid; an exchange is a NEW order
 * belonging to the CUSTOMER, not to the manager who pressed the button; and the
 * order's own status only moves when there is nothing left of the order to send
 * back.
 */
class ReturnHandlingTest extends TestCase
{
    protected Product $product;

    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        FakeGateway::reset();

        // The guard counts per IP in the cache, which outlives a test's
        // transaction: without this the sixth test in the file would be told it
        // has sent too many enquiries.
        RateLimiter::clear('form:enquiry:ip:127.0.0.1');
        RateLimiter::clear('form:enquiry:id:guest@example.test');

        $this->product = Product::create([
            'sku' => 'RMA-1',
            'name' => ['en' => 'Returnable thing'],
            'slug' => 'returnable-thing',
            'price' => 100_00,
            'stock' => 5,
            'status' => 'active',
        ]);

        $this->method = PaymentMethod::create([
            'name' => ['en' => 'Cash to the courier'],
            'enabled' => true,
            'position' => 90,
        ]);

        // The shop has to say which of its own statuses a return may be asked
        // from; nothing ticked means nothing offered, which is the design.
        app(SettingsManager::class)->set('torgash.rma', 'statuses', [OrderStatus::default()->code]);
    }

    // ------------------------------------------------------------- the acts

    public function test_approving_moves_the_state_and_says_so_in_the_thread(): void
    {
        $return = $this->askForReturn();

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/approve', [
                'note' => 'Пришлите Новой Почтой на наше отделение №5.',
            ])
            ->assertRedirect();

        $return->refresh();

        $this->assertSame(State::APPROVED, $return->state);

        // A state badge on a page the customer may never open has told them
        // nothing; the note is what actually reaches them.
        $this->assertSame(
            'Пришлите Новой Почтой на наше отделение №5.',
            $return->enquiry->messages()->latest('id')->value('body'),
        );
    }

    public function test_receiving_the_goods_puts_them_back_into_stock(): void
    {
        $return = $this->askForReturn(bought: 2);

        $this->assertSame(3, $this->product->fresh()->stock, 'the order took two');

        $this->receive($return);

        $this->assertSame(5, $this->product->fresh()->stock);
        $this->assertSame(State::RECEIVED, $return->fresh()->state);
        $this->assertNotNull($return->fresh()->received_at);
    }

    /**
     * The warranty case, and the reason the disposition is a decision rather
     * than a setting: the item goes to the manufacturer or into the bin, and
     * adding it to stock would put a broken thing back on sale.
     */
    public function test_a_write_off_receives_the_goods_and_adds_nothing_to_stock(): void
    {
        $return = $this->askForReturn(bought: 2);

        $this->receive($return, ReturnRequest::WRITEOFF);

        $this->assertSame(3, $this->product->fresh()->stock);
        $this->assertSame(ReturnRequest::WRITEOFF, $return->fresh()->disposition);
        $this->assertSame(State::RECEIVED, $return->fresh()->state);
    }

    public function test_the_goods_cannot_be_received_twice(): void
    {
        $return = $this->askForReturn(bought: 2);

        $this->receive($return);
        $this->assertSame(5, $this->product->fresh()->stock);

        // Refused by the state, not silently applied a second time — otherwise
        // one impatient double-click invents a unit.
        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/receive', [
                'disposition' => ReturnRequest::RESTOCK,
            ])
            ->assertForbidden();

        $this->assertSame(5, $this->product->fresh()->stock);
    }

    /**
     * The owner's words about an exchange: "the quantity is added to the
     * warehouse it came back TO". A customer returns to the nearest shop, not
     * to the one that shipped — crediting the shipper would make two counts
     * wrong at once.
     *
     * The awkward half is that the shop it came back to may never have stocked
     * the thing. Its row is created there, rather than the credit being moved
     * to the default warehouse: the goods are physically on that shelf.
     */
    public function test_goods_can_come_back_to_a_warehouse_that_did_not_ship_them(): void
    {
        $from = $this->warehouse('Dispatch', 4);
        $to = $this->warehouse('Corner shop', 0);

        $return = $this->askForReturn();

        $this->assertSame(3, $this->stockAt($from), 'the order shipped from the only warehouse holding it');

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/receive', [
                'disposition' => ReturnRequest::RESTOCK,
                'warehouse_id' => $to,
            ])
            ->assertRedirect();

        $this->assertSame(3, $this->stockAt($from), 'the shipper was credited for a parcel it never received');
        $this->assertSame(1, $this->stockAt($to));
        $this->assertSame(4, $this->product->fresh()->stock, 'the aggregate lost the rest of the stock');
        $this->assertSame($to, (int) $return->fresh()->warehouse_id);
    }

    /**
     * A product whose stock is NOT kept per warehouse has no rows to name, so
     * naming one must fall through to the plain column. Creating its first row
     * instead would make that row the whole count — the aggregate is rewritten
     * from the rows, and everything the column said would go.
     */
    public function test_naming_a_warehouse_for_an_unsplit_product_still_adds_to_its_stock(): void
    {
        $to = $this->warehouse('Corner shop', 0, forProduct: false);

        $return = $this->askForReturn();

        $this->assertSame(4, $this->product->fresh()->stock);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/receive', [
                'disposition' => ReturnRequest::RESTOCK,
                'warehouse_id' => $to,
            ])
            ->assertRedirect();

        $this->assertSame(5, $this->product->fresh()->stock);
    }

    // -------------------------------------------------------------- the money

    public function test_a_refund_with_no_gateway_is_recorded_against_the_order(): void
    {
        $return = $this->askForReturn();
        $order = $return->order;

        $this->markPaid($order);
        $this->receive($return);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/refund', ['amount' => '100.00'])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame(PaymentState::REFUNDED, $order->payment_state);
        $this->assertSame(0, (int) $order->paid_total);
        $this->assertSame(100_00, (int) $return->fresh()->refund_amount);
        $this->assertSame(State::COMPLETED, $return->fresh()->state);
    }

    public function test_a_partial_refund_leaves_the_rest_of_the_money_where_it_is(): void
    {
        $return = $this->askForReturn(bought: 2);
        $order = $return->order;

        $this->markPaid($order);
        $this->receive($return);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/refund', ['amount' => '100.00'])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $order->payment_state);
        $this->assertSame(100_00, (int) $order->paid_total);
    }

    /**
     * The shop watches payment in its CRM (which is why "only paid orders" is
     * off by default), so this system holds none of the customer's money. The
     * honest answer is to say so — stamping a refund would invent a payment
     * that never happened.
     */
    public function test_an_order_that_was_never_recorded_as_paid_refuses_the_refund(): void
    {
        $return = $this->askForReturn();
        $this->receive($return);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/refund', ['amount' => '100.00'])
            ->assertSessionHasErrors('amount');

        $return->refresh();

        $this->assertNull($return->refund_amount);
        $this->assertSame(State::RECEIVED, $return->state, 'a refused refund must not complete the return');
    }

    /** More than the shop is holding is refused, never quietly clamped. */
    public function test_refunding_more_than_was_paid_is_refused(): void
    {
        $return = $this->askForReturn();
        $order = $return->order;

        $this->markPaid($order);
        $this->receive($return);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/refund', ['amount' => '500.00'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(PaymentState::PAID, $order->fresh()->payment_state);
    }

    /**
     * With a gateway wired up, the money goes back through it and the payment
     * state is moved by the applier every callback goes through — never stamped
     * twice, once here and once when the gateway says so later.
     */
    public function test_a_refund_goes_through_the_gateway_when_there_is_one(): void
    {
        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        Binding::create([
            'payment_method_id' => $this->method->id,
            'gateway' => 'fake',
            'config' => ['token' => 'sekret'],
            'test_mode' => true,
        ]);

        // An order paid online is placed awaiting payment and holds no stock;
        // the money arriving is what moves it on, which is where a customer who
        // then wants their money back starts from.
        $order = $this->placeOrder();
        app(OrderStatusChanger::class)->change($order, OrderStatus::default());

        $return = $this->askForReturnOn($order->refresh());

        $this->markPaid($order);
        $this->receive($return);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/refund', ['amount' => '100.00'])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame(PaymentState::REFUNDED, $order->payment_state);
        $this->assertDatabaseHas('m_payments_transactions', [
            'order_id' => $order->id,
            'gateway' => 'fake',
            'summary' => 'refunded',
        ]);
    }

    // ----------------------------------------------------------- the exchange

    /**
     * The replacement is a real order placed through PlaceOrder — and it belongs
     * to the CUSTOMER, not to the manager who pressed the button. That is the
     * whole reason PlaceOrder learned who an order is for.
     */
    public function test_an_exchange_places_a_new_order_for_the_customer(): void
    {
        $buyer = User::create(['name' => 'Иван', 'email' => 'buyer@example.test', 'password' => 'secret123']);

        $return = $this->askForReturn(user: $buyer);
        $original = $return->order;

        $this->receive($return);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/exchange', [
                'surcharge' => ReturnRequest::SURCHARGE_NONE,
            ])
            ->assertRedirect();

        $return->refresh();
        $exchange = Order::findOrFail($return->exchange_order_id);

        $this->assertNotSame($original->id, $exchange->id, 'an exchange is a new order, not an edit');
        $this->assertSame($buyer->id, $exchange->user_id, 'the exchange belonged to the manager, not the buyer');
        $this->assertNull($exchange->ip_address, 'the request placing it is the manager’s, not the buyer’s');
        $this->assertSame($original->email, $exchange->email);
        $this->assertSame(ReturnRequest::SURCHARGE_NONE, $return->surcharge);
        $this->assertSame(State::COMPLETED, $return->state);

        // The original is untouched: its total is what the customer, and
        // everything that has already read it, agreed to.
        $this->assertSame((int) $original->total, (int) $original->fresh()->total);
    }

    public function test_an_exchange_order_is_created_only_once(): void
    {
        $return = $this->askForReturn();
        $this->receive($return);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/exchange', ['surcharge' => 'none'])
            ->assertRedirect();

        $before = Order::count();

        // Completed, so the action is not on offer at all.
        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/exchange', ['surcharge' => 'none'])
            ->assertForbidden();

        $this->assertSame($before, Order::count());
    }

    // ------------------------------------------------------- the order status

    public function test_a_completed_return_moves_the_order_only_when_nothing_is_left_to_return(): void
    {
        app(SettingsManager::class)->set('torgash.rma', 'completed_status', $this->returnedStatus()->code);

        // Two units bought, one sent back: the order is not returned.
        $return = $this->askForReturn(bought: 2, qty: 1);
        $order = $return->order;

        $this->markPaid($order);
        $this->receive($return);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/refund', ['amount' => '100.00'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::default()->code, $order->fresh()->status);
    }

    public function test_a_return_of_the_whole_order_moves_its_status(): void
    {
        app(SettingsManager::class)->set('torgash.rma', 'completed_status', $this->returnedStatus()->code);

        $return = $this->askForReturn();
        $order = $return->order;

        $this->markPaid($order);
        $this->receive($return);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/refund', ['amount' => '100.00'])
            ->assertRedirect();

        $this->assertSame('returned', $order->fresh()->status);
    }

    /**
     * The trap this refuses: a status whose inventory_action is "increase" puts
     * the WHOLE order back on the shelf when the order enters it — and the
     * lines are already back, put there by the receipt. One click would credit
     * the same goods twice.
     *
     * The picker does not offer such a status, and this is the second lock: the
     * value was saved months ago and the status may have been edited since.
     */
    public function test_a_status_that_returns_stock_itself_is_never_applied(): void
    {
        app(SettingsManager::class)->set('torgash.rma', 'completed_status', 'cancelled');

        $return = $this->askForReturn();
        $order = $return->order;

        $this->markPaid($order);
        $this->receive($return);

        $this->assertSame(5, $this->product->fresh()->stock);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/refund', ['amount' => '100.00'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::default()->code, $order->fresh()->status);
        $this->assertSame(5, $this->product->fresh()->stock, 'the same unit was credited twice');
    }

    // ------------------------------------------------------------ who may act

    public function test_a_manager_without_the_permission_cannot_act_on_a_return(): void
    {
        $return = $this->askForReturn();

        $staff = User::create(['name' => 'Менеджер', 'email' => 'manager@example.test', 'password' => 'secret123']);
        // Staff, and allowed in the inbox — but answering a question and
        // approving a refund are not the same permission, and a shop can grant
        // the first to everybody.
        $role = Role::findOrCreate('inbox');
        $role->givePermissionTo(Permission::findOrCreate('enquiries.manage'));
        $staff->assignRole($role);

        $this->actingAs($staff)
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/approve')
            ->assertForbidden();

        $this->assertSame(State::REQUESTED, $return->fresh()->state);
    }

    /** The card is drawn on the ticket, and nowhere else: there is no second inbox. */
    public function test_the_return_is_shown_on_its_ticket_in_the_inbox(): void
    {
        $return = $this->askForReturn();

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/enquiries/'.$return->enquiry_id)
            ->assertOk()
            ->assertSee('Return or exchange')
            ->assertSee('Goods received');
    }

    // -------------------------------------------------------------- fixtures

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    /**
     * The status a shop settling returns actually makes: one that says what
     * happened and touches no stock, because this module has already moved it.
     * None of the seeded ones qualify — every "it did not go ahead" status
     * ships with inventory_action "increase" — which is exactly why the setting
     * is empty by default rather than guessing.
     */
    protected function returnedStatus(): OrderStatus
    {
        return OrderStatus::firstOrCreate(['code' => 'returned'], [
            'name' => ['en' => 'Returned'],
            'color' => '#9ca3af',
            'position' => 20,
            'is_default' => false,
            'inventory_action' => 'none',
        ]);
    }

    /**
     * A placed order with a return asked against it, exactly as the storefront
     * form does it — through RequestReturn, because a fixture that inserted the
     * rows itself would test nothing about how they get there.
     */
    protected function askForReturn(int $bought = 1, ?int $qty = null, ?User $user = null): ReturnRequest
    {
        return $this->askForReturnOn($this->placeOrder($bought, $user), $qty ?? $bought);
    }

    protected function askForReturnOn(Order $order, int $qty = 1): ReturnRequest
    {
        $this->post('http://localhost/checkout/'.$order->order_number.'/return', [
            'resolution' => ReturnRequest::REFUND,
            'reason' => 'Не подошёл размер',
            'name' => 'Иван',
            'email' => 'guest@example.test',
            'lines' => [$this->product->id => ['qty' => $qty]],
            // A public POST that writes rows goes through FormGuard, so the
            // form's own fields are part of what a real submission carries.
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ])->assertRedirect();

        return ReturnRequest::latest('id')->firstOrFail();
    }

    protected function placeOrder(int $qty = 1, ?User $user = null): Order
    {
        $this->post('http://localhost/cart/add/returnable-thing', ['qty' => $qty])->assertOk();

        $request = $user ? $this->actingAs($user) : $this;

        $request->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $this->method->id,
        ]);

        return Order::latest('id')->firstOrFail();
    }

    /**
     * A warehouse, optionally holding this product. `forProduct: false` leaves
     * the product unsplit — its stock stays in the aggregate column, which is
     * a different case and not a smaller one.
     */
    protected function warehouse(string $name, int $stock, bool $forProduct = true): int
    {
        $warehouse = Warehouse::create(['name' => ['en' => $name], 'enabled' => true, 'sort' => 0]);

        if ($forProduct) {
            DB::table('m_warehouse_product_stock')->insert([
                'warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'qty' => $stock,
            ]);

            DB::table('m_catalog_products')->where('id', $this->product->id)->update([
                'stock' => DB::table('m_warehouse_product_stock')->where('product_id', $this->product->id)->sum('qty'),
            ]);
        }

        return $warehouse->id;
    }

    protected function stockAt(int $warehouseId): int
    {
        return (int) DB::table('m_warehouse_product_stock')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $this->product->id)
            ->value('qty');
    }

    protected function markPaid(Order $order): void
    {
        app(RecordPayment::class)->record($order, PaymentState::PAID);
    }

    protected function receive(ReturnRequest $return, string $disposition = ReturnRequest::RESTOCK): void
    {
        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/'.$return->id.'/receive', [
                'disposition' => $disposition,
            ])
            ->assertRedirect();
    }
}
