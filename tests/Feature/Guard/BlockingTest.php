<?php

namespace Tests\Feature\Guard;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Torgash\Guard\Blocking\Blocks;
use Torgash\Guard\Blocking\Signals;
use Torgash\Guard\Models\Block;

/**
 * The last part of the guard, and the only one that can do harm.
 *
 * So what is pinned here is not "an address gets blocked" — that is the easy
 * half — but the three rules the plan called non-negotiable, plus the one
 * safeguard that came out of writing it:
 *
 *   1. a request that presented valid credentials is never touched;
 *   2. it watches before it acts, and watching refuses nobody;
 *   3. there is a way back in that does not go through the admin panel;
 *   4. a private address is never blocked automatically, because a shop behind
 *      an untrusted proxy sees every visitor as one address and the first
 *      automatic block would shut the whole shop.
 */
class BlockingTest extends TestCase
{
    /** A public address (TEST-NET-3), so nothing here is the machine's own. */
    protected const IP = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings(Blocks::ON);
        Block::query()->delete();
        app(Blocks::class)->flush();
    }

    protected function settings(string $mode, array $extra = []): void
    {
        $settings = app(SettingsManager::class);
        $settings->set('torgash.guard', 'blocking_mode', $mode);

        foreach ($extra + [
            'block_threshold' => 3,
            'block_window' => 10,
            'block_minutes' => 60,
            'block_allow' => '',
        ] as $key => $value) {
            $settings->set('torgash.guard', $key, $value);
        }

        foreach (Signals::COUNTED as $signal) {
            // The IPv6 tests' network too: the cache outlives a test's transaction.
            foreach ([self::IP, '2001:db8:1:2::/64'] as $subject) {
                RateLimiter::clear('guard:signal:'.$signal.':'.$subject);
            }
        }
    }

    /** A request that looks like it came from the address under test. */
    protected function asVisitor(string $ip = self::IP): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /** Report a signal as if it arrived from that address. */
    protected function report(string $signal, int $times = 1, string $ip = self::IP): void
    {
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => $ip]);

        for ($i = 0; $i < $times; $i++) {
            app(Blocks::class)->report($signal, $request);
        }
    }

    // ------------------------------------------------------- what it is for

    public function test_enough_refusals_from_one_address_earn_a_block(): void
    {
        $this->report(Signals::LOGIN, 2);

        $this->assertNull(Block::where('ip', self::IP)->first(), 'Below the threshold nothing is written.');

        $this->report(Signals::LOGIN);

        $block = Block::where('ip', self::IP)->firstOrFail();

        $this->assertSame(Block::ENFORCED, $block->mode);
        $this->assertSame(Signals::LOGIN, $block->reason);
        $this->assertEquals(['login' => 3], $block->signals);
        $this->assertTrue($block->until->isFuture());
    }

    /**
     * One counter, not four. Two wrong passwords and one refused form is the
     * same address misbehaving three times — which is exactly the sentence the
     * single threshold on the settings screen makes it possible to reason about.
     */
    public function test_signals_of_different_kinds_are_counted_together(): void
    {
        $this->report(Signals::LOGIN, 2);
        $this->report(Signals::FORM);

        $block = Block::where('ip', self::IP)->firstOrFail();

        // assertEquals, not assertSame: jsonb does not keep key order — it
        // sorts by length, then bytewise. The project's own Postgres gotcha,
        // met again here.
        $this->assertEquals(['login' => 2, 'form' => 1], $block->signals);
    }

    public function test_a_blocked_address_is_refused_with_a_429_and_a_retry_after(): void
    {
        $this->report(Signals::LOGIN, 3);

        $response = $this->asVisitor()->get('http://localhost/');

        $response->assertStatus(429);
        $this->assertNotEmpty($response->headers->get('Retry-After'));
    }

    /** Watch mode is the whole point of the row: the hits are the evidence. */
    public function test_requests_are_counted_even_when_nobody_is_refused(): void
    {
        $this->settings(Blocks::WATCH);
        $this->report(Signals::LOGIN, 3);

        $block = Block::where('ip', self::IP)->firstOrFail();
        $this->assertSame(Block::OBSERVED, $block->mode);

        $this->asVisitor()->get('http://localhost/')->assertSuccessful();
        $this->asVisitor()->get('http://localhost/')->assertSuccessful();

        $this->assertSame(2, $block->fresh()->hits);
    }

    // ------------------------------------------------ rule one: credentials

    public function test_a_signed_in_request_is_never_touched(): void
    {
        $this->report(Signals::LOGIN, 3);
        $this->assertTrue(Block::where('ip', self::IP)->firstOrFail()->isEnforced());

        $this->actingAs(User::role('admin')->firstOrFail());

        $this->asVisitor()->get('http://localhost/admin')->assertSuccessful();
    }

    /**
     * ...and nothing a signed-in request does is even counted: the manager who
     * mistypes their own password while changing it is not a signal.
     */
    public function test_nothing_is_counted_against_a_signed_in_request(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->report(Signals::LOGIN, 5);

        $this->assertNull(Block::where('ip', self::IP)->first());
    }

    /**
     * The other half of rule one is the SHAPE of the route table rather than
     * any code: everything credentialed is registered outside `web`, so the
     * check cannot reach it. `/api/v2/*` is the one to pin, because it is the
     * one an integration lives on.
     */
    public function test_the_check_is_not_in_front_of_the_machines_doors(): void
    {
        $this->report(Signals::LOGIN, 3);

        // No token, so a 401 — but a 401 from the API's own door, which is the
        // point: the block never got a say.
        $this->asVisitor()->getJson('http://localhost/api/v2/admin/products')->assertStatus(401);
    }

    // ---------------------------------------------------- rule two: watching

    public function test_watching_is_the_default(): void
    {
        app(SettingsManager::class)->forget('torgash.guard', 'blocking_mode');

        $this->assertSame(Blocks::WATCH, app(Blocks::class)->mode());
    }

    public function test_an_observed_block_refuses_nobody(): void
    {
        $this->settings(Blocks::WATCH);
        $this->report(Signals::FORM, 3);

        $this->asVisitor()->get('http://localhost/')->assertSuccessful();
    }

    public function test_switching_enforcement_on_does_not_apply_yesterdays_observations(): void
    {
        $this->settings(Blocks::WATCH);
        $this->report(Signals::FORM, 3);

        $this->settings(Blocks::ON);
        app(Blocks::class)->flush();

        $this->assertSame(Block::OBSERVED, Block::where('ip', self::IP)->firstOrFail()->mode);
        $this->asVisitor()->get('http://localhost/')->assertSuccessful();
    }

    public function test_an_observed_block_can_be_agreed_with_by_hand(): void
    {
        $this->settings(Blocks::WATCH);
        $this->report(Signals::FORM, 3);

        app(Blocks::class)->enforce(Block::where('ip', self::IP)->firstOrFail());

        $this->asVisitor()->get('http://localhost/')->assertStatus(429);
    }

    public function test_off_records_nothing_and_refuses_nobody(): void
    {
        $this->settings(Blocks::OFF);
        $this->report(Signals::LOGIN, 5);

        $this->assertNull(Block::where('ip', self::IP)->first());
    }

    // ----------------------------------------------------- rule three: console

    public function test_the_console_lets_an_address_back_in(): void
    {
        $this->report(Signals::LOGIN, 3);
        $this->asVisitor()->get('http://localhost/')->assertStatus(429);

        $this->artisan('guard:unblock', ['address' => self::IP])->assertSuccessful();

        $this->asVisitor()->get('http://localhost/')->assertSuccessful();
    }

    public function test_the_console_can_release_everything_and_go_back_to_watching(): void
    {
        $this->report(Signals::LOGIN, 3);

        $this->artisan('guard:unblock', ['--all' => true, '--watch' => true])->assertSuccessful();

        $this->assertSame(Blocks::WATCH, app(Blocks::class)->mode());
        $this->assertNotNull(Block::where('ip', self::IP)->firstOrFail()->released_at);
        $this->asVisitor()->get('http://localhost/')->assertSuccessful();
    }

    /**
     * Letting an address in has to clear the counters too, or the window that
     * earned the block is still full of what earned it and the next refusal
     * puts them straight back.
     */
    public function test_letting_an_address_in_forgets_what_it_did(): void
    {
        $this->report(Signals::LOGIN, 3);
        app(Blocks::class)->release(self::IP);

        // One more refusal after being let in must not be the third one all
        // over again.
        $this->report(Signals::LOGIN);

        $this->assertFalse(Block::where('ip', self::IP)->firstOrFail()->isActive());
    }

    /** Coming back is what lengthens the next one. */
    public function test_coming_back_counts_a_strike_and_lengthens_the_block(): void
    {
        $this->report(Signals::LOGIN, 3);
        $first = Block::where('ip', self::IP)->firstOrFail();

        app(Blocks::class)->release(self::IP);
        $this->report(Signals::LOGIN, 3);

        $second = Block::where('ip', self::IP)->firstOrFail();

        $this->assertSame(2, $second->strikes);
        $this->assertTrue($second->until->greaterThan($first->until));
    }

    // ------------------------------------------------- rule four: the proxy

    public function test_a_private_address_is_recorded_but_never_blocked_automatically(): void
    {
        $this->report(Signals::LOGIN, 3, '10.0.0.7');

        $block = Block::where('ip', '10.0.0.7')->firstOrFail();

        $this->assertSame(Block::OBSERVED, $block->mode);
        $this->assertNotNull($block->note, 'It says why, rather than looking like an ordinary observation.');
    }

    public function test_a_private_address_can_still_be_blocked_by_hand(): void
    {
        $block = app(Blocks::class)->block('10.0.0.7', Signals::MANUAL, ['by_hand' => true, 'minutes' => 30]);

        $this->assertSame(Block::ENFORCED, $block->mode);
    }

    // ------------------------------------------------------------ the rest

    public function test_an_allowed_address_is_never_blocked_at_all(): void
    {
        $this->settings(Blocks::ON, ['block_allow' => "198.51.100.0/24\n".self::IP]);

        $this->report(Signals::LOGIN, 5);
        $this->report(Signals::LOGIN, 5, '198.51.100.4');

        $this->assertSame(0, Block::count());
    }

    public function test_a_network_is_matched_by_its_prefix_and_not_by_its_text(): void
    {
        $this->settings(Blocks::ON, ['block_allow' => '198.51.100.0/30']);

        $this->report(Signals::LOGIN, 3, '198.51.100.2');
        $this->report(Signals::LOGIN, 3, '198.51.100.9');

        $this->assertNull(Block::where('ip', '198.51.100.2')->first());
        $this->assertNotNull(Block::where('ip', '198.51.100.9')->first());
    }

    /**
     * The signals are somebody else's announcements, and this is the one that
     * runs on every public form in the shop: core refuses, the guard counts.
     */
    public function test_a_refused_public_form_is_a_signal(): void
    {
        $guard = app(FormGuard::class);
        $request = Request::create('/', 'POST', [
            FormGuard::TRAP => 'a bot filled this in',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 10)),
        ], server: ['REMOTE_ADDR' => self::IP]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertNotNull($guard->check($request, null, 'enquiry'));
        }

        $block = Block::where('ip', self::IP)->firstOrFail();

        $this->assertEquals(['form' => 3], $block->signals);
    }

    /** A block ends by itself; nothing has to tidy it up. */
    public function test_a_block_that_has_run_out_stops_blocking(): void
    {
        $this->report(Signals::LOGIN, 3);

        Block::where('ip', self::IP)->update(['until' => now()->subMinute()]);
        app(Blocks::class)->flush();

        $this->asVisitor()->get('http://localhost/')->assertSuccessful();
    }

    public function test_the_screen_lists_what_is_standing(): void
    {
        $this->report(Signals::LOGIN, 3);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/guard/blocks')
            ->assertSuccessful()
            ->assertSee(self::IP);
    }

    public function test_the_tabs_and_the_search_narrow_the_list(): void
    {
        $this->settings(Blocks::WATCH);
        $this->report(Signals::LOGIN, 3);
        app(Blocks::class)->block('198.51.100.4', Signals::MANUAL, ['by_hand' => true, 'minutes' => 30]);

        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/guard/blocks?tab=enforced')
            ->assertOk()->assertSee('198.51.100.4')->assertDontSee(self::IP);

        $this->actingAs($admin)->get('http://localhost/admin/guard/blocks?tab=observed')
            ->assertOk()->assertSee(self::IP)->assertDontSee('198.51.100.4');

        $this->actingAs($admin)->get('http://localhost/admin/guard/blocks?signal=manual')
            ->assertOk()->assertSee('198.51.100.4')->assertDontSee(self::IP);

        $this->actingAs($admin)->get('http://localhost/admin/guard/blocks?q=198.51')
            ->assertOk()->assertSee('198.51.100.4')->assertDontSee(self::IP);
    }

    /** Through Blocks::release(), so the counters are forgotten as by the row's own button. */
    public function test_ticked_addresses_are_let_back_in_together(): void
    {
        $this->report(Signals::LOGIN, 3);
        app(Blocks::class)->block('198.51.100.4', Signals::MANUAL, ['by_hand' => true]);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/guard/blocks/bulk', ['action' => 'release', 'ids' => Block::pluck('id')->all()])
            ->assertRedirect();

        $this->assertSame(0, Block::active()->count());
        $this->asVisitor()->get('http://localhost/')->assertSuccessful();
    }

    /** Written by a visitor's request in their language, read by the admin in theirs. */
    public function test_the_automatic_note_is_stored_in_english_and_printed_in_the_readers_language(): void
    {
        $this->report(Signals::LOGIN, 3, '10.0.0.7');

        $this->assertStringStartsWith('A private or reserved address', Block::where('ip', '10.0.0.7')->value('note'));

        $this->actingAs(User::role('admin')->firstOrFail())
            ->withSession(['admin_locale' => 'ru'])
            ->get('http://localhost/admin/guard/blocks')
            ->assertOk()
            ->assertSee('Частный или служебный адрес');
    }

    // ------------------------------------------------------------- IPv6

    /**
     * A client holds a whole /64: a bot taking a fresh address for every
     * password never filled any one address's counter, so it was never blocked.
     */
    public function test_addresses_of_one_ipv6_network_share_one_counter_and_one_block(): void
    {
        $this->report(Signals::LOGIN, 1, '2001:db8:1:2::a');
        $this->report(Signals::LOGIN, 1, '2001:db8:1:2::b');
        $this->report(Signals::LOGIN, 1, '2001:db8:1:2:ffff::c');

        $block = Block::sole();
        $this->assertSame('2001:db8:1:2::/64', $block->ip);
        $this->assertSame(Block::ENFORCED, $block->mode);

        $this->asVisitor('2001:db8:1:2::dead')->get('http://localhost/')->assertStatus(429);
        $this->asVisitor('2001:db8:1:3::a')->get('http://localhost/')->assertSuccessful();
    }

    public function test_two_spellings_of_one_address_are_one_client(): void
    {
        app(Blocks::class)->block('2001:0db8:0001:0002:0000:0000:0000:0005', Signals::MANUAL, ['by_hand' => true]);
        app(Blocks::class)->block('::ffff:'.self::IP, Signals::MANUAL, ['by_hand' => true]);

        $this->assertEqualsCanonicalizing(['2001:db8:1:2::/64', self::IP], Block::pluck('ip')->all());
        $this->asVisitor('2001:db8:1:2::5')->get('http://localhost/')->assertStatus(429);
        $this->asVisitor()->get('http://localhost/')->assertStatus(429);
    }

    public function test_any_address_of_the_network_lets_the_network_back_in(): void
    {
        app(Blocks::class)->block('2001:db8:1:2::5', Signals::MANUAL, ['by_hand' => true]);

        $this->artisan('guard:unblock', ['address' => '2001:db8:1:2::77'])->assertSuccessful();

        $this->assertSame(0, Block::active()->count());
    }

    /** Allowing the office's one address and then blocking its network would block the office. */
    public function test_an_allowed_ipv6_address_covers_its_network(): void
    {
        $this->settings(Blocks::ON, ['block_allow' => '2001:db8:1:2::1']);

        $this->report(Signals::LOGIN, 3, '2001:db8:1:2::ff');
        $this->assertNull(app(Blocks::class)->block('2001:db8:1:2::abc', Signals::MANUAL, ['by_hand' => true]));

        $this->assertSame(0, Block::count());
    }

    public function test_a_network_can_be_blocked_by_hand_as_the_list_writes_it(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/guard/blocks', ['ip' => '2001:db8:9:9::/64'])
            ->assertSessionHasNoErrors();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/guard/blocks', ['ip' => 'not-an-address/64'])
            ->assertSessionHasErrors('ip');

        $this->assertSame(['2001:db8:9:9::/64'], Block::pluck('ip')->all());
    }
}
