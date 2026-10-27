<?php

namespace Tests\Feature\Checkout;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;
use App\Core\Mail\EventMail;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ZoneMatcher;

/**
 * Buying with a phone number and nothing else.
 *
 * The point of every test here is that it is an ORDINARY ORDER: the same
 * PlaceOrder, the same stock, the same statuses. A typical one-click is a "call
 * request" with an order attached and an invented email address; ours has no
 * email at all and says so.
 */
class OneClickTest extends TestCase
{
    protected Product $product;

    protected ShippingMethod $shipping;

    protected PaymentMethod $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('form:one_click:ip:127.0.0.1');

        $this->product = Product::create([
            'sku' => 'ONE-1', 'name' => ['en' => 'Bought in one click'], 'slug' => 'bought-in-one-click',
            'price' => 250_00, 'stock' => 5, 'status' => 'active',
        ]);

        // The methods a shop nominates for this: real ones, priced at nothing,
        // whose whole meaning is "we will agree it when we call you".
        $this->shipping = ShippingMethod::create([
            'name' => ['en' => 'Arrange with manager'], 'price_mode' => 'quote',
            'position' => 90, 'enabled' => true,
        ]);
        $this->shipping->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true,
        ]);

        $this->payment = PaymentMethod::create([
            'name' => ['en' => 'Agree with the manager'], 'enabled' => true, 'position' => 90,
        ]);

        $this->configure();
    }

    protected function configure(bool $on = true, bool $nominate = true): void
    {
        $settings = app(SettingsManager::class);
        $settings->set('torgash.checkout', 'one_click', $on);
        $settings->set('torgash.checkout', 'one_click_shipping_id', $nominate ? (string) $this->shipping->id : '');
        $settings->set('torgash.checkout', 'one_click_payment_id', $nominate ? $this->payment->id : 0);
    }

    /** @param array<string, mixed> $overrides */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'customer_name' => 'Пётр',
            'phone' => '+380 (67) 123-45-67',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ], $overrides);
    }

    public function test_a_phone_number_places_a_real_order(): void
    {
        $this->post('http://localhost/one-click', $this->payload())->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame('+380 (67) 123-45-67', $order->phone);
        $this->assertSame(250_00, $order->total);
        $this->assertSame($this->payment->id, $order->payment_method_id);
        $this->assertSame('Arrange with manager', $order->shipping_method_name);

        // An ordinary order in every other way: stock moved, and the buyer is
        // the same counterparty a checkout order from that phone would be.
        $this->assertSame(4, $this->product->fresh()->stock);
        $this->assertNotNull($order->customer_ref);
    }

    /**
     * No name typed is stored as none — never as words in the buyer's language,
     * which a manager reading in another one then saw on every screen. The
     * words are the reader's, drawn when shown.
     */
    public function test_an_order_with_no_name_stores_none_and_says_so_in_the_readers_language(): void
    {
        $this->post('http://localhost/one-click', $this->payload(['customer_name' => '']))->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        $this->assertNull($order->customer_name);
        $this->assertSame('Ordered by phone', $order->customerLabel());

        app()->setLocale('ru');
        $this->assertSame('Заказ по телефону', $order->customerLabel());
    }

    /**
     * Where an order came from is written down at placement — the one moment
     * it is known — and the card counts the others from there, with the way to
     * the guard's block when the guard is installed.
     */
    public function test_the_address_an_order_came_from_is_kept_and_shown(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->post('http://localhost/one-click', $this->payload())->assertRedirect();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->post('http://localhost/one-click', $this->payload(['customer_name' => 'Somebody else']))->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('203.0.113.50', $order->ip_address);

        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/orders/'.$order->order_number)
            ->assertOk()
            ->assertSee('203.0.113.50')
            ->assertSee('1 more order from here')
            ->assertSee('admin/guard/blocks?block=203.0.113.50', false);

        $this->actingAs($admin)->get('http://localhost/admin/orders?q=203.0.113.50')
            ->assertOk()
            ->assertSee($order->order_number);
    }

    /** The browser and its languages ride the same capture, and the card reads them back. */
    public function test_the_browser_is_written_down_and_shown(): void
    {
        $this->withHeaders(['User-Agent' => 'TestBrowser/1.0 (Test OS)', 'Accept-Language' => 'uk-UA,uk;q=0.9,en;q=0.8'])
            ->post('http://localhost/one-click', $this->payload())->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('TestBrowser/1.0 (Test OS)', $order->client['user_agent']);
        $this->assertSame('uk-UA,uk;q=0.9,en;q=0.8', $order->client['languages']);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/orders/'.$order->order_number)
            ->assertOk()
            ->assertSee('TestBrowser/1.0 (Test OS)')
            ->assertSee('uk-UA, uk, en');
    }

    /** Unticked means not recorded — the address included. */
    public function test_what_the_shop_unticks_is_not_recorded(): void
    {
        app(SettingsManager::class)->set('torgash.checkout', 'client_facts', ['languages']);

        $this->withHeaders(['User-Agent' => 'TestBrowser/1.0', 'Accept-Language' => 'en'])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])
            ->post('http://localhost/one-click', $this->payload())->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertNull($order->ip_address);
        $this->assertSame(['languages' => 'en'], $order->client);
    }

    /**
     * The thing older engines invent an address for. NULL means "not given": the
     * confirmation email is not sent rather than sent nowhere, and nothing in
     * the shop has to pretend a made-up mailbox exists.
     */
    public function test_no_email_is_invented_and_none_is_sent(): void
    {
        $this->post('http://localhost/one-click', $this->payload());

        $this->assertNull(Order::latest('id')->firstOrFail()->email);
        Mail::assertNotQueued(EventMail::class);
    }

    /**
     * A visitor who already has a basket clicked "buy this one". Ordering what
     * they had put aside — or emptying it — is not what they asked for.
     */
    public function test_the_visitors_own_basket_is_neither_ordered_nor_emptied(): void
    {
        $other = Product::create([
            'sku' => 'ONE-2', 'name' => ['en' => 'Already in the basket'], 'slug' => 'already-in-the-basket',
            'price' => 30_00, 'stock' => 5, 'status' => 'active',
        ]);

        $this->post('http://localhost/cart/add/'.$other->slug, ['qty' => 2]);
        $carts = Cart::count();

        $this->post('http://localhost/one-click', $this->payload());

        $order = Order::latest('id')->firstOrFail();
        $this->assertCount(1, $order->items);
        $this->assertSame($this->product->id, $order->items[0]['product_id']);

        // ...and the throwaway cart it was placed from is gone again.
        $this->assertSame($carts, Cart::count());
        // Their own basket, untouched — read as lines, which is what a cart
        // holds since PLAN §33.3.
        $theirs = collect(Cart::latest('id')->first()->items)->firstWhere('product_id', $other->id);
        $this->assertSame(2, (int) ($theirs['qty'] ?? 0));
    }

    public function test_it_is_not_offered_until_the_shop_says_which_methods_it_uses(): void
    {
        $this->configure(on: true, nominate: false);

        $this->post('http://localhost/one-click', $this->payload())->assertNotFound();
        $this->assertSame(0, Order::count());
    }

    public function test_it_is_not_offered_when_switched_off(): void
    {
        $this->configure(on: false);

        $this->post('http://localhost/one-click', $this->payload())->assertNotFound();
    }

    /**
     * The nominated method must really be on offer for a cart with no address.
     * PlaceOrder is not relaxed for this — a hole there would be a hole for the
     * API too — so a shop whose method has no rate covering an unknown address
     * is told to use the full checkout.
     */
    public function test_a_nominated_method_with_no_rate_refuses_rather_than_placing_anything(): void
    {
        $this->shipping->rates()->delete();

        $this->post('http://localhost/one-click', $this->payload())
            ->assertSessionHasErrors('one_click');

        $this->assertSame(0, Order::count());
        $this->assertSame(5, $this->product->fresh()->stock);
    }

    public function test_the_form_is_guarded_like_every_other_public_one(): void
    {
        $this->post('http://localhost/one-click', $this->payload([FormGuard::TRAP => 'spam']))
            ->assertRedirect();

        $this->assertSame(0, Order::count(), 'a trapped bot placed an order');
    }

    public function test_a_form_submitted_instantly_places_nothing(): void
    {
        $this->post('http://localhost/one-click', $this->payload([
            FormGuard::STAMP => Crypt::encryptString((string) time()),
        ]))->assertSessionHasErrors('one_click');

        $this->assertSame(0, Order::count());
    }

    /**
     * A refusal has to be VISIBLE. The form swaps itself for the answer, and
     * HTMX drops a 4xx on the floor — so the honest 422 meant the customer
     * pressed the button and watched nothing at all happen. The answer is the
     * form again, with the message and with what they typed still in it.
     */
    public function test_a_refused_order_answers_htmx_with_the_form_and_the_reason(): void
    {
        $this->shipping->rates()->delete();

        $response = $this->withHeaders(['HX-Request' => 'true'])
            ->post('http://localhost/one-click', $this->payload(['customer_name' => 'Иван']));

        $response->assertOk();
        $response->assertSee('We cannot take this order without an address', false);
        // Still a form, so HTMX has something to swap in — and the phone
        // number survived the refusal.
        $response->assertSee('<form', false);
        $response->assertSee('value="Иван"', false);
        $response->assertSee($this->payload()['phone'], false);

        $this->assertSame(0, Order::count());
    }

    /**
     * One click orders the build chosen in the buy box: it used to put the
     * bare product in its throwaway cart, so a chosen option was dropped from
     * the order and from its price.
     */
    public function test_the_options_chosen_in_the_buy_box_are_ordered(): void
    {
        $group = \Torgash\Options\Models\OptionGroup::create([
            'product_id' => $this->product->id, 'admin_name' => 'oc-warranty',
            'name' => ['en' => 'Warranty'], 'type' => 'select', 'required' => false, 'enabled' => true,
        ]);
        $choice = \Torgash\Options\Models\OptionChoice::create([
            'group_id' => $group->id, 'kind' => 'plain', 'name' => ['en' => '+1 year'],
            'price_rule' => 'fixed', 'amount' => 30_00, 'enabled' => true,
        ]);
        app(\Torgash\Options\Groups::class)->flush();

        $this->post('http://localhost/one-click', $this->payload([
            'qty' => 2,
            'config' => ['options' => [$group->id => (string) $choice->id]],
        ]))->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(2 * (250_00 + 30_00), $order->total);
    }

    /** A required question left unanswered is refused, and nothing is ordered. */
    public function test_an_unanswered_required_option_refuses_the_order(): void
    {
        $group = \Torgash\Options\Models\OptionGroup::create([
            'product_id' => $this->product->id, 'admin_name' => 'oc-cpu',
            'name' => ['en' => 'Processor'], 'type' => 'select', 'required' => true, 'enabled' => true,
        ]);
        \Torgash\Options\Models\OptionChoice::create([
            'group_id' => $group->id, 'kind' => 'plain', 'name' => ['en' => 'Xeon'], 'enabled' => true,
        ]);
        app(\Torgash\Options\Groups::class)->flush();
        $carts = Cart::count();

        $this->withHeaders(['HX-Request' => 'true'])
            ->post('http://localhost/one-click', $this->payload(['buy_form' => 'buy-box-'.$this->product->id.'-form']))
            ->assertOk()
            ->assertSee('Processor')
            // The re-drawn form still takes the buy box's answers along.
            ->assertSee('hx-include="#buy-box-'.$this->product->id.'-form [name^=', false);

        $this->assertSame(0, Order::count());
        // The throwaway cart went with the refusal.
        $this->assertSame($carts, Cart::count());
    }

    public function test_the_button_appears_on_the_product_page_once_it_is_set_up(): void
    {
        $this->get('http://localhost/product/'.$this->product->slug)
            ->assertOk()
            ->assertSee('Buy in one click');

        $this->configure(on: false);

        $this->get('http://localhost/product/'.$this->product->slug)
            ->assertOk()
            ->assertDontSee('Buy in one click');
    }

    /** Never on what cannot be bought — the order would be refused after the number was typed. */
    public function test_it_is_not_offered_on_a_product_that_cannot_be_bought(): void
    {
        $this->product->update(['stock' => 0, 'availability_status' => 'out_of_stock']);

        $this->get('http://localhost/product/'.$this->product->slug)
            ->assertOk()
            ->assertDontSee('Buy in one click');
    }

    /** The shop's ticked statuses narrow it: a preorder may be left to the ordinary basket. */
    public function test_ticked_statuses_decide_where_it_is_offered(): void
    {
        $preorder = Product::create([
            'sku' => 'ONE-PRE', 'name' => ['en' => 'Sold ahead'], 'slug' => 'sold-ahead',
            'price' => 250_00, 'stock' => 0, 'status' => 'active', 'availability_status' => 'preorder',
        ]);
        app(SettingsManager::class)->set('torgash.checkout', 'one_click_statuses', ['in_stock']);

        $this->get('http://localhost/product/'.$this->product->slug)->assertOk()->assertSee('Buy in one click');
        $this->get('http://localhost/product/'.$preorder->slug)->assertOk()->assertDontSee('Buy in one click');

        app(SettingsManager::class)->set('torgash.checkout', 'one_click_statuses', []);
        $this->get('http://localhost/product/'.$preorder->slug)->assertOk()->assertSee('Buy in one click');
    }

    /**
     * The whole basket from the cart page (`one_click_cart`): on the cart page
     * the customer asked for THE BASKET, so the basket itself is ordered and
     * emptied — the other way round from a product page, on purpose.
     */
    public function test_the_whole_cart_is_ordered_from_the_cart_page_and_emptied(): void
    {
        $other = Product::create([
            'sku' => 'ONE-3', 'name' => ['en' => 'Second line'], 'slug' => 'second-line',
            'price' => 30_00, 'stock' => 5, 'status' => 'active',
        ]);
        app(SettingsManager::class)->set('torgash.checkout', 'one_click_cart', true);

        $this->post('http://localhost/cart/add/'.$this->product->slug, ['qty' => 1]);
        $this->post('http://localhost/cart/add/'.$other->slug, ['qty' => 2]);
        $this->get('http://localhost/cart')->assertOk()->assertSee('Buy in one click');

        $payload = $this->payload(['scope' => 'cart']);
        unset($payload['product_id']);

        $response = $this->post('http://localhost/one-click', $payload, ['HX-Request' => 'true']);

        $order = Order::latest('id')->firstOrFail();
        $response->assertHeader('HX-Redirect', route('checkout.confirmation', $order->order_number));
        $this->assertCount(2, $order->items);
        $this->assertSame(250_00 + 2 * 30_00, $order->total);
        $this->assertSame(3, $other->fresh()->stock);

        $this->get('http://localhost/cart')->assertSee(__('Your cart is empty.'));
    }

    /** Its own switch: the product-page one being on does not put it on the cart page. */
    public function test_the_whole_cart_needs_its_own_switch(): void
    {
        app(SettingsManager::class)->set('torgash.checkout', 'one_click_cart', false);
        $this->post('http://localhost/cart/add/'.$this->product->slug, ['qty' => 1]);

        $this->get('http://localhost/cart')->assertOk()->assertDontSee('Buy in one click');

        $payload = $this->payload(['scope' => 'cart']);
        unset($payload['product_id']);
        $this->post('http://localhost/one-click', $payload)->assertNotFound();
        $this->assertSame(0, Order::count());
    }

    public function test_the_shop_can_hold_a_one_click_order_until_the_phone_is_confirmed(): void
    {
        $sent = $this->confirmByCode('one_click');

        $this->post('http://localhost/one-click', $this->payload())->assertSessionHasErrors('phone');
        $this->assertSame(0, Order::count());

        $cookie = $this->confirmedCookie('one_click', '+380 (67) 123-45-67', $sent);

        $this->withCookie(\App\Core\Verification\FormVerification::COOKIE, $cookie)
            ->post('http://localhost/one-click', $this->payload())->assertRedirect();
        $this->assertSame(1, Order::count());
    }

    public function test_the_shop_can_offer_one_click_to_guests_only(): void
    {
        app(SettingsManager::class)->set('torgash.checkout', 'one_click_audience', 'guests');

        $this->actingAs(User::factory()->create())
            ->post('http://localhost/one-click', $this->payload())->assertNotFound();
        $this->assertSame(0, Order::count());

        $this->post('http://localhost/account/logout');
        $this->post('http://localhost/one-click', $this->payload())->assertRedirect();
        $this->assertSame(1, Order::count());
    }

    /**
     * PLAN §44: with an SMS channel and the form ticked, the contact must be
     * confirmed by a code first — and then the very same submission goes.
     * Returns the list the test channel fills with every code it sends.
     */
    protected function confirmByCode(string $form): \ArrayObject
    {
        $sent = new \ArrayObject;
        $channel = new class($sent) implements \App\Core\Verification\Channel
        {
            public function __construct(private \ArrayObject $sent) {}

            public function label(): string { return 'SMS'; }

            public function kinds(): array { return ['phone']; }

            public function available(): bool { return true; }

            public function supports(string $kind, string $address): bool { return true; }

            public function send(string $kind, string $address, array $message): \App\Core\Verification\SendResult
            {
                $this->sent[] = $message['code'];

                return \App\Core\Verification\SendResult::sent();
            }
        };
        hook_listen('core.verification.channels', fn (array $all) => $all + ['sms' => $channel]);
        app(SettingsManager::class)->set('core', 'verify_forms', [$form]);

        return $sent;
    }

    /** Confirms `$phone` for `$form` and hands back the browser's cookie. */
    protected function confirmedCookie(string $form, string $phone, \ArrayObject $sent): string
    {
        $this->post('http://localhost/verify-contact', ['verify_form' => $form, 'phone' => $phone])->assertOk();

        return $this->post('http://localhost/verify-contact/check', ['verify_form' => $form, 'phone' => $phone, 'verify_code' => $sent[count($sent) - 1]])
            ->getCookie(\App\Core\Verification\FormVerification::COOKIE)->getValue();
    }
}
