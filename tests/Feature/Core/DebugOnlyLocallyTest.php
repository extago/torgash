<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * APP_DEBUG pages only for this machine: a request that came through a
 * tunnel (Cloudflare rewrote Host to the dev name but added its headers) or
 * to a public host gets the live shop's error page, not the code and the
 * values around it.
 */
class DebugOnlyLocallyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => true]);
        Route::get('/_debug-probe', fn () => throw new RuntimeException('secret-detail-from-the-code'));
    }

    public function test_this_machine_still_sees_the_debug_page(): void
    {
        $this->get('http://torgash.local/_debug-probe')->assertStatus(500)->assertSee('secret-detail-from-the-code');
    }

    public function test_a_request_through_a_tunnel_gets_the_live_error_page(): void
    {
        $this->get('http://torgash.local/_debug-probe', ['CF-Connecting-IP' => '203.0.113.7', 'CF-Ray' => 'abc'])
            ->assertStatus(500)->assertDontSee('secret-detail-from-the-code');

        // Put back for whoever comes next on the same worker.
        $this->assertTrue(config('app.debug'));
    }

    public function test_a_public_host_gets_the_live_error_page(): void
    {
        $this->get('http://shop.example.com/_debug-probe')->assertStatus(500)->assertDontSee('secret-detail-from-the-code');
    }
}
