<?php

namespace Tests\Feature\Enquiries;

use App\Models\User;
use Tests\TestCase;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;
use Torgash\Enquiries\Status;

/**
 * The customer's half of a ticket: their own threads, in their own account.
 *
 * What is pinned here is mostly who may READ what. A conversation is the one
 * thing in a shop that is neither public nor staff-only, and every cheap way of
 * deciding "is this yours" — the email on the row, a token in a link, the
 * customer_ref — hands somebody else's questions to whoever guesses right.
 */
class EnquiryAccountTest extends TestCase
{
    protected function customer(string $email = 'buyer@example.com'): User
    {
        return User::create(['name' => 'Иван', 'email' => $email, 'password' => 'secret123']);
    }

    protected function enquiry(array $overrides = []): Enquiry
    {
        return Enquiry::create(array_merge([
            'kind' => 'callback',
            'name' => 'Иван',
            'phone' => '+380671234567',
        ], $overrides));
    }

    public function test_the_account_lists_only_this_persons_enquiries(): void
    {
        $me = $this->customer();
        $somebodyElse = $this->customer('other@example.com');

        $mine = $this->enquiry(['user_id' => $me->id]);
        $theirs = $this->enquiry(['user_id' => $somebodyElse->id]);

        // By the LINK, not by a name: the customer's own list has no reason to
        // print the name they gave, so asserting on it would pass either way.
        $this->actingAs($me)
            ->get('http://localhost/account/enquiries')
            ->assertOk()
            ->assertSee('/account/enquiries/'.$mine->id.'"', false)
            ->assertDontSee('/account/enquiries/'.$theirs->id.'"', false);
    }

    /**
     * An email address is not proof of identity (§29.3/1). A guest enquiry left
     * under the address somebody registers with afterwards stays a guest
     * enquiry — stitching them together by email would hand one person's
     * conversation to whoever signs up with their address.
     */
    public function test_a_guest_enquiry_is_not_claimed_by_an_account_with_the_same_email(): void
    {
        $me = $this->customer('guest-then-account@example.com');
        $guest = $this->enquiry(['email' => 'guest-then-account@example.com']);

        $this->actingAs($me)
            ->get('http://localhost/account/enquiries')
            ->assertOk()
            ->assertDontSee('/account/enquiries/'.$guest->id.'"', false);

        $this->actingAs($me)
            ->get('http://localhost/account/enquiries/'.$guest->id)
            ->assertNotFound();
    }

    /** 404, not 403: a thread that is not theirs must not be distinguishable from one that is not there. */
    public function test_somebody_elses_thread_is_not_found(): void
    {
        $theirs = $this->enquiry(['user_id' => $this->customer('owner@example.com')->id]);

        $this->actingAs($this->customer())
            ->get('http://localhost/account/enquiries/'.$theirs->id)
            ->assertNotFound();

        $this->actingAs($this->customer('third@example.com'))
            ->post('http://localhost/account/enquiries/'.$theirs->id.'/messages', ['body' => 'Читаю чужое.'])
            ->assertNotFound();

        $this->assertSame(0, $theirs->messages()->count());
    }

    public function test_the_customer_can_write_into_their_own_thread(): void
    {
        $me = $this->customer();
        $enquiry = $this->enquiry(['user_id' => $me->id, 'status' => Status::DONE]);

        $this->actingAs($me)
            ->post('http://localhost/account/enquiries/'.$enquiry->id.'/messages', [
                'body' => 'А доставка входит в цену?',
            ])
            ->assertRedirect();

        $message = $enquiry->messages()->firstOrFail();

        $this->assertFalse($message->is_staff);
        $this->assertSame($me->id, $message->user_id);
        $this->assertSame(Status::IN_PROGRESS, $enquiry->refresh()->status, 'the answer to an answer went nowhere');
    }

    /**
     * Rejected says "we are not answering this". A row that can talk its way
     * back into the inbox cannot say it, so the form is not drawn and a POST
     * made by hand is refused out loud rather than swallowed.
     */
    public function test_a_rejected_thread_takes_no_more_messages(): void
    {
        $me = $this->customer();
        $enquiry = $this->enquiry(['user_id' => $me->id, 'status' => Status::REJECTED]);

        $this->actingAs($me)
            ->get('http://localhost/account/enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertSee(__('This conversation is closed.'));

        $this->actingAs($me)
            ->post('http://localhost/account/enquiries/'.$enquiry->id.'/messages', ['body' => 'И всё-таки.'])
            ->assertForbidden();

        $this->assertSame(0, $enquiry->messages()->count());
    }

    /**
     * The four inbox states are the shop talking to itself. "Rejected" is a
     * decision about the enquiry, not something to print at the person who
     * wrote it.
     */
    public function test_the_customer_is_not_shown_the_inboxs_own_vocabulary(): void
    {
        $me = $this->customer();
        $enquiry = $this->enquiry(['user_id' => $me->id, 'status' => Status::REJECTED]);

        $this->actingAs($me)
            ->get('http://localhost/account/enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertSee(__('Closed'))
            ->assertDontSee(Status::label(Status::REJECTED));
    }

    public function test_the_thread_shows_both_sides(): void
    {
        $me = $this->customer();
        $enquiry = $this->enquiry(['user_id' => $me->id]);

        app(PostMessage::class)->fromStaff($enquiry, User::role('admin')->firstOrFail(), 'Перезвоним завтра.');
        app(PostMessage::class)->fromCustomer($enquiry, 'Спасибо, жду.', $me);

        $this->actingAs($me)
            ->get('http://localhost/account/enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertSee('Перезвоним завтра.')
            ->assertSee('Спасибо, жду.')
            ->assertSee(__('The shop'));
    }

    /**
     * The menu item is the module's, answered into core's list — core never
     * learns this module's name, and the item goes when the module does.
     */
    public function test_the_account_menu_gets_an_item_from_the_module(): void
    {
        $this->actingAs($this->customer())
            ->get('http://localhost/account')
            ->assertOk()
            ->assertSee('/account/enquiries');
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $enquiry = $this->enquiry(['user_id' => $this->customer()->id]);

        $this->get('http://localhost/account/enquiries')->assertRedirect();
        $this->get('http://localhost/account/enquiries/'.$enquiry->id)->assertRedirect();
        $this->post('http://localhost/account/enquiries/'.$enquiry->id.'/messages', ['body' => 'Hi'])->assertRedirect();

        $this->assertSame(0, $enquiry->messages()->count());
    }
}
