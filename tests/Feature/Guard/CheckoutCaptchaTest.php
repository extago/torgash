<?php

namespace Tests\Feature\Guard;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Guard\Captcha\ArithmeticCaptcha;

/**
 * A CAPTCHA on placing an order — the owner's question (2026-10-27: "is there
 * one for guest orders?"). Nothing until the shop ticks the form; then one sum
 * per ORDER, so an order sent back for a missing field does not ask a second
 * one; and none of core's stamp, trap or rate limits, which a checkout cannot
 * take (FormGuard::checkAdded()).
 */
class CheckoutCaptchaTest extends TestCase
{
    protected PaymentMethod $cash;

    protected int $ordersBefore;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $settings = app(SettingsManager::class);
        $settings->set('torgash.guard', 'captcha_provider', ArithmeticCaptcha::key());
        $settings->set('torgash.guard', 'captcha_forms', ['checkout']);
        $settings->set('torgash.guard', 'captcha_exempt_groups', []);
        $settings->set('torgash.guard', 'captcha_ttl', 10);

        $this->cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -1]);
        Product::create(['sku' => 'CC-SVC', 'name' => ['en' => 'Installation'], 'slug' => 'cc-installation', 'price' => 300_00, 'stock' => 0, 'status' => 'active', 'availability_status' => 'unlimited', 'needs_delivery' => false]);

        $this->ordersBefore = Order::count();
    }

    /** The settings screen offers it, and the picture's address knows it. */
    public function test_the_checkout_is_a_form_the_captcha_can_be_put_on(): void
    {
        $this->assertArrayHasKey('checkout', FormGuard::forms());

        $this->get('http://localhost/captcha/checkout/abcdefghijklmnop.png')->assertOk();
    }

    /**
     * Not ticked, not asked — and core's own layers are not there either: an
     * order needs no stamp, and an autofill that lands in the trap does not
     * drop it in silence.
     */
    public function test_nothing_stands_in_front_of_an_order_until_the_shop_ticks_it(): void
    {
        app(SettingsManager::class)->set('torgash.guard', 'captcha_forms', ['enquiry']);
        $this->basket();

        $this->get('http://localhost/checkout')->assertOk()->assertDontSee('captcha/checkout/', false);

        $this->order([FormGuard::TRAP => 'autofilled'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->placed());
    }

    public function test_an_order_without_the_right_answer_is_not_placed(): void
    {
        $this->basket();

        $this->get('http://localhost/checkout')->assertOk()->assertSee('captcha/checkout/', false);
        [$nonce, $answer] = $this->challenge();

        $this->order()->assertSessionHasErrors('captcha');
        $this->order(['captcha_id' => $nonce, 'captcha' => (string) ($answer + 1)])->assertSessionHasErrors('captcha');

        $this->assertSame(0, $this->placed());
    }

    /**
     * One sum per ORDER: an order sent back for something else keeps the right
     * answer and the page stops asking; the corrected order goes through with
     * no second sum, and the next order is asked again.
     */
    public function test_a_right_answer_lasts_until_the_order_is_placed(): void
    {
        $this->basket();
        [$nonce, $answer] = $this->challenge();

        $this->order(['captcha_id' => $nonce, 'captcha' => (string) $answer, 'email' => ''])
            ->assertSessionHasErrors('email')
            ->assertSessionDoesntHaveErrors('captcha');

        $this->get('http://localhost/checkout')->assertOk()->assertDontSee('captcha/checkout/', false);
        $this->order()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->placed());

        $this->basket();
        $this->get('http://localhost/checkout')->assertOk()->assertSee('captcha/checkout/', false);
        $this->order()->assertSessionHasErrors('captcha');
        $this->assertSame(1, $this->placed());
    }

    /** ...but no longer than an answer lives. */
    public function test_a_kept_answer_runs_out_with_the_answers_minutes(): void
    {
        $this->basket();
        [$nonce, $answer] = $this->challenge();

        $this->order(['captcha_id' => $nonce, 'captcha' => (string) $answer, 'email' => ''])->assertSessionHasErrors('email');

        $this->travel(11)->minutes();

        $this->order()->assertSessionHasErrors('captcha');
        $this->assertSame(0, $this->placed());
    }

    /** The checkout asks the same gate as every other form. */
    public function test_staff_are_never_asked_at_the_checkout(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $this->basket();

        $this->get('http://localhost/checkout')->assertOk()->assertDontSee('captcha/checkout/', false);
        $this->order()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->placed());
    }

    // -------------------------------------------------------------- fixtures

    protected function basket(): void
    {
        $this->post('http://localhost/cart/add/cc-installation', ['qty' => 1])->assertOk();
    }

    /**
     * Ask for a picture the way a browser does, and read back what it decided
     * the answer is.
     *
     * @return array{0: string, 1: int}
     */
    protected function challenge(): array
    {
        $nonce = 'abcdefghijklmnop';

        $this->get('http://localhost/captcha/checkout/'.$nonce.'.png')->assertOk();

        return [$nonce, (int) session('guard.captcha')['checkout'][$nonce]['answer']];
    }

    /** @param array<string, string> $extra */
    protected function order(array $extra = [])
    {
        return $this->post('http://localhost/checkout', array_merge([
            'customer_name' => 'Buyer',
            'email' => 'guest@example.test',
            'payment_method_id' => (string) $this->cash->id,
        ], $extra));
    }

    protected function placed(): int
    {
        return Order::count() - $this->ordersBefore;
    }
}
