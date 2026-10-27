<?php

namespace Tests\Feature\Automations;

use App\Core\Support\FormGuard;
use App\Core\Verification\Channels;
use App\Core\Verification\SendResult;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Automations\Codes\HttpCodeChannel;
use Torgash\Automations\Codes\Presets;
use Torgash\Automations\Models\CodeChannel;

/**
 * A verification code delivered by an HTTP request the shop composed
 * (PLAN §44.3): the request is what the row says, the answer is read by the
 * row's rules into the four outcomes the customer is told about, and a
 * secret never appears in anything kept or shown. No request leaves the
 * test — the provider is faked.
 */
class CodeChannelTest extends TestCase
{
    protected function turbo(array $overrides = []): CodeChannel
    {
        $preset = Presets::all()['turbosms'];

        return CodeChannel::create([
            ...collect($preset)->except('secrets')->all(),
            'secrets' => ['token' => 'sekret-token-123'],
            'enabled' => true,
            'daily_limit' => 50,
            ...$overrides,
        ]);
    }

    protected function send(CodeChannel $row, string $phone = '+380671234567'): SendResult
    {
        return (new HttpCodeChannel($row))->send('phone', $phone, ['code' => '123456', 'text' => '123456 — ваш код']);
    }

    public function test_the_turbosms_preset_sends_what_the_provider_expects(): void
    {
        Http::fake(['api.turbosms.ua/*' => Http::response([
            'response_code' => 800, 'response_status' => 'SUCCESS_MESSAGE_ACCEPTED',
            'response_result' => [['phone' => '380671234567', 'response_code' => 0, 'response_status' => 'OK']],
        ])]);

        $this->assertTrue($this->send($this->turbo())->ok());

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === 'https://api.turbosms.ua/message/send.json'
                && $request->hasHeader('Authorization', 'Bearer sekret-token-123')
                && $request['recipients'] === ['380671234567']
                && $request['sms']['text'] === '123456 — ваш код';
        });
    }

    public function test_the_answer_is_read_into_the_four_outcomes(): void
    {
        $row = $this->turbo();

        Http::fakeSequence('api.turbosms.ua/*')
            ->push(['response_code' => 800, 'response_result' => [['response_code' => 305, 'response_status' => 'INVALID_PHONE']]])
            ->push(['response_code' => 406, 'response_status' => 'NOT_ALLOWED_RECIPIENT_COUNTRY'])
            ->push(['response_code' => 203, 'response_status' => 'REQUIRED_BALANCE'])
            ->push('Bad gateway', 502);

        $this->assertSame(SendResult::INVALID_ADDRESS, $this->send($row)->status);
        $this->assertSame(SendResult::UNREACHABLE, $this->send($row)->status);
        $this->assertSame(SendResult::TEMPORARY, $this->send($row)->status, 'no balance is the provider failing, not the number');
        $this->assertSame(SendResult::TEMPORARY, $this->send($row)->status);
    }

    public function test_a_secret_never_reaches_the_log(): void
    {
        $row = $this->turbo(['url' => 'https://api.turbosms.ua/message/send.json?token={{ secret.token }}']);
        Http::fake(['api.turbosms.ua/*' => Http::response(['echo' => 'your token is sekret-token-123'], 500)]);

        $result = $this->send($row);

        $this->assertSame(SendResult::TEMPORARY, $result->status);
        $this->assertStringNotContainsString('sekret-token-123', (string) $result->error);
    }

    public function test_countries_and_the_day_decide_what_is_offered(): void
    {
        $row = $this->turbo(['countries' => 'UA', 'daily_limit' => 2]);
        $channels = app(Channels::class);

        $this->assertArrayHasKey($row->key(), $channels->for('phone', '+380671234567'));
        $this->assertArrayNotHasKey($row->key(), $channels->for('phone', '+48501234567'), 'a country it does not serve');

        VerificationCode::create([
            'purpose' => 'sign_in', 'kind' => 'phone', 'address' => '+380671234567', 'channel' => $row->key(),
            'code_hash' => 'x', 'browser_hash' => 'x', 'sends' => 2, 'last_sent_at' => now(), 'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertArrayNotHasKey($row->key(), $channels->for('phone', '+380671234567'), 'the day is used up');
    }

    public function test_a_private_host_is_refused_before_anything_is_sent(): void
    {
        Http::fake();
        $row = $this->turbo(['url' => 'http://127.0.0.1/send']);

        $this->assertSame(SendResult::TEMPORARY, $this->send($row)->status);
        Http::assertNothingSent();
    }

    public function test_the_form_keeps_a_secret_left_blank_and_never_draws_it(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $row = $this->turbo();

        $this->actingAs($admin)->get('http://localhost/admin/automations/channels/'.$row->id.'/edit')
            ->assertOk()
            ->assertSee('token=')
            ->assertDontSee('sekret-token-123');

        $this->actingAs($admin)->put('http://localhost/admin/automations/channels/'.$row->id, [
            ...collect(Presets::all()['turbosms'])->except('secrets')->all(),
            'name' => 'SMS',
            'secrets' => "token=\nsender=MAGAZIN",
            'daily_limit' => 10,
            'enabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['token' => 'sekret-token-123', 'sender' => 'MAGAZIN'], $row->fresh()->secrets);
    }

    public function test_the_admins_test_goes_through_the_verifier_and_its_log(): void
    {
        Http::fake(['api.turbosms.ua/*' => Http::response([
            'response_code' => 800, 'response_result' => [['response_code' => 0]],
        ])]);
        $row = $this->turbo(['enabled' => false]);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/automations/channels/'.$row->id.'/test', ['address' => '380671234567'])
            ->assertSessionHas('status');

        $code = VerificationCode::latest('id')->first();
        $this->assertSame(['channel_test', $row->key(), '+380671234567', 'sent'], [$code->purpose, $code->channel, $code->address, $code->status]);
        $this->assertFalse($row->fresh()->enabled, 'testing does not switch it on');
    }
}
