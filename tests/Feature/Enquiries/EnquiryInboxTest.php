<?php

namespace Tests\Feature\Enquiries;

use App\Models\User;
use Tests\TestCase;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Message;
use Torgash\Enquiries\Status;

/**
 * The inbox's tools: the queue tab, the manager filter, the search that reads
 * what people wrote, in-place changes, several at once, and the enquiry page's
 * view of the same person.
 */
class EnquiryInboxTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function enquiry(array $attributes = []): Enquiry
    {
        return Enquiry::create($attributes + [
            'kind' => 'callback',
            'name' => 'Somebody',
            'phone' => '+380501112233',
            'fields' => ['values' => ['phone' => '+380501112233'], 'labels' => ['phone' => 'Phone']],
        ]);
    }

    public function test_the_queue_tab_holds_only_what_the_shop_owes_an_answer_to(): void
    {
        $this->enquiry(['name' => 'Nobody-answered']);
        $answered = $this->enquiry(['name' => 'Shop-spoke-last', 'status' => Status::IN_PROGRESS]);
        Message::create(['enquiry_id' => $answered->id, 'is_staff' => true, 'body' => 'Hi']);
        $back = $this->enquiry(['name' => 'Customer-spoke-last', 'status' => Status::IN_PROGRESS]);
        Message::create(['enquiry_id' => $back->id, 'is_staff' => true, 'body' => 'Hi']);
        Message::create(['enquiry_id' => $back->id, 'is_staff' => false, 'body' => 'And?']);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/enquiries?view=awaiting')
            ->assertOk()
            ->assertSee('Nobody-answered')
            ->assertSee('Customer-spoke-last')
            ->assertDontSee('Shop-spoke-last');
    }

    public function test_search_reads_the_answers_and_the_conversation_and_the_number(): void
    {
        $kettle = $this->enquiry(['name' => 'Kettle-person', 'fields' => ['values' => ['question' => 'Красный чайник есть?'], 'labels' => []]]);
        $thread = $this->enquiry(['name' => 'Thread-person']);
        Message::create(['enquiry_id' => $thread->id, 'is_staff' => false, 'body' => 'Где мой заказ TG-777?']);
        $this->enquiry(['name' => 'Other-person']);

        $this->actingAs($this->admin)->get('http://localhost/admin/enquiries?view=all&q=чайник')
            ->assertSee('Kettle-person')->assertDontSee('Other-person');
        $this->get('http://localhost/admin/enquiries?view=all&q=TG-777')
            ->assertSee('Thread-person')->assertDontSee('Other-person');
        $this->get('http://localhost/admin/enquiries?view=all&q=%23'.$kettle->id)
            ->assertSee('Kettle-person');
    }

    public function test_the_manager_filter_finds_mine_and_nobodys(): void
    {
        $this->enquiry(['name' => 'Mine-one', 'manager_id' => $this->admin->id]);
        $this->enquiry(['name' => 'Ownerless-one']);

        $this->actingAs($this->admin)->get('http://localhost/admin/enquiries?manager=me')
            ->assertSee('Mine-one')->assertDontSee('Ownerless-one');
        $this->get('http://localhost/admin/enquiries?manager=none')
            ->assertSee('Ownerless-one')->assertDontSee('Mine-one');
    }

    public function test_status_and_manager_change_in_place(): void
    {
        $enquiry = $this->enquiry();

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/enquiries/{$enquiry->id}/status", ['status' => Status::DONE])
            ->assertOk()->assertSee('enquiry-status-'.$enquiry->id, false);
        $this->assertSame(Status::DONE, $enquiry->refresh()->status);
        $this->assertNotNull($enquiry->answered_at);

        $this->patch("http://localhost/admin/enquiries/{$enquiry->id}/manager", ['manager_id' => 'me'])
            ->assertOk()->assertSee($this->admin->name);
        $this->assertSame($this->admin->id, $enquiry->refresh()->manager_id);
    }

    public function test_several_at_once(): void
    {
        $a = $this->enquiry();
        $b = $this->enquiry();

        $this->actingAs($this->admin)
            ->post('http://localhost/admin/enquiries/bulk', ['action' => 'assign', 'manager_id' => 'me', 'ids' => [$a->id, $b->id]])
            ->assertRedirect();
        $this->assertSame($this->admin->id, $a->refresh()->manager_id);

        $this->post('http://localhost/admin/enquiries/bulk', ['action' => 'close', 'ids' => [$a->id, $b->id]]);
        $this->assertTrue($a->refresh()->isLocked());
        $this->assertTrue($b->refresh()->isLocked());

        $this->post('http://localhost/admin/enquiries/bulk', ['action' => 'delete', 'ids' => [$a->id]]);
        $this->assertNull($a->fresh());
    }

    public function test_reply_and_close_in_one_go(): void
    {
        $enquiry = $this->enquiry();

        $this->actingAs($this->admin)
            ->post("http://localhost/admin/enquiries/{$enquiry->id}/messages", ['body' => 'Done, thanks.', 'and_close' => 1])
            ->assertRedirect();

        $this->assertTrue($enquiry->refresh()->isLocked());
        $this->assertSame(1, $enquiry->messages()->count());
    }

    /**
     * A callback that left only a phone number has nobody to read a reply:
     * the page offers a call log, not a conversation, and writing into it
     * still takes the enquiry out of the queue.
     */
    public function test_a_phone_only_enquiry_gets_a_call_log_not_a_conversation(): void
    {
        $phoneOnly = $this->enquiry();
        $withEmail = $this->enquiry(['email' => 'someone@example.test']);

        $this->actingAs($this->admin)
            ->get("http://localhost/admin/enquiries/{$phoneOnly->id}")
            ->assertOk()
            ->assertSee('What was agreed on the phone')
            ->assertDontSee('Write a reply to the customer');

        $this->get("http://localhost/admin/enquiries/{$withEmail->id}")
            ->assertOk()
            ->assertSee('Write a reply to the customer');

        $this->post("http://localhost/admin/enquiries/{$phoneOnly->id}/messages", ['body' => 'Rang, agreed Tuesday.'])
            ->assertSessionHas('status', 'Written down.');
        $this->assertFalse($phoneOnly->refresh()->load('lastMessage')->isAwaitingReply());
    }

    /** A callback is dealt with from the row: ring, then resolve, and back to the list. */
    public function test_a_callback_is_rung_and_resolved_from_the_list(): void
    {
        $enquiry = $this->enquiry();

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/enquiries')
            ->assertSee('tel:+380501112233', false)
            ->assertSee(route('admin.enquiries.close', $enquiry), false);

        $this->from('http://localhost/admin/enquiries')
            ->post("http://localhost/admin/enquiries/{$enquiry->id}/close")
            ->assertRedirect('http://localhost/admin/enquiries');
        $this->assertTrue($enquiry->refresh()->isLocked());
        $this->assertSame(Status::DONE, $enquiry->status);
    }

    public function test_the_page_shows_the_same_persons_other_enquiries_and_the_next_in_the_queue(): void
    {
        $first = $this->enquiry(['name' => 'Same-person', 'customer_ref' => 'ref-1', 'kind' => 'product_question']);
        $current = $this->enquiry(['name' => 'Same-person', 'customer_ref' => 'ref-1']);

        $this->actingAs($this->admin)
            ->get("http://localhost/admin/enquiries/{$current->id}")
            ->assertOk()
            ->assertSee('#'.$first->id)
            ->assertSee(route('admin.enquiries.show', $first->id), false);
    }
}
