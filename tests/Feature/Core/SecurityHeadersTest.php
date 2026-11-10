<?php

namespace Tests\Feature\Core;

use App\Core\Http\SecurityHeaders;
use Tests\TestCase;

/**
 * The security headers come from the application, whatever server it runs
 * behind (audit 2026-10-15: OSPanel's Caddy added some, another server would
 * have sent none).
 */
class SecurityHeadersTest extends TestCase
{
    public function test_every_answer_carries_them_and_not_the_php_version(): void
    {
        $response = $this->get('http://localhost/');

        foreach (SecurityHeaders::HEADERS as $name => $value) {
            $response->assertHeader($name, $value);
        }
        $response->assertHeaderMissing('X-Powered-By');
        // Not over http, and never for a development host.
        $response->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://torgash.local/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_a_live_shop_over_https_asks_browsers_to_stay_on_https(): void
    {
        $this->get('https://shop.example.com/')->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS);
    }

    /**
     * `SESSION_SECURE_COOKIE` left EMPTY is what does this: the framework then
     * marks a cookie by the request it answers. Written as `false` it would
     * carry the session in the clear; written as `true`, http would lose it.
     */
    public function test_a_cookie_given_over_https_never_goes_back_over_plain_http(): void
    {
        $response = $this->get('https://localhost/account/login');

        $this->assertTrue($response->getCookie(config('session.cookie'), false)->isSecure());
        $this->assertTrue($response->getCookie('XSRF-TOKEN', false)->isSecure());
    }

    public function test_the_cookie_of_a_remembered_browser_is_marked_too(): void
    {
        $user = \App\Models\User::factory()->create(['password' => 'password-one']);

        $response = $this->post('https://localhost/account/login', ['email' => $user->email, 'password' => 'password-one', 'remember' => 1]);

        $this->assertTrue($response->getCookie(\Illuminate\Support\Facades\Auth::guard()->getRecallerName(), false)->isSecure());
    }

    public function test_plain_http_gets_a_cookie_it_can_send_back(): void
    {
        $this->assertFalse($this->get('http://localhost/account/login')->getCookie(config('session.cookie'), false)->isSecure());
    }

    public function test_an_error_page_carries_them_too(): void
    {
        $this->get('http://localhost/no-such-page-anywhere')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
