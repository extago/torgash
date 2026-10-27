<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;

/**
 * API tokens in the admin's common shape: issued from a window and shown
 * once, searched and tabbed by state, revoked one at a time or several.
 */
class ApiTokensScreenTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_issued_once_listed_by_state_and_revoked_in_bulk(): void
    {
        $this->post('http://localhost/admin/api/tokens', ['name' => 'Quokka CRM', 'abilities' => ['read']])
            ->assertRedirect()->assertSessionHas('new_token');

        ApiToken::issue($this->admin, 'Quokka old', null, now()->subDay());
        $fresh = ApiToken::where('name', 'Quokka CRM')->firstOrFail();
        $old = ApiToken::where('name', 'Quokka old')->firstOrFail();

        $this->get('http://localhost/admin/api/tokens?q=quokka&status=expired')->assertOk()
            ->assertSee('Quokka old')->assertDontSee('Quokka CRM');
        $this->get('http://localhost/admin/api/tokens?q=quokka&status=active')->assertOk()
            ->assertSee('Quokka CRM')->assertDontSee('Quokka old');

        $this->post('http://localhost/admin/api/tokens/bulk', ['ids' => [$fresh->id, $old->id]])->assertRedirect();
        $this->assertSame(0, ApiToken::whereIn('id', [$fresh->id, $old->id])->count());
    }
}
