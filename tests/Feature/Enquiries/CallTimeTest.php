<?php

namespace Tests\Feature\Enquiries;

use App\Core\Support\FormGuard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Torgash\Enquiries\CallTime;
use Torgash\Enquiries\Models\Enquiry;

/**
 * "When to call" (CallTime): a part of the day or the person's own hours,
 * by their own clock, which the form takes from the browser — and staff are
 * told what that is by the shop's clock.
 */
class CallTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('form:enquiry:ip:127.0.0.1');
        config(['app.timezone' => 'Europe/Moscow']);
    }

    /** @param array<string, mixed> $time */
    protected function send(array $time): \Illuminate\Testing\TestResponse
    {
        return $this->post('http://localhost/enquiry', [
            'kind' => 'callback',
            'phone' => '+7 (916) 123-45-67',
            'time' => $time,
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ]);
    }

    public function test_own_hours_by_their_clock_are_kept_and_told_to_staff_by_the_shops(): void
    {
        $this->send(['when' => 'custom', 'from' => '10:00', 'to' => '12:00', 'zone' => 'Asia/Vladivostok'])->assertSessionHasNoErrors();

        $enquiry = Enquiry::latest('id')->firstOrFail();
        // jsonb sorts the keys; what is kept is what matters.
        $kept = $enquiry->fields['values']['time'];
        ksort($kept);
        $this->assertSame(['from' => '10:00', 'to' => '12:00', 'when' => 'custom', 'zone' => 'Asia/Vladivostok'], $kept);
        $this->assertSame('custom', $enquiry->answer('time'));

        $answer = collect($enquiry->answers())->firstWhere('key', 'time');
        $this->assertSame('From 10:00 to 12:00', $answer['value']);
        // Vladivostok is seven hours ahead of Moscow.
        $this->assertStringContainsString('03:00–05:00 by your clock', $answer['note']);
        $this->assertStringContainsString('(+7 h)', $answer['note']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get('http://localhost/admin/enquiries/'.$enquiry->id)->assertOk()
            ->assertSee('From 10:00 to 12:00')
            ->assertSee('03:00–05:00 by your clock');
    }

    public function test_a_part_of_the_day_and_a_bare_choice_still_read(): void
    {
        $this->send(['when' => 'evening', 'zone' => 'Europe/Moscow'])->assertSessionHasNoErrors();
        $answer = collect(Enquiry::latest('id')->firstOrFail()->answers())->firstWhere('key', 'time');
        $this->assertSame('Evening', $answer['value']);
        $this->assertNull($answer['note'], 'the same clock as the shop: nothing to convert');

        // An older form, an API client: "evening" alone.
        RateLimiter::clear('form:enquiry:ip:127.0.0.1');
        $this->post('http://localhost/enquiry', [
            'kind' => 'callback', 'phone' => '+7 (916) 123-45-68', 'time' => 'evening',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)), FormGuard::TRAP => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame('evening', Enquiry::latest('id')->firstOrFail()->answer('time'));
    }

    public function test_own_hours_with_no_hours_are_refused(): void
    {
        $this->send(['when' => 'custom', 'zone' => 'Asia/Vladivostok'])->assertSessionHasErrors('time');
        $this->assertSame(0, Enquiry::count());
    }

    public function test_the_note_names_the_time_there_now(): void
    {
        $call = CallTime::from(['when' => 'morning', 'zone' => 'Asia/Novosibirsk']);
        $note = $call->staffNote(CarbonImmutable::parse('2026-10-31 20:00', 'Europe/Moscow'));

        // Novosibirsk is four hours ahead: morning there (09–12) is 05–08 in Moscow.
        $this->assertSame('05:00–08:00 by your clock · it is 00:00 there now (+4 h)', $note);
        $this->assertNull(CallTime::from(['when' => 'morning', 'zone' => 'Not/AZone'])->staffNote());
    }

    public function test_the_form_offers_own_hours_and_takes_the_clock_from_the_browser(): void
    {
        $this->get('http://localhost/');
        $html = view('torgash.enquiries::partials.form', ['enquiryKind' => 'callback', 'errors' => new \Illuminate\Support\ViewErrorBag])->render();

        $this->assertStringContainsString('name="time[when]"', $html);
        $this->assertStringContainsString('value="custom"', $html);
        $this->assertStringContainsString('name="time[from]"', $html);
        $this->assertStringContainsString('resolvedOptions().timeZone', $html);
    }
}
