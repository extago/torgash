<?php

namespace Tests\Feature\Enquiries;

use App\Core\Hooks\HookBus;
use App\Core\Privacy\PersonalData;
use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Torgash\Checkout\Orders\ClientFacts as OrderFacts;
use Torgash\Enquiries\ClientFacts;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Privacy\Retention;

/**
 * An enquiry writes down the browser it came from the way an order does, under
 * the shop's own ticks — and the card can say what else came from that address.
 */
class EnquiryClientFactsTest extends TestCase
{
    private const IP = '203.0.113.60';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('form:enquiry:ip:'.self::IP);
    }

    public function test_the_address_and_browser_are_kept_and_shown(): void
    {
        $this->send('first@example.test');
        $this->send('second@example.test');

        $enquiry = Enquiry::latest('id')->firstOrFail();
        $this->assertSame(self::IP, $enquiry->ip_address);
        $this->assertSame('TestBrowser/1.0', $enquiry->client['user_agent']);
        $this->assertSame('uk-UA,uk;q=0.9,en;q=0.8', $enquiry->client['languages']);

        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertSee(self::IP)
            ->assertSee('1 more enquiry from here')
            ->assertSee('TestBrowser/1.0')
            ->assertSee('uk-UA, uk, en')
            ->assertSee('admin/guard/blocks?block='.self::IP, false);

        // Across the tabs: a finished one from the same address still counts.
        Enquiry::whereKeyNot($enquiry->id)->where('ip_address', self::IP)->update(['status' => 'done']);

        $this->actingAs($admin)->get('http://localhost/admin/enquiries?view=all&q='.self::IP)
            ->assertOk()
            ->assertSee('first@example.test');
    }

    public function test_what_the_shop_unticks_is_not_recorded_and_orders_keep_their_own_ticks(): void
    {
        app(SettingsManager::class)->set('torgash.enquiries', 'client_facts', ['languages']);

        $this->send('asker@example.test');

        $enquiry = Enquiry::latest('id')->firstOrFail();
        $this->assertNull($enquiry->ip_address);
        $this->assertSame(['languages' => 'uk-UA,uk;q=0.9,en;q=0.8'], $enquiry->client);

        $this->assertContains('ip_address', app(OrderFacts::class)->enabled());
    }

    public function test_a_fact_from_a_module_reaches_both_and_cannot_name_a_column(): void
    {
        app(HookBus::class)->listen('core.client_facts', fn (array $facts) => $facts + [
            'campaign' => ['label' => 'Campaign', 'capture' => fn () => 'spring', 'column' => 'no_such_column'],
        ]);
        app(SettingsManager::class)->set('torgash.enquiries', 'client_facts', ['campaign']);

        $this->send('asker@example.test');

        $this->assertSame(['campaign' => 'spring'], Enquiry::latest('id')->firstOrFail()->client);
        $this->assertArrayHasKey('campaign', app(OrderFacts::class)->all());
        $this->assertArrayHasKey('campaign', app(ClientFacts::class)->options());
    }

    public function test_the_settings_screen_offers_the_facts(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/settings/torgash.enquiries')
            ->assertOk()
            ->assertSee('Browser and device');
    }

    public function test_the_address_expires_after_the_shops_days(): void
    {
        $old = $this->row('old@example.test', '10.0.0.1');
        $old->forceFill(['created_at' => now()->subDays(200)])->saveQuietly();
        $recent = $this->row('recent@example.test', '10.0.0.2');

        app(SettingsManager::class)->set('torgash.privacy', 'retention', ['enquiry_ip' => 180]);
        app(Retention::class)->run();

        $this->assertNull($old->fresh()->ip_address);
        $this->assertNull($old->fresh()->client);
        $this->assertSame('old@example.test', $old->fresh()->email, 'only the address expires');
        $this->assertSame('10.0.0.2', $recent->fresh()->ip_address);
        $this->assertArrayHasKey('enquiry_ip', app(PersonalData::class)->retained());
    }

    private function send(string $email): void
    {
        $this->withHeaders(['User-Agent' => 'TestBrowser/1.0', 'Accept-Language' => 'uk-UA,uk;q=0.9,en;q=0.8'])
            ->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->post('http://localhost/enquiry', [
                'kind' => 'message',
                'name' => 'Иван',
                'email' => $email,
                'message' => 'Вопрос',
                FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
                FormGuard::TRAP => '',
            ])->assertRedirect();
    }

    private function row(string $email, string $ip): Enquiry
    {
        return Enquiry::create([
            'kind' => 'message',
            'email' => $email,
            'ip_address' => $ip,
            'client' => ['user_agent' => 'Old'],
        ]);
    }
}
