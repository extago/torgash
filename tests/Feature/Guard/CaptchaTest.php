<?php

namespace Tests\Feature\Guard;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Guard\Captcha\ArithmeticCaptcha;

/**
 * The CAPTCHA: a small sum drawn as a picture, and everything around it that
 * decides whether anybody is asked at all.
 *
 * The owner's design, and its reasoning is what the tests are about. The same
 * principle as an alphanumeric CAPTCHA — the text is rendered into an IMAGE,
 * not into HTML — but the alphabet is digits and three signs, which buys milder
 * distortion at the same resistance to a naive OCR bot and is far kinder to
 * read. Around it: per form, never for staff, never for a trusted customer
 * group, and the answer good exactly once.
 */
class CaptchaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('form:enquiry:ip:127.0.0.1');
        RateLimiter::clear('form:enquiry:id:asker@example.test');

        $settings = app(SettingsManager::class);
        $settings->set('torgash.guard', 'captcha_provider', ArithmeticCaptcha::key());
        $settings->set('torgash.guard', 'captcha_forms', ['enquiry']);
        $settings->set('torgash.guard', 'captcha_exempt_groups', []);
        $settings->set('torgash.guard', 'captcha_ttl', 10);
    }

    // ------------------------------------------------------------ the picture

    public function test_the_image_is_a_png_and_the_answer_never_reaches_the_page(): void
    {
        $nonce = 'abcdefghijklmnop';

        $response = $this->get('http://localhost/captcha/enquiry/'.$nonce.'.png');

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", $response->getContent());

        // Fetching the image is what CREATES the challenge: the answer is
        // written into the session, and the page never carries it.
        $stored = session('guard.captcha')['enquiry'][$nonce] ?? null;

        $this->assertIsArray($stored);
        $this->assertIsInt($stored['answer']);

        // The markup holds the nonce — which picture — and an empty box. Not
        // the sum, not the answer, and nothing derivable from either.
        $page = $this->get('http://localhost/')->getContent();

        $this->assertStringContainsString('name="captcha_id"', $page);
        $this->assertMatchesRegularExpression('/name="captcha"(?![^>]*\bvalue=)/', $page);
    }

    /** Never cached: a cached picture is a sum whose answer the session forgot. */
    public function test_the_image_is_not_cacheable(): void
    {
        $header = $this->get('http://localhost/captcha/enquiry/abcdefghijklmnop.png')
            ->headers->get('Cache-Control');

        $this->assertMatchesRegularExpression('/no-store|no-cache|max-age=0/', (string) $header);
    }

    public function test_only_a_real_form_name_and_a_real_nonce_are_drawn(): void
    {
        $this->get('http://localhost/captcha/made-up-form/abcdefghijklmnop.png')->assertNotFound();
        $this->get('http://localhost/captcha/enquiry/short.png')->assertNotFound();
    }

    // ------------------------------------------------------------- the widget

    public function test_the_widget_appears_only_on_a_form_the_shop_ticked(): void
    {
        $this->get('http://localhost/')
            ->assertOk()
            ->assertSee('captcha_id', false)
            ->assertSee('/captcha/enquiry/', false);

        app(SettingsManager::class)->set('torgash.guard', 'captcha_forms', []);

        $this->get('http://localhost/')
            ->assertOk()
            ->assertDontSee('captcha_id', false);
    }

    public function test_no_provider_chosen_means_no_widget_and_no_check(): void
    {
        app(SettingsManager::class)->set('torgash.guard', 'captcha_provider', '');

        $this->get('http://localhost/')->assertDontSee('captcha_id', false);

        // ...and the form still works, rather than refusing everybody for a box
        // that was never drawn.
        $this->submit([])->assertRedirect();
        $this->assertSame(1, Enquiry::count());
    }

    // ------------------------------------------------------------ the answer

    public function test_the_right_answer_lets_the_enquiry_through(): void
    {
        [$nonce, $answer] = $this->challenge();

        $this->submit(['captcha_id' => $nonce, 'captcha' => (string) $answer])->assertRedirect();

        $this->assertSame(1, Enquiry::count());
    }

    public function test_a_wrong_answer_refuses_the_form(): void
    {
        [$nonce, $answer] = $this->challenge();

        $this->submit(['captcha_id' => $nonce, 'captcha' => (string) ($answer + 1)])
            ->assertSessionHasErrors('enquiry');

        $this->assertSame(0, Enquiry::count());
    }

    public function test_no_answer_at_all_refuses_the_form(): void
    {
        $this->challenge();

        $this->submit([])->assertSessionHasErrors('enquiry');

        $this->assertSame(0, Enquiry::count());
    }

    /**
     * A wrong answer has to be VISIBLE, and it must not cost them what they
     * typed.
     *
     * The form is in a dialog and swaps itself for the answer; HTMX does not
     * swap a 4xx at all, so the honest 422 meant a mistyped sum looked exactly
     * like a form that did nothing. This is the case that found it — before a
     * CAPTCHA, a refusal on this path was a honeypot (answered as a success)
     * or a rate limit, and neither is an everyday event.
     */
    public function test_a_wrong_answer_comes_back_as_the_form_with_the_reason_and_what_was_typed(): void
    {
        [$nonce, $answer] = $this->challenge();

        $response = $this->withHeaders(['HX-Request' => 'true'])->post('http://localhost/enquiry', [
            'kind' => 'callback',
            'name' => 'Иван',
            'phone' => '+380671234567',
            'email' => 'asker@example.test',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
            'captcha_id' => $nonce,
            'captcha' => (string) ($answer + 1),
        ]);

        // 200 rather than 422: a 4xx is not swapped, and an invisible refusal
        // is indistinguishable from a broken button.
        $response->assertOk();
        $response->assertSee('<form', false);
        $response->assertSee('right answer to the sum', false);
        $response->assertSee('value="Иван"', false);
        $response->assertSee('+380671234567', false);

        // ...and the picture in it is a NEW one: the old answer was spent by
        // the attempt, so the form that comes back must not point at it.
        $response->assertDontSee('value="'.$nonce.'"', false);

        $this->assertSame(0, Enquiry::count());
    }

    /**
     * One use. Somebody who pays a cent to have one solved gets one submission
     * for it, not a thousand — and it is also what makes "a new sum after a
     * mistake" automatic rather than a feature to remember.
     */
    public function test_an_answer_works_exactly_once(): void
    {
        [$nonce, $answer] = $this->challenge();

        $this->submit(['captcha_id' => $nonce, 'captcha' => (string) $answer])->assertRedirect();

        RateLimiter::clear('form:enquiry:ip:127.0.0.1');
        RateLimiter::clear('form:enquiry:id:asker@example.test');

        $this->submit(['captcha_id' => $nonce, 'captcha' => (string) $answer])
            ->assertSessionHasErrors('enquiry');

        $this->assertSame(1, Enquiry::count());
    }

    public function test_an_answer_expires(): void
    {
        [$nonce, $answer] = $this->challenge();

        $this->travel(11)->minutes();

        $this->submit(['captcha_id' => $nonce, 'captcha' => (string) $answer])
            ->assertSessionHasErrors('enquiry');
    }

    // ------------------------------------------------------------ who is asked

    /** Somebody in the admin has proved more about themselves than a sum could. */
    public function test_staff_are_never_asked(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->get('http://localhost/')->assertDontSee('captcha_id', false);

        $this->submit([])->assertRedirect();
        $this->assertSame(1, Enquiry::count());
    }

    public function test_a_trusted_customer_group_is_never_asked(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 0]);

        $trusted = User::create([
            'name' => 'Оптовик', 'email' => 'wholesale@example.test',
            'password' => 'secret123', 'customer_group_id' => $group->id,
        ]);

        app(SettingsManager::class)->set('torgash.guard', 'captcha_exempt_groups', [$group->id]);

        $this->actingAs($trusted);

        $this->get('http://localhost/')->assertDontSee('captcha_id', false);
        $this->submit([])->assertRedirect();

        $this->assertSame(1, Enquiry::count());
    }

    /** ...and an ordinary signed-in customer still is. */
    public function test_an_ordinary_customer_is_still_asked(): void
    {
        $customer = User::create(['name' => 'Иван', 'email' => 'buyer@example.test', 'password' => 'secret123']);

        $this->actingAs($customer);

        $this->get('http://localhost/')->assertSee('captcha_id', false);
        $this->submit([])->assertSessionHasErrors('enquiry');

        $this->assertSame(0, Enquiry::count());
    }

    // -------------------------------------------------------------- fixtures

    /**
     * Ask for a picture the way a browser does, and read back what it decided
     * the answer is.
     *
     * @return array{0: string, 1: int}
     */
    protected function challenge(string $form = 'enquiry'): array
    {
        $nonce = 'abcdefghijklmnop';

        $this->get('http://localhost/captcha/'.$form.'/'.$nonce.'.png')->assertOk();

        return [$nonce, (int) session('guard.captcha')[$form][$nonce]['answer']];
    }

    /** @param array<string, string> $extra */
    protected function submit(array $extra)
    {
        return $this->post('http://localhost/enquiry', array_merge([
            'kind' => 'callback',
            'name' => 'Иван',
            'phone' => '+380671234567',
            'email' => 'asker@example.test',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ], $extra));
    }
}
