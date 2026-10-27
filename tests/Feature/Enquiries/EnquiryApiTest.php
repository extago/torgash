<?php

namespace Tests\Feature\Enquiries;

use App\Models\User;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Status;

/**
 * §23.4/4: the last open item from the enquiries plan — a CRM reads the inbox
 * out over the API, exactly as a manager reads and dispositions it on screen.
 */
class EnquiryApiTest extends TestCase
{
    protected function token(): string
    {
        $admin = User::role('admin')->firstOrFail();
        [, $token] = ApiToken::issue($admin, 'Tests');

        return $token;
    }

    protected function call_(string $method, string $uri, array $data = [])
    {
        return $this->json($method, 'http://localhost'.$uri, $data, [
            'Authorization' => 'Bearer '.$this->token(),
        ]);
    }

    public function test_an_enquiry_is_read_with_its_answers_and_subject(): void
    {
        $enquiry = Enquiry::create([
            'kind' => 'callback',
            'phone' => '+380671234567',
            'name' => 'Иван',
            'fields' => ['values' => ['phone' => '+380671234567'], 'labels' => ['phone' => 'Phone']],
            'customer_ref' => 'ref-1',
        ]);

        $response = $this->call_('GET', '/api/v2/admin/enquiries/'.$enquiry->id)->assertOk();

        $response->assertJsonPath('data.kind', 'callback');
        $response->assertJsonPath('data.kind_label', 'Callback');
        $response->assertJsonPath('data.customer_ref', 'ref-1');
        $response->assertJsonPath('data.answers.0.label', 'Phone');
    }

    public function test_filters_narrow_the_list(): void
    {
        Enquiry::create(['kind' => 'callback', 'phone' => '1', 'status' => Status::NEW]);
        Enquiry::create(['kind' => 'message', 'phone' => '2', 'status' => Status::DONE]);

        $response = $this->call_('GET', '/api/v2/admin/enquiries?status='.Status::DONE)->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('message', $response->json('data.0.kind'));
    }

    /**
     * The one act the admin screen offers — status, manager, notes — with the
     * same answered_at rule: stamped on the first move into a finished state.
     */
    public function test_update_dispositions_the_enquiry_the_way_the_admin_screen_does(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $enquiry = Enquiry::create(['kind' => 'callback', 'phone' => '+380671234567']);

        $this->call_('PATCH', '/api/v2/admin/enquiries/'.$enquiry->id, [
            'status' => Status::DONE,
            'manager_id' => $admin->id,
            'notes' => 'Rang back, sold it.',
        ])->assertOk()->assertJsonPath('data.status', Status::DONE);

        $enquiry->refresh();
        $this->assertSame(Status::DONE, $enquiry->status);
        $this->assertNotNull($enquiry->answered_at);
    }

    /** The answers themselves are not among the writable fields. */
    public function test_the_answers_cannot_be_rewritten_through_the_api(): void
    {
        $enquiry = Enquiry::create([
            'kind' => 'callback', 'phone' => '+380671234567',
            'fields' => ['values' => ['phone' => '+380671234567'], 'labels' => ['phone' => 'Phone']],
        ]);

        $this->call_('PATCH', '/api/v2/admin/enquiries/'.$enquiry->id, [
            'fields' => ['values' => ['phone' => 'hijacked']],
        ])->assertOk();

        $this->assertSame('+380671234567', $enquiry->fresh()->answer('phone'));
    }
}
