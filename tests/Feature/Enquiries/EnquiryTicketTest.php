<?php

namespace Tests\Feature\Enquiries;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use App\Core\Mail\EventMail;
use Torgash\Enquiries\Mail\EnquiryLetters;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Enquiries\AutoClose;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;
use Torgash\Enquiries\Status;

/**
 * The three things that turn a thread into a ticket somebody can actually run:
 * what the customer is told it says, what the shop's queue is, and how a client
 * outside the browser answers one.
 */
class EnquiryTicketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

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

    protected function set(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('torgash.enquiries', $key, $value);
    }

    // ------------------------------------------------ what the customer reads

    /**
     * The bug this pins, found on a real screen: the shop answers, which leaves
     * the enquiry "in progress", and the customer's page then printed "waiting
     * for an answer" directly underneath the answer. The state they are shown
     * is about the CONVERSATION, not about the shop's queue.
     */
    public function test_an_answered_thread_does_not_tell_the_customer_it_is_waiting(): void
    {
        $me = $this->customer();
        $enquiry = $this->enquiry(['user_id' => $me->id]);

        $this->assertSame(Status::CUSTOMER_WAITING, $enquiry->customerState());

        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Перезвоним завтра.');

        $enquiry = $enquiry->fresh();

        $this->assertSame(Status::IN_PROGRESS, $enquiry->status, 'the shop\'s own queue still says in progress');
        $this->assertSame(Status::CUSTOMER_ANSWERED, $enquiry->customerState());

        $this->actingAs($me)
            ->get('http://localhost/account/enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertSee(__('Answer received'))
            ->assertDontSee(__('Waiting for an answer'));
    }

    /** Writing back is asking again: the shop owes the next word. */
    public function test_writing_back_puts_the_ball_in_the_shops_court(): void
    {
        $enquiry = $this->enquiry();

        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Ответ.');
        app(PostMessage::class)->fromCustomer($enquiry, 'А ещё вопрос.');

        $this->assertSame(Status::CUSTOMER_WAITING, $enquiry->fresh()->customerState());
    }

    /** A callback that was simply rung back reads as answered, thread or no thread. */
    public function test_a_finished_enquiry_with_no_thread_reads_as_answered(): void
    {
        $this->assertSame(
            Status::CUSTOMER_ANSWERED,
            $this->enquiry(['status' => Status::DONE])->customerState(),
        );
    }

    // ------------------------------------------------------- the unread badge

    public function test_an_unread_answer_is_marked_until_it_is_opened(): void
    {
        $me = $this->customer();
        $enquiry = $this->enquiry(['user_id' => $me->id]);

        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Перезвоним завтра.');

        $this->assertTrue($enquiry->fresh()->hasUnreadReply());
        $this->assertSame(1, Enquiry::where('user_id', $me->id)->unreadByCustomer()->count());

        $this->actingAs($me)->get('http://localhost/account/enquiries/'.$enquiry->id)->assertOk();

        $this->assertFalse($enquiry->fresh()->hasUnreadReply(), 'reading it did not mark it read');
        $this->assertSame(0, Enquiry::where('user_id', $me->id)->unreadByCustomer()->count());
    }

    /**
     * The customer opening their own page is not the shop editing the enquiry:
     * an exporter pulling everything `updated_after` yesterday must not see it
     * again because somebody read it.
     */
    public function test_reading_a_thread_does_not_look_like_an_edit(): void
    {
        $me = $this->customer();
        $enquiry = $this->enquiry(['user_id' => $me->id]);
        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Ответ.');

        $before = $enquiry->fresh()->updated_at;
        $this->travel(1)->hours();

        $this->actingAs($me)->get('http://localhost/account/enquiries/'.$enquiry->id)->assertOk();

        $this->assertEquals($before, $enquiry->fresh()->updated_at);
    }

    /**
     * The badge and the row marker must agree. They would not if the count
     * asked "is there an unseen staff message" rather than "is the last message
     * an unseen staff one": a customer who wrote back through another door —
     * the API today, an agent tomorrow — has plainly read the answer.
     */
    public function test_the_badge_says_what_the_list_shows(): void
    {
        $me = $this->customer();
        $enquiry = $this->enquiry(['user_id' => $me->id]);

        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Ответ.');
        app(PostMessage::class)->fromCustomer($enquiry, 'Понял, спасибо.', $me);

        $this->assertFalse($enquiry->fresh()->hasUnreadReply());
        $this->assertSame(0, Enquiry::where('user_id', $me->id)->unreadByCustomer()->count());
    }

    /** Their own unread answers, and nobody else's, counted for the menu. */
    public function test_the_menu_counts_only_this_persons_unread_answers(): void
    {
        $me = $this->customer();
        $mine = $this->enquiry(['user_id' => $me->id]);
        $theirs = $this->enquiry(['user_id' => $this->customer('other@example.com')->id]);

        app(PostMessage::class)->fromStaff($mine, $this->admin(), 'Ответ вам.');
        app(PostMessage::class)->fromStaff($theirs, $this->admin(), 'Ответ другому.');

        $this->assertSame(1, Enquiry::where('user_id', $me->id)->unreadByCustomer()->count());
    }

    // ------------------------------------------------------------ auto-close

    public function test_nothing_closes_itself_until_the_shop_asks_for_it(): void
    {
        $enquiry = $this->enquiry();
        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Ответ.');

        $this->travel(100)->hours();

        $this->assertSame(0, AutoClose::run());
        $this->assertSame(Status::IN_PROGRESS, $enquiry->fresh()->status);
    }

    public function test_an_answer_nobody_came_back_to_closes_itself(): void
    {
        $this->set('auto_close_hours', 48);

        $enquiry = $this->enquiry();
        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Ответ.');
        $answeredAt = $enquiry->fresh()->answered_at;

        $this->travel(24)->hours();
        $this->assertSame(0, AutoClose::run(), 'closed before the shop said it could');

        $this->travel(25)->hours();
        $this->assertSame(1, AutoClose::run());

        $enquiry = $enquiry->fresh();

        $this->assertSame(Status::DONE, $enquiry->status);
        $this->assertEquals($answeredAt, $enquiry->answered_at, 'a 3am sweep is not when the person was answered');
    }

    /**
     * The rule is "the shop spoke last", never "it is old". A question the shop
     * has not answered is backlog, and closing it would be the inbox tidying
     * away its own failures.
     */
    public function test_an_unanswered_question_is_never_closed_by_age(): void
    {
        $this->set('auto_close_hours', 1);

        $waiting = $this->enquiry();
        $written = $this->enquiry();
        app(PostMessage::class)->fromStaff($written, $this->admin(), 'Ответ.');
        app(PostMessage::class)->fromCustomer($written, 'А ещё вопрос.');

        $this->travel(1000)->hours();

        $this->assertSame(0, AutoClose::run());
        $this->assertSame(Status::NEW, $waiting->fresh()->status);
        $this->assertSame(Status::IN_PROGRESS, $written->fresh()->status);
    }

    // ---------------------------------------------------- the inbox's own queue

    /** One of each state, told apart by the name printed in the row. */
    protected function oneOfEach(): void
    {
        foreach (Status::ALL as $status) {
            $this->enquiry(['name' => 'Заявка-'.$status, 'status' => $status]);
        }
    }

    /**
     * The list opens on the queue. A shop that has answered a thousand
     * callbacks would otherwise have to page through them to find the one
     * question nobody has touched.
     */
    public function test_the_inbox_opens_on_what_is_not_dealt_with(): void
    {
        $this->oneOfEach();

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/enquiries')
            ->assertOk()
            ->assertSee('Заявка-new')
            ->assertSee('Заявка-in_progress')
            ->assertDontSee('Заявка-done')
            ->assertDontSee('Заявка-rejected');
    }

    /**
     * And the archive is one click away. This is the case the marker exists
     * for: the form submits no `status[]` at all when every box is unticked,
     * so without `status_set` this request would be a first visit and the
     * default would silently come back.
     */
    public function test_unticking_every_box_shows_every_state(): void
    {
        $this->oneOfEach();

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/enquiries?status_set=1')
            ->assertOk()
            ->assertSee('Заявка-new')
            ->assertSee('Заявка-done')
            ->assertSee('Заявка-rejected');
    }

    public function test_a_chosen_set_is_the_set_that_is_shown(): void
    {
        $this->oneOfEach();

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/enquiries?status_set=1&status%5B%5D=done&status%5B%5D=rejected')
            ->assertOk()
            ->assertSee('Заявка-done')
            ->assertSee('Заявка-rejected')
            ->assertDontSee('Заявка-new');
    }

    /** A bookmark from before the boxes existed asked for one state; it still does. */
    public function test_an_older_single_status_link_still_means_that_state(): void
    {
        $this->oneOfEach();

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/enquiries?status=rejected')
            ->assertOk()
            ->assertSee('Заявка-rejected')
            ->assertDontSee('Заявка-new');
    }

    // ------------------------------------------------------------------- API

    protected function call_(string $method, string $uri, array $data = [])
    {
        [, $token] = ApiToken::issue($this->admin(), 'Tests');

        return $this->json($method, 'http://localhost'.$uri, $data, [
            'Authorization' => 'Bearer '.$token,
        ]);
    }

    public function test_the_api_answers_with_the_thread_and_who_owes_a_reply(): void
    {
        $enquiry = $this->enquiry();
        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Перезвоним завтра.');

        $response = $this->call_('GET', '/api/v2/admin/enquiries/'.$enquiry->id)->assertOk();

        $response->assertJsonPath('data.messages.0.author', 'shop');
        $response->assertJsonPath('data.messages.0.body', 'Перезвоним завтра.');
        $response->assertJsonPath('data.awaiting_reply', false);
    }

    /**
     * The queue an outside client — a CRM, an agent — actually asks for. Not
     * status=new: the shop replying leaves an enquiry in progress, and a
     * customer writing back to a finished one reopens it.
     */
    public function test_the_api_can_ask_for_what_still_needs_an_answer(): void
    {
        $unanswered = $this->enquiry();
        $answered = $this->enquiry();
        app(PostMessage::class)->fromStaff($answered, $this->admin(), 'Ответ.');

        $ids = collect($this->call_('GET', '/api/v2/admin/enquiries?awaiting_reply=1')
            ->assertOk()
            ->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($unanswered->id));
        $this->assertFalse($ids->contains($answered->id));
    }

    /** The same set the screen asks for, said in one parameter. */
    public function test_the_api_takes_several_statuses_at_once(): void
    {
        $this->oneOfEach();

        $names = collect($this->call_('GET', '/api/v2/admin/enquiries?status=new,in_progress')
            ->assertOk()
            ->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Заявка-new'));
        $this->assertTrue($names->contains('Заявка-in_progress'));
        $this->assertFalse($names->contains('Заявка-done'));
    }

    /**
     * Answering through the API is the SAME act as answering in the box: it
     * ends the wait, moves the enquiry, takes ownership and emails. A client
     * writing a message row would have done none of it.
     */
    public function test_the_api_answers_an_enquiry_the_way_a_manager_does(): void
    {
        $enquiry = $this->enquiry(['email' => 'buyer@example.com']);

        $this->call_('POST', '/api/v2/admin/enquiries/'.$enquiry->id.'/actions/reply', [
            'body' => 'Ответ из CRM.',
        ])
            ->assertOk()
            ->assertJsonPath('data.messages.0.author', 'shop')
            ->assertJsonPath('data.awaiting_reply', false);

        $enquiry = $enquiry->fresh();

        $this->assertSame(Status::IN_PROGRESS, $enquiry->status);
        $this->assertSame($this->admin()->id, $enquiry->manager_id, 'the token\'s owner did not take it on');
        $this->assertNotNull($enquiry->answered_at);
        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === EnquiryLetters::REPLIED);
    }

    public function test_the_api_refuses_an_empty_answer(): void
    {
        $enquiry = $this->enquiry();

        $this->call_('POST', '/api/v2/admin/enquiries/'.$enquiry->id.'/actions/reply', ['body' => ''])
            ->assertStatus(422);

        $this->assertSame(0, $enquiry->messages()->count());
    }
}
