<?php

namespace Tests\Feature\Rma;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Enquiries\OrderEnquiries;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderKey;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Rma\Kind;
use Torgash\Rma\Models\ReturnRequest;
use Torgash\Rma\State;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * The path the owner described: a customer starts with an ordinary QUESTION
 * about their order, and it ends as a return the shop drew up itself.
 *
 * Two halves, and they were both missing. Asking about an order at all belonged
 * nowhere — the order page only offered a return, so "where is my parcel" had
 * to be filed as one — and the manager had no way to say "this is coming back"
 * without inventing a second ticket and splitting the conversation in half.
 *
 * The seam matters as much as the feature: the conversation on the order page
 * is CHECKOUT's, because checkout owns orders and a shop that takes no returns
 * still has customers asking questions. Returns only adds a button to it.
 */
class TicketToReturnTest extends TestCase
{
    protected Product $product;

    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        RateLimiter::clear('form:enquiry:ip:127.0.0.1');
        RateLimiter::clear('form:enquiry:id:guest@example.test');

        $this->product = Product::create([
            'sku' => 'TTR-1',
            'name' => ['en' => 'A thing that broke'],
            'slug' => 'a-thing-that-broke',
            'price' => 100_00,
            'stock' => 5,
            'status' => 'active',
        ]);

        $this->method = PaymentMethod::create([
            'name' => ['en' => 'Cash to the courier'],
            'enabled' => true,
            'position' => 90,
        ]);
    }

    // ------------------------------------------- asking about an order at all

    public function test_a_customer_can_ask_a_plain_question_about_their_order(): void
    {
        $order = $this->placeOrder();

        $this->post($this->url($order, 'checkout.enquiry'), [
            'question' => 'Когда приедет?',
            'name' => 'Иван',
            'email' => 'guest@example.test',
        ] + $this->guardFields())->assertRedirect();

        $enquiry = Enquiry::latest('id')->firstOrFail();

        $this->assertSame(OrderEnquiries::KIND, $enquiry->kind);
        // From the order the route was addressed with, never from the request:
        // a hand-built form must not file a question against somebody else's.
        $this->assertSame('order', $enquiry->subject_type);
        $this->assertSame($order->id, $enquiry->subject_id);
        $this->assertSame('Когда приедет?', $enquiry->answer('question'));
    }

    /**
     * The panel is checkout's, so it is there with the returns module doing
     * nothing at all — and the inbox can still say what the ticket is about.
     */
    public function test_the_order_page_offers_the_question_form_and_names_the_order_in_the_inbox(): void
    {
        $order = $this->placeOrder();

        $this->get(OrderKey::url($order, 'checkout.confirmation'))
            ->assertOk()
            ->assertSee('Ask about this order');

        $this->post($this->url($order, 'checkout.enquiry'), [
            'question' => 'Where is it?',
            'email' => 'guest@example.test',
        ] + $this->guardFields())->assertRedirect();

        $subject = Enquiry::latest('id')->firstOrFail()->subject();

        $this->assertSame('Order '.$order->order_number, $subject['label']);
    }

    /** Somebody else's order is not readable and not askable-about. */
    public function test_a_question_cannot_be_asked_without_the_order_key(): void
    {
        $order = $this->placeOrder();

        $this->flushSession();

        $this->post('http://localhost/checkout/'.$order->order_number.'/enquiry', [
            'question' => 'Hello',
            'email' => 'stranger@example.test',
        ] + $this->guardFields())->assertNotFound();

        $this->assertSame(0, Enquiry::count());
    }

    // ------------------------------------ and the shop turning it into a return

    public function test_a_manager_turns_a_question_into_a_return_on_the_same_ticket(): void
    {
        $order = $this->placeOrder(2);
        $enquiry = $this->askAbout($order, 'Пришло сломанное.');

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/for/'.$enquiry->id, [
                'order_number' => $order->order_number,
                'resolution' => ReturnRequest::EXCHANGE,
                'lines' => [$this->product->id => ['qty' => 1]],
                'note' => 'Оформили возврат, пришлите на отделение №5.',
            ])
            ->assertRedirect();

        $return = ReturnRequest::latest('id')->firstOrFail();
        $enquiry->refresh();

        $this->assertSame($enquiry->id, $return->enquiry_id);
        $this->assertSame($order->id, $return->order_id);
        $this->assertSame(ReturnRequest::EXCHANGE, $return->resolution);
        $this->assertSame(State::REQUESTED, $return->state);
        $this->assertSame(1, (int) $return->lines[0]['qty']);
        // The price comes from the ORDER's frozen line, not from the form.
        $this->assertSame(100_00, (int) $return->lines[0]['unit_price']);

        // The ticket became a return, so the inbox's own filter finds it...
        $this->assertSame(Kind::RETURN, $enquiry->kind);
        // ...and not one word of what the person wrote was disturbed.
        $this->assertSame('Пришло сломанное.', $enquiry->answer('question'));
        // The customer is told in the thread they are already in.
        $this->assertSame(
            'Оформили возврат, пришлите на отделение №5.',
            $enquiry->messages()->latest('id')->value('body'),
        );
    }

    /**
     * A ticket from the general contact form is about nothing in particular
     * until a manager says which order it is about.
     */
    public function test_a_return_can_be_drawn_up_on_a_ticket_that_named_no_order(): void
    {
        $order = $this->placeOrder();

        $enquiry = Enquiry::create([
            'kind' => 'message',
            'name' => 'Иван',
            'email' => 'guest@example.test',
            'fields' => ['values' => ['message' => 'Товар не работает'], 'labels' => ['message' => 'Message']],
        ]);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/for/'.$enquiry->id, [
                'order_number' => $order->order_number,
                'resolution' => ReturnRequest::REFUND,
                'lines' => [$this->product->id => ['qty' => 1]],
            ])
            ->assertRedirect();

        $enquiry->refresh();

        $this->assertSame('order', $enquiry->subject_type);
        $this->assertSame($order->id, $enquiry->subject_id);
        $this->assertSame(1, ReturnRequest::where('enquiry_id', $enquiry->id)->count());
    }

    /**
     * The shop's OFFER rules gate the customer's button, not the manager. A
     * shop that ticks no statuses offers nobody a return — and its staff can
     * still take something back, which is what staff are for.
     */
    public function test_the_shops_offer_rules_do_not_bind_the_manager(): void
    {
        app(SettingsManager::class)->set('torgash.rma', 'statuses', []);
        app(SettingsManager::class)->set('torgash.rma', 'days', 1);

        $order = $this->placeOrder();
        $order->update(['created_at' => now()->subYear()]);

        $enquiry = $this->askAbout($order, 'Сломалось через год.');

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/for/'.$enquiry->id, [
                'order_number' => $order->order_number,
                'resolution' => ReturnRequest::REFUND,
                'lines' => [$this->product->id => ['qty' => 1]],
            ])
            ->assertRedirect();

        $this->assertSame(1, ReturnRequest::where('enquiry_id', $enquiry->id)->count());
    }

    /** What DOES bind is the arithmetic: the same unit cannot come back twice. */
    public function test_a_manager_cannot_return_more_than_is_left_of_the_order(): void
    {
        $order = $this->placeOrder(1);
        $first = $this->askAbout($order, 'Одно сломалось.');

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/for/'.$first->id, [
                'order_number' => $order->order_number,
                'resolution' => ReturnRequest::REFUND,
                'lines' => [$this->product->id => ['qty' => 5]],
            ])
            ->assertRedirect();

        $this->assertSame(1, (int) ReturnRequest::latest('id')->firstOrFail()->lines[0]['qty']);

        // Nothing is left, so a second ticket cannot claim it again.
        $second = $this->askAbout($order, 'И это тоже.');

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/for/'.$second->id, [
                'order_number' => $order->order_number,
                'resolution' => ReturnRequest::REFUND,
                'lines' => [$this->product->id => ['qty' => 1]],
            ])
            ->assertSessionHasErrors('enquiry');

        $this->assertSame(1, ReturnRequest::count());
    }

    public function test_a_ticket_takes_only_one_return(): void
    {
        $order = $this->placeOrder(2);
        $enquiry = $this->askAbout($order, 'Сломалось.');

        foreach ([1, 1] as $qty) {
            $response = $this->actingAs($this->admin())
                ->post('http://localhost/admin/enquiries/returns/for/'.$enquiry->id, [
                    'order_number' => $order->order_number,
                    'resolution' => ReturnRequest::REFUND,
                    'lines' => [$this->product->id => ['qty' => $qty]],
                ]);
        }

        $response->assertSessionHasErrors('lines');
        $this->assertSame(1, ReturnRequest::where('enquiry_id', $enquiry->id)->count());
    }

    public function test_an_unknown_order_number_is_refused(): void
    {
        $order = $this->placeOrder();
        $enquiry = $this->askAbout($order, 'Вопрос.');

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/returns/for/'.$enquiry->id, [
                'order_number' => 'TG-999999',
                'resolution' => ReturnRequest::REFUND,
                'lines' => [$this->product->id => ['qty' => 1]],
            ])
            ->assertSessionHasErrors('order_number');

        $this->assertSame(0, ReturnRequest::count());
    }

    /** Answering tickets and taking goods back are different permissions. */
    public function test_the_form_is_not_offered_without_the_returns_permission(): void
    {
        $order = $this->placeOrder();
        $enquiry = $this->askAbout($order, 'Вопрос.');

        $staff = User::create(['name' => 'Менеджер', 'email' => 'inbox@example.test', 'password' => 'secret123']);
        $role = Role::findOrCreate('inbox');
        $role->givePermissionTo(Permission::findOrCreate('enquiries.manage'));
        $staff->assignRole($role);

        $this->actingAs($staff)
            ->get('http://localhost/admin/enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertDontSee('Draw up the return');

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertSee('Draw up the return');
    }

    // -------------------------------------------------------------- fixtures

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    /** @return array<string, string> */
    protected function guardFields(): array
    {
        return [
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ];
    }

    protected function url(Order $order, string $route): string
    {
        return OrderKey::url($order, $route);
    }

    protected function askAbout(Order $order, string $question): Enquiry
    {
        RateLimiter::clear('form:enquiry:ip:127.0.0.1');
        RateLimiter::clear('form:enquiry:id:guest@example.test');

        $this->post($this->url($order, 'checkout.enquiry'), [
            'question' => $question,
            'name' => 'Иван',
            'email' => 'guest@example.test',
        ] + $this->guardFields())->assertRedirect();

        return Enquiry::latest('id')->firstOrFail();
    }

    protected function placeOrder(int $qty = 1): Order
    {
        $this->post('http://localhost/cart/add/a-thing-that-broke', ['qty' => $qty])->assertOk();

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $this->method->id,
        ]);

        $order = Order::latest('id')->firstOrFail();

        // The ordinary case for a return: the order is in the shop's default
        // status, which is what OrderStatus::default() answers.
        $this->assertSame(OrderStatus::default()->code, $order->status);

        return $order;
    }
}
