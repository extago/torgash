<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Laravel's `throttle:N,M` counts per visitor, not per door: every route using
 * the plain form shared one counter, so a browser's reports of a blocked script
 * closed "I lost my password", and the tightest limit lived by the window of
 * whichever route was hit first.
 */
class RateLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('throttle:5,10')->get('/__limits/tight', fn () => 'ok');
        Route::middleware('throttle:100,1')->get('/__limits/loose', fn () => 'ok');
    }

    public function test_a_limit_counts_its_own_door_only(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->get('http://localhost/__limits/loose')->assertOk();
        }

        $this->get('http://localhost/__limits/tight')->assertOk();
    }

    public function test_a_door_still_closes_at_its_own_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->get('http://localhost/__limits/tight')->assertOk();
        }

        $this->get('http://localhost/__limits/tight')->assertStatus(429);
        $this->get('http://localhost/__limits/loose')->assertOk();
    }

    public function test_a_signed_in_visitor_is_counted_per_door_too(): void
    {
        $this->actingAs(User::factory()->create());

        for ($i = 0; $i < 8; $i++) {
            $this->get('http://localhost/__limits/loose')->assertOk();
        }

        $this->get('http://localhost/__limits/tight')->assertOk();
    }

    public function test_a_real_door_is_not_closed_by_another(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->post('http://localhost/csp-report', []);
        }

        $this->post('http://localhost/admin/password/forgot', ['email' => 'nobody@example.test'])
            ->assertRedirect();
    }
}
