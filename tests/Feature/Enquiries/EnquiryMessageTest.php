<?php

namespace Tests\Feature\Enquiries;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Message;
use Torgash\Enquiries\PostMessage;
use Torgash\Enquiries\Status;

/**
 * The conversation that turns an enquiry into a ticket.
 *
 * What is pinned here is mostly what a reply MEANS beyond the row it writes:
 * the wait is over, somebody owns this, and the other side is told. Each of
 * those lived in a controller in every shop engine we looked at, and each of
 * them was then forgotten by the second caller.
 */
class EnquiryMessageTest extends TestCase
{
    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    protected function enquiry(array $overrides = []): Enquiry
    {
        return Enquiry::create(array_merge([
            'kind' => 'callback',
            'name' => 'Иван',
            'phone' => '+380671234567',
        ], $overrides));
    }

    public function test_a_reply_is_stored_and_ends_the_wait(): void
    {
        $admin = $this->admin();
        $enquiry = $this->enquiry();

        $this->actingAs($admin)
            ->post('http://localhost/admin/enquiries/'.$enquiry->id.'/messages', [
                'body' => 'Перезвоним завтра до полудня.',
            ])
            ->assertRedirect();

        $message = $enquiry->messages()->firstOrFail();

        $this->assertTrue($message->is_staff);
        $this->assertSame($admin->id, $message->user_id);
        $this->assertSame('Перезвоним завтра до полудня.', $message->body);

        $enquiry->refresh();

        $this->assertNotNull($enquiry->answered_at, 'the reply did not end the wait');
        $this->assertSame(Status::IN_PROGRESS, $enquiry->status, 'an answered enquiry still counted as untouched');
        $this->assertSame($admin->id, $enquiry->manager_id, 'nobody owns an enquiry somebody has answered');
    }

    /**
     * The first message is what the person typed, and it lives on the enquiry
     * itself — copying it into a message row would be two sources of truth
     * about the same words.
     */
    public function test_an_unanswered_enquiry_has_no_messages_at_all(): void
    {
        $this->assertSame(0, $this->enquiry()->messages()->count());
    }

    public function test_the_wait_is_measured_once_and_a_later_reply_does_not_move_it(): void
    {
        $admin = $this->admin();
        $enquiry = $this->enquiry();

        app(PostMessage::class)->fromStaff($enquiry, $admin, 'Первый ответ.');
        $first = $enquiry->refresh()->answered_at;

        $this->travel(2)->hours();

        app(PostMessage::class)->fromStaff($enquiry, $admin, 'И ещё одно.');

        $this->assertEquals($first, $enquiry->refresh()->answered_at);
        $this->assertSame(2, $enquiry->messages()->count());
    }

    /**
     * A rule notifying the customer runs off this hook, so what it is handed
     * must be the state the reply LEFT the enquiry in — not the half of it
     * that was true one line earlier.
     */
    public function test_a_message_announces_itself_with_the_enquiry_already_saved(): void
    {
        $seen = null;
        hook_listen('enquiries.message.created', function (array $context) use (&$seen) {
            $seen = [
                'body' => $context['message']->body,
                'staff' => $context['message']->is_staff,
                'status' => $context['enquiry']->fresh()->status,
            ];
        });

        app(PostMessage::class)->fromStaff($this->enquiry(), $this->admin(), 'Готово.');

        $this->assertNotNull($seen, 'nothing was announced, so nobody could ever be told');
        $this->assertSame('Готово.', $seen['body']);
        $this->assertTrue($seen['staff']);
        $this->assertSame(Status::IN_PROGRESS, $seen['status'], 'a listener saw the enquiry mid-update');
    }

    /**
     * An answer that gets an answer is a question nobody has read yet, so a
     * finished thread goes back into the queue.
     *
     * Only a finished one. A rejected thread is CLOSED, and a closed thread
     * takes no messages at all — see EnquiryLockTest; here it is enough that
     * the attempt is refused and nothing about it moves.
     */
    public function test_a_customer_writing_again_reopens_a_finished_enquiry(): void
    {
        $done = $this->enquiry(['status' => Status::DONE]);
        app(PostMessage::class)->fromCustomer($done, 'А доставка входит в цену?');
        $this->assertSame(Status::IN_PROGRESS, $done->refresh()->status);

        $rejected = $this->enquiry(['status' => Status::REJECTED]);

        try {
            app(PostMessage::class)->fromCustomer($rejected, 'Купите наши ссылки.');
            $this->fail('a rejected enquiry talked its way back into the inbox');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(Status::REJECTED, $rejected->refresh()->status);
    }

    /**
     * The name is a snapshot, like an order's payment_method_name: a manager
     * who leaves and is renamed must not rewrite what the customer read.
     */
    public function test_the_author_name_is_frozen_when_it_is_written(): void
    {
        $admin = $this->admin();
        $enquiry = $this->enquiry();

        app(PostMessage::class)->fromStaff($enquiry, $admin, 'Ответ.');

        $before = $admin->name;
        $admin->update(['name' => 'Somebody Else']);

        $this->assertSame($before, $enquiry->messages()->firstOrFail()->authorName());
    }

    /** A customer with no account signs with the name the enquiry was left under. */
    public function test_a_guest_message_is_signed_with_the_name_they_gave(): void
    {
        $enquiry = $this->enquiry(['name' => 'Пётр']);

        $message = app(PostMessage::class)->fromCustomer($enquiry, 'Всё ещё жду.');

        $this->assertSame('Пётр', $message->authorName());
        $this->assertFalse($message->is_staff);
        $this->assertNull($message->user_id);
    }

    public function test_the_thread_goes_when_the_enquiry_goes(): void
    {
        $enquiry = $this->enquiry();
        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Ответ.');

        $this->actingAs($this->admin())
            ->delete('http://localhost/admin/enquiries/'.$enquiry->id)
            ->assertRedirect();

        $this->assertSame(0, Message::where('enquiry_id', $enquiry->id)->count());
    }

    public function test_an_empty_reply_is_refused(): void
    {
        $enquiry = $this->enquiry();

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/'.$enquiry->id.'/messages', ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, $enquiry->messages()->count());
    }

    public function test_only_staff_can_write_into_a_thread(): void
    {
        $enquiry = $this->enquiry();

        $this->post('http://localhost/admin/enquiries/'.$enquiry->id.'/messages', ['body' => 'Hello'])
            ->assertRedirect();

        $this->assertSame(0, $enquiry->messages()->count());
    }

    public function test_the_thread_is_on_the_enquiry_screen(): void
    {
        $enquiry = $this->enquiry();
        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Перезвонили, договорились.');

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertSee('Перезвонили, договорились.');
    }
}
