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

    public function test_a_token_is_narrowed_to_resources_when_issued(): void
    {
        $this->get('http://localhost/admin/api/tokens')->assertOk()
            ->assertSee('admin.products:read')->assertSee('admin.orders:write')->assertSee('commerceml:write')
            ->assertDontSee('commerceml:read', false)->assertDontSee('shop.products');

        $this->post('http://localhost/admin/api/tokens', ['name' => 'CRM', 'narrow' => 'chosen', 'abilities' => ['admin.orders:read', 'admin.products:write', 'admin.products:write']])
            ->assertRedirect()->assertSessionHas('new_token');
        $this->assertSame(['admin.orders:read', 'admin.products:write'], ApiToken::where('name', 'CRM')->firstOrFail()->abilities);

        $this->post('http://localhost/admin/api/tokens', ['name' => 'Everything', 'narrow' => 'all', 'abilities' => ['admin.orders:read']])->assertRedirect();
        $this->assertNull(ApiToken::where('name', 'Everything')->firstOrFail()->abilities);

        $this->post('http://localhost/admin/api/tokens', ['name' => 'Nothing', 'narrow' => 'chosen'])->assertSessionHasErrors('abilities');
        $this->post('http://localhost/admin/api/tokens', ['name' => 'Made up', 'narrow' => 'chosen', 'abilities' => ['admin.secrets:read']])->assertSessionHasErrors('abilities.0');
        $this->post('http://localhost/admin/api/tokens', ['name' => '1C reads', 'narrow' => 'chosen', 'abilities' => ['commerceml:read']])->assertSessionHasErrors('abilities.0');

        $this->get('http://localhost/admin/api/tokens')->assertOk()
            ->assertSee('Orders: read')->assertSee('Products: read and write')->assertSee(__('everything its owner may'));
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
