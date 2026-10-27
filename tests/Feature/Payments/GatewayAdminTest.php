<?php

namespace Tests\Feature\Payments;

use App\Core\Support\Currencies;
use App\Models\User;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Payments\Models\Binding;

/**
 * Setting a gateway up — on the payment method, which is the only screen that
 * exists for it.
 *
 * The credential handling is the part worth pinning down: the form cannot show
 * a stored key back, so saving the page must not wipe one just because nobody
 * retyped it — a mistake that stays invisible until the next customer pays.
 */
class GatewayAdminTest extends TestCase
{
    protected User $admin;

    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        FakeGateway::reset();
        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        // The currency memo is a per-request singleton and tests share one
        // application, so it can still be holding rows an earlier test created
        // and rolled back — and the currency warnings below are read off it.
        app(Currencies::class)->flush();

        $this->admin = User::role('admin')->firstOrFail();
        $this->method = PaymentMethod::orderBy('id')->firstOrFail();
    }

    public function test_the_gateway_is_configured_on_the_payment_method_itself(): void
    {
        $this->actingAs($this->admin)
            ->get($this->url().'/edit')
            ->assertOk()
            // One screen: the method a customer picks and the gateway that
            // takes the money are set up in the same place.
            ->assertSee('Fake gateway')
            ->assertSee('gateway_config[fake][token]', false)
            ->assertSee('/payment/fake/callback');
    }

    /**
     * A gateway that cannot take the shop's currency says so on this screen.
     *
     * Prices and acquiring are two axes — a shop lists in dollars and is paid
     * in hryvnia, or the reverse — and nothing said a word about the
     * combination until a customer stood in front of a Pay button that could
     * not work (§48 of the log).
     */
    public function test_the_form_warns_when_the_gateway_cannot_take_the_shops_currency(): void
    {
        // A currency no shop has (ISO's own "for testing" code), so the case
        // under test is "none of them", whatever this shop happens to charge in.
        FakeGateway::$currencies = ['XTS'];

        // The sentence for "none of the shop's currencies", which is a
        // different case from "some of them" — and different from what the
        // real monobank block on the same page says about RUB.
        $this->actingAs($this->admin)
            ->get($this->url().'/edit')
            ->assertOk()
            ->assertSee('This gateway takes XTS, and this method accepts', false);
    }

    /** And the traps only the gateway knows about its own configuration. */
    public function test_the_form_shows_the_gateways_own_warning(): void
    {
        FakeGateway::$warnings = ['Holding the money works only in hryvnia.'];

        $this->actingAs($this->admin)
            ->get($this->url().'/edit')
            ->assertOk()
            ->assertSee('Holding the money works only in hryvnia.');
    }

    public function test_a_gateway_that_takes_everything_warns_about_nothing(): void
    {
        // The fake declares no currency list, so nothing about currency is
        // said for it. Asserted on the "none of them" sentence, which is the
        // one only a gateway with a list can produce — the page also carries
        // every other gateway's block, and a real one may have its own say.
        $this->actingAs($this->admin)
            ->get($this->url().'/edit')
            ->assertOk()
            ->assertDontSee('This gateway takes', false);
    }

    public function test_saving_the_method_wires_the_gateway(): void
    {
        $this->actingAs($this->admin)
            ->put($this->url(), $this->form([
                'gateway' => 'fake',
                'gateway_config' => ['fake' => ['token' => 'sk_live', 'hold' => '1']],
                'gateway_test_mode' => '1',
            ]))
            ->assertRedirect();

        $binding = Binding::firstOrFail();

        $this->assertSame('fake', $binding->gateway);
        $this->assertSame('sk_live', $binding->config['token']);
        $this->assertTrue($binding->config['hold']);
        $this->assertTrue($binding->test_mode);
    }

    public function test_saving_again_without_retyping_the_key_keeps_it(): void
    {
        Binding::create([
            'payment_method_id' => $this->method->id,
            'gateway' => 'fake',
            'config' => ['token' => 'sk_live'],
            'test_mode' => false,
        ]);

        $this->actingAs($this->admin)
            ->put($this->url(), $this->form([
                'gateway' => 'fake',
                'gateway_config' => ['fake' => ['token' => '']],
                'gateway_test_mode' => '1',
            ]))
            ->assertRedirect();

        $binding = Binding::firstOrFail();

        $this->assertSame('sk_live', $binding->config['token'], 'a blank credential means "keep it"');
        $this->assertTrue($binding->test_mode);
    }

    public function test_the_form_says_a_key_is_stored_without_showing_it(): void
    {
        Binding::create([
            'payment_method_id' => $this->method->id,
            'gateway' => 'fake',
            'config' => ['token' => 'sk_live_SECRET'],
        ]);

        // An empty password box otherwise reads as "it did not save" — which is
        // exactly how this was reported.
        $this->actingAs($this->admin)
            ->get($this->url().'/edit')
            ->assertOk()
            ->assertSee(__('saved — leave blank to keep it'))
            ->assertDontSee('sk_live_SECRET');
    }

    public function test_another_gateways_fields_are_ignored_on_save(): void
    {
        // Every gateway's fields are on the page at once, so the hidden ones are
        // posted too. Only the selected gateway's are read.
        $this->actingAs($this->admin)
            ->put($this->url(), $this->form([
                'gateway' => 'fake',
                'gateway_config' => [
                    'fake' => ['token' => 'mine'],
                    'someone_else' => ['token' => 'theirs'],
                ],
            ]))
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(['token' => 'mine', 'hold' => false], Binding::firstOrFail()->config);
    }

    public function test_unwiring_removes_the_binding_rather_than_blanking_it(): void
    {
        Binding::create(['payment_method_id' => $this->method->id, 'gateway' => 'fake', 'config' => ['token' => 'x']]);

        $this->actingAs($this->admin)->put($this->url(), $this->form(['gateway' => '']))->assertRedirect();

        $this->assertSame(0, Binding::count());
    }

    public function test_the_log_is_reached_from_the_payment_screen_not_the_sidebar(): void
    {
        // A menu entry of its own read as a second place to configure payments.
        $this->assertSame([], app(\App\Core\Modules\ModuleManager::class)
            ->find('torgash.payments')->adminMenu);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/payments/methods')
            ->assertOk()
            ->assertSee(route('admin.payments.log'), false);
    }

    public function test_the_log_renders_and_is_closed_without_the_permission(): void
    {
        $this->actingAs($this->admin)->get('http://localhost/admin/payments/log')->assertOk();

        $customer = User::create(['name' => 'Buyer', 'email' => 'buyer3@example.test', 'password' => 'secret123']);
        $this->actingAs($customer)->get('http://localhost/admin/payments/log')->assertForbidden();
    }

    /**
     * The method form posts the method's own fields too; a gateway section that
     * only works when they are absent would not survive a real save.
     *
     * @param  array<string, mixed>  $gateway
     * @return array<string, mixed>
     */
    protected function form(array $gateway): array
    {
        return array_merge([
            'name' => $this->method->name['en'] ?? 'Method',
            'position' => 0,
            'enabled' => '1',
        ], $gateway);
    }

    protected function url(): string
    {
        return 'http://localhost/admin/payments/methods/'.$this->method->id;
    }
}
