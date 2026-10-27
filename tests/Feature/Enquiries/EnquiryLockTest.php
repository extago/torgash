<?php

namespace Tests\Feature\Enquiries;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;
use Torgash\Enquiries\Status;

/**
 * "Question resolved" — the end of a conversation, on purpose.
 *
 * The problem it solves is not tidiness: an enquiry that is merely *done* can be
 * written into for ever, so a customer comes back to a year-old thread and the
 * office is answering a context nobody remembers. Locking is a second axis
 * beside the status, the way an order's payment state is, because "have we dealt
 * with this" and "may anybody still write here" are different questions.
 */
class EnquiryLockTest extends TestCase
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

    protected function call_(string $method, string $uri, array $data = [])
    {
        [, $token] = ApiToken::issue($this->admin(), 'Tests');

        return $this->json($method, 'http://localhost'.$uri, $data, [
            'Authorization' => 'Bearer '.$token,
        ]);
    }

    public function test_one_button_answers_it_and_ends_it(): void
    {
        $enquiry = $this->enquiry();

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/'.$enquiry->id.'/close')
            ->assertRedirect();

        $enquiry->refresh();

        $this->assertSame(Status::DONE, $enquiry->status);
        $this->assertNotNull($enquiry->answered_at);
        $this->assertTrue($enquiry->isLocked());
    }

    /** Closed for the SHOP too — that is the difference from "done". */
    public function test_nobody_writes_into_a_closed_thread(): void
    {
        $me = $this->customer();
        $enquiry = $this->enquiry(['user_id' => $me->id, 'locked_at' => now()]);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/'.$enquiry->id.'/messages', ['body' => 'Ещё одно.'])
            ->assertForbidden();

        $this->actingAs($me)
            ->post('http://localhost/account/enquiries/'.$enquiry->id.'/messages', ['body' => 'А я через год.'])
            ->assertForbidden();

        $this->assertSame(0, $enquiry->messages()->count());
    }

    /**
     * The funnel is where the rule lives, so a door nobody has built yet cannot
     * get round it.
     */
    public function test_the_funnel_itself_refuses_a_closed_thread(): void
    {
        $enquiry = $this->enquiry(['locked_at' => now()]);

        try {
            app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Ещё одно.');
            $this->fail('a closed thread took a message');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, $enquiry->messages()->count());
    }

    /** Rejecting one locks it without a stamp: "not worth answering" has to mean it. */
    public function test_a_rejected_enquiry_is_closed_by_that_alone(): void
    {
        $this->assertTrue($this->enquiry(['status' => Status::REJECTED])->isLocked());
    }

    public function test_a_manager_can_change_their_mind(): void
    {
        $enquiry = $this->enquiry(['status' => Status::DONE, 'locked_at' => now()]);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/'.$enquiry->id.'/reopen')
            ->assertRedirect();

        $this->assertFalse($enquiry->refresh()->isLocked());

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/'.$enquiry->id.'/messages', ['body' => 'Ещё одно.'])
            ->assertRedirect();

        $this->assertSame(1, $enquiry->messages()->count());
    }

    /**
     * A rejected enquiry is locked by its status alone, so unlocking it has to
     * move the status too — otherwise the button appears to do nothing.
     */
    public function test_reopening_a_rejected_enquiry_actually_reopens_it(): void
    {
        $enquiry = $this->enquiry(['status' => Status::REJECTED]);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/enquiries/'.$enquiry->id.'/reopen')
            ->assertRedirect();

        $this->assertFalse($enquiry->refresh()->isLocked());
        $this->assertSame(Status::IN_PROGRESS, $enquiry->status);
    }

    public function test_the_customer_is_told_it_is_over_and_gets_no_box(): void
    {
        $me = $this->customer();
        $enquiry = $this->enquiry(['user_id' => $me->id, 'status' => Status::DONE, 'locked_at' => now()]);

        $this->actingAs($me)
            ->get('http://localhost/account/enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertSee(__('This conversation is closed.'))
            ->assertSee(__('Closed'))
            ->assertDontSee(__('Add to this conversation'));
    }

    public function test_the_api_closes_one_and_says_so(): void
    {
        $enquiry = $this->enquiry();

        $this->call_('POST', '/api/v2/admin/enquiries/'.$enquiry->id.'/actions/close')
            ->assertOk()
            ->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.status', Status::DONE);

        $this->call_('POST', '/api/v2/admin/enquiries/'.$enquiry->id.'/actions/reply', ['body' => 'Ещё одно.'])
            ->assertForbidden();
    }
}
