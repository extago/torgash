<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use Torgash\Checkout\Mail\CheckoutLetters;
use Torgash\Checkout\Models\Order;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;

/**
 * The guest's own key to their own order.
 *
 * Until now a guest could see the order they had just placed and nothing after
 * that: the only thing authorising them was a session value, so closing the tab
 * lost the order for ever — no status, no invoice, and no way to ask for
 * anything about it. The order number could not become the key, because it is
 * sequential and TG-000124 is one keystroke from TG-000123. A signed URL is
 * both: unguessable, and good tomorrow.
 */
class OrderKeyTest extends TestCase
{
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-KEY-'.random_int(10000, 99999),
            'status' => 'new',
            'customer_name' => 'Иван',
            'email' => 'buyer@example.com',
            'phone' => '+380671234567',
            'address_line' => 'ул. Тестовая, 1',
            'city' => 'Киев',
            'items' => [[
                'product_id' => 1, 'sku' => 'X', 'name' => 'Товар',
                'qty' => 1, 'unit_price' => 100_00, 'line_total' => 100_00,
            ]],
            'subtotal' => 100_00,
            'total' => 100_00,
            'currency_code' => 'USD',
        ], $overrides));
    }

    public function test_a_stranger_cannot_read_an_order_by_its_number(): void
    {
        $order = $this->order();

        $this->get('http://localhost/checkout/'.$order->order_number.'/confirmation')
            ->assertNotFound();
    }

    public function test_the_signed_link_opens_it_with_no_account_and_no_session(): void
    {
        $order = $this->order();

        $this->get($order->customerUrl())
            ->assertOk()
            ->assertSee($order->order_number);
    }

    /**
     * The key belongs to the ORDER, not to one address.
     *
     * Found by opening the /ru/ copy of a link that worked: a storefront page
     * exists once per locale, and Laravel's own hasValidSignature() hashes the
     * whole address — so the language switcher, which carries the query string
     * across as it must, turned a working link into a 404 the moment somebody
     * changed language.
     */
    public function test_the_key_opens_the_order_in_every_language(): void
    {
        $order = $this->order();
        $key = parse_url($order->customerUrl(), PHP_URL_QUERY);

        $this->get('http://localhost/checkout/'.$order->order_number.'/confirmation?'.$key)->assertOk();
        $this->get('http://localhost/ru/checkout/'.$order->order_number.'/confirmation?'.$key)->assertOk();
    }

    /** A key for the order is not a key for its invoice, and the reverse. */
    public function test_each_page_has_its_own_key(): void
    {
        $order = $this->order();
        $orderKey = parse_url($order->customerUrl(), PHP_URL_QUERY);

        $this->get('http://localhost/checkout/'.$order->order_number.'/invoice?'.$orderKey)->assertNotFound();
    }

    /** A signature for one order is not a signature for the next one. */
    public function test_a_tampered_link_is_refused(): void
    {
        $order = $this->order();
        $other = $this->order();

        $url = str_replace($order->order_number, $other->order_number, $order->customerUrl());

        $this->get($url)->assertNotFound();
    }

    public function test_the_invoice_is_reachable_by_the_same_key(): void
    {
        $order = $this->order();

        $this->get($order->invoiceUrl())->assertOk();
        $this->get('http://localhost/checkout/'.$order->order_number.'/invoice')->assertNotFound();
    }

    /**
     * The key has to reach the customer, or it is not a key. It goes in the
     * confirmation and in every status change — the two mails a shop sends
     * about an order the customer may want to look at again.
     */
    public function test_the_confirmation_email_carries_the_key(): void
    {
        $order = $this->order();

        $this->assertStringContainsString('signature=', \App\Core\Mail\Letters::compose(CheckoutLetters::PLACED, ['order' => $order], 'en')['html']);
    }

    /** An account holder never needed the key and still does not. */
    public function test_the_owner_reads_their_order_without_a_signature(): void
    {
        $user = User::create(['name' => 'Иван', 'email' => 'owner@example.com', 'password' => 'secret123']);
        $order = $this->order(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('http://localhost/checkout/'.$order->order_number.'/confirmation')
            ->assertOk();
    }

    // ------------------------------------------- writing into a thread by key

    protected function enquiry(Order $order): Enquiry
    {
        return Enquiry::create([
            'kind' => 'message',
            'name' => 'Иван',
            'email' => 'buyer@example.com',
            'subject_type' => 'order',
            'subject_id' => $order->id,
        ]);
    }

    public function test_a_signed_link_is_enough_to_answer_the_shop(): void
    {
        Mail::fake();

        $enquiry = $this->enquiry($this->order());

        $this->post(URL::signedRoute('enquiries.signed_reply', $enquiry), ['body' => 'Спасибо, получил.'])
            ->assertRedirect();

        $message = $enquiry->messages()->firstOrFail();

        $this->assertFalse($message->is_staff);
        $this->assertSame('Спасибо, получил.', $message->body);
    }

    public function test_without_the_signature_it_is_refused(): void
    {
        $enquiry = $this->enquiry($this->order());

        $this->post('http://localhost/enquiry/'.$enquiry->id.'/reply', ['body' => 'Чужое.'])
            ->assertForbidden();

        $this->assertSame(0, $enquiry->messages()->count());
    }

    /** A closed thread is closed to every door, signature included. */
    public function test_a_closed_thread_refuses_a_signed_reply(): void
    {
        Mail::fake();

        $enquiry = $this->enquiry($this->order());
        $enquiry->update(['locked_at' => now()]);

        $this->post(URL::signedRoute('enquiries.signed_reply', $enquiry), ['body' => 'Через год.'])
            ->assertForbidden();

        $this->assertSame(0, $enquiry->messages()->count());
    }

    /** The staff reply is still staff's: a signature says which thread, not who. */
    public function test_the_shop_still_answers_as_the_shop(): void
    {
        Mail::fake();

        $enquiry = $this->enquiry($this->order());
        app(PostMessage::class)->fromStaff($enquiry, User::role('admin')->firstOrFail(), 'Ответили.');

        $this->post(URL::signedRoute('enquiries.signed_reply', $enquiry), ['body' => 'И я.'])
            ->assertRedirect();

        $this->assertSame([true, false], $enquiry->messages()->pluck('is_staff')->all());
    }
}
