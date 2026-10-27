<?php

namespace Tests\Feature\Core;

use App\Core\Http\OutboundUrl;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * A link somebody pasted reaches only the public internet (audit
 * 2026-10-15: a picture "by its link" made the server fetch 127.0.0.1 and
 * the office network).
 */
class OutboundUrlTest extends TestCase
{
    public function test_private_loopback_and_reserved_addresses_are_not_public(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '::1', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1'] as $ip) {
            $this->assertFalse(OutboundUrl::isPublic($ip), $ip);
        }
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700::1111'] as $ip) {
            $this->assertTrue(OutboundUrl::isPublic($ip), $ip);
        }
    }

    public function test_a_link_to_the_server_itself_or_a_private_network_is_refused_before_any_request(): void
    {
        Http::fake();

        foreach (['http://127.0.0.1/admin', 'http://[::1]:5432/', 'http://router.internal/', 'http://169.254.169.254/latest/meta-data/', 'file:///etc/passwd', 'gopher://x/'] as $url) {
            try {
                app(OutboundUrl::class)->get($url);
                $this->fail('fetched '.$url);
            } catch (RuntimeException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_public_link_is_fetched_and_a_redirect_into_the_network_is_not_followed(): void
    {
        Http::fake([
            'https://cdn.example.com/a.jpg' => Http::response('jpeg', 200),
            'https://cdn.example.com/moved.jpg' => Http::response('', 302, ['Location' => '/a.jpg']),
            'https://cdn.example.com/sneaky.jpg' => Http::response('', 302, ['Location' => 'http://db.internal:5432/']),
        ]);

        $this->assertSame('jpeg', app(OutboundUrl::class)->get('https://cdn.example.com/a.jpg')->body());
        $this->assertSame('jpeg', app(OutboundUrl::class)->get('https://cdn.example.com/moved.jpg')->body());

        $this->expectException(RuntimeException::class);
        app(OutboundUrl::class)->get('https://cdn.example.com/sneaky.jpg');
    }
}
