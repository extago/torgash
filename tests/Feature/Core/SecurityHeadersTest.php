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

    public function test_an_error_page_carries_them_too(): void
    {
        $this->get('http://localhost/no-such-page-anywhere')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
