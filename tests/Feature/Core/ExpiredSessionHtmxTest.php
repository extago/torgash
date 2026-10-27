<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * A page left open past its session: the bell's poll must not swap the whole
 * login screen into the top bar. HTMX is told to take the PAGE to the login,
 * and the login brings it back to the page, not to the fragment.
 */
class ExpiredSessionHtmxTest extends TestCase
{
    public function test_an_htmx_request_without_a_session_sends_the_page_to_the_login(): void
    {
        $this->get('http://localhost/admin/notifications', [
            'HX-Request' => 'true',
            'HX-Current-URL' => 'http://localhost/admin/catalog/categories',
        ])
            ->assertStatus(401)
            ->assertHeader('HX-Redirect', route('admin.login'))
            ->assertSessionHas('url.intended', 'http://localhost/admin/catalog/categories');
    }

    public function test_another_host_is_never_remembered_as_the_way_back(): void
    {
        $this->get('http://localhost/admin/notifications', [
            'HX-Request' => 'true',
            'HX-Current-URL' => 'https://evil.example/admin',
        ])
            ->assertStatus(401)
            ->assertSessionMissing('url.intended');
    }

    public function test_an_ordinary_request_still_redirects(): void
    {
        $this->get('http://localhost/admin/catalog/categories')->assertRedirect(route('admin.login'));
    }
}
