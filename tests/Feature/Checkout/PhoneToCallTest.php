<?php

namespace Tests\Feature\Checkout;

use App\Core\Access\Impersonation;
use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Core\Support\Phones;
use App\Core\Verification\FormVerification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ZoneMatcher;

/**
 * The number a customer left to be rung on (`users.contact_phone`): kept from
 * the profile or from their first order, given back by every form that asks,
 * and worth nothing as proof of who they are.
 */
class PhoneToCallTest extends TestCase
{
    protected const NUMBER = '+380935550107';

    protected const OTHER = '+380671234567';

    protected User $customer;

    protected PaymentMethod $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('form:one_click:ip:127.0.0.1');
        RateLimiter::clear('form:enquiry:ip:127.0.0.1');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->customer = User::factory()->create([
            'name' => 'Olha Buyer', 'email' => 'olha@phone-to-call.test', 'password' => Hash::make('a-good-password'),
        ]);
        $this->payment = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => 99]);

        Product::create(['sku' => 'PC-1', 'name' => ['en' => 'Helmet'], 'slug' => 'pc-helmet', 'price' => 10_00, 'stock' => 9, 'status' => 'active']);
    }

    // --------------------------------------------------------------- the profile

    public function test_the_profile_keeps_a_number_with_no_code_asked(): void
    {
        $this->actingAs($this->customer)->get('http://localhost/account')->assertOk()->assertSee(__('Phone for calls'));

        $this->actingAs($this->customer)
            ->put('http://localhost/account/profile/phone', ['contact_phone' => '+380 (93) 555-01-07'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(self::NUMBER, $this->customer->fresh()->contact_phone);
        $this->assertNull($this->customer->fresh()->phone, 'the phone that signs in is written only after a code');

        $this->actingAs($this->customer->fresh())->get('http://localhost/account')->assertSee(Phones::display(self::NUMBER));
    }

    public function test_the_profile_refuses_what_is_no_number_and_forgets_an_emptied_one(): void
    {
        $this->customer->forceFill(['contact_phone' => self::NUMBER])->save();

        $this->actingAs($this->customer)
            ->put('http://localhost/account/profile/phone', ['contact_phone' => '12'])
            ->assertSessionHasErrors('contact_phone');
        $this->assertSame(self::NUMBER, $this->customer->fresh()->contact_phone);

        $this->actingAs($this->customer)
            ->put('http://localhost/account/profile/phone', ['contact_phone' => ''])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->customer->fresh()->contact_phone);
    }

    public function test_a_proven_phone_needs_no_second_one(): void
    {
        $this->customer->forceFill(['phone' => self::NUMBER, 'phone_verified_at' => now()])->save();

        $this->actingAs($this->customer)->get('http://localhost/account')->assertOk()->assertDontSee(__('Phone for calls'));
    }

    // --------------------------------------------------------- what it is not

    public function test_two_accounts_may_leave_one_number(): void
    {
        $this->customer->forceFill(['contact_phone' => self::NUMBER])->save();

        $other = User::factory()->create(['email' => 'second@phone-to-call.test']);
        $this->actingAs($other)
            ->put('http://localhost/account/profile/phone', ['contact_phone' => self::NUMBER])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::NUMBER, $other->fresh()->contact_phone);
    }

    public function test_it_signs_in_to_nothing(): void
    {
        $this->customer->forceFill(['contact_phone' => self::NUMBER])->save();

        $this->post('http://localhost/account/login', ['email' => self::NUMBER, 'password' => 'a-good-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_it_is_confirmed_by_nothing(): void
    {
        $this->customer->forceFill(['contact_phone' => self::NUMBER])->save();

        $request = Request::create('http://localhost/');
        $request->setUserResolver(fn () => $this->customer);

        $this->assertFalse(app(FormVerification::class)->verified($request, 'phone', self::NUMBER));
    }

    // ------------------------------------------------------------- from an order

    public function test_the_first_order_leaves_its_number(): void
    {
        $this->order(['phone' => '093 555 01 07'])->assertRedirect();

        $this->assertSame(self::NUMBER, $this->customer->fresh()->contact_phone, 'read by the country the form names');
    }

    public function test_a_later_order_does_not_move_it(): void
    {
        $this->order(['phone' => self::NUMBER])->assertRedirect();
        $this->order(['phone' => self::OTHER])->assertRedirect();

        $this->assertSame(self::NUMBER, $this->customer->fresh()->contact_phone, 'the next parcel may be somebody else\'s');
    }

    public function test_what_is_no_number_is_not_kept(): void
    {
        $this->order(['phone' => 'after six, ask for Ira'])->assertRedirect();

        $this->assertSame(1, Order::where('user_id', $this->customer->id)->count());
        $this->assertNull($this->customer->fresh()->contact_phone);
    }

    public function test_an_account_with_a_proven_phone_keeps_no_other(): void
    {
        $this->customer->forceFill(['phone' => self::NUMBER, 'phone_verified_at' => now()])->save();

        $this->order(['phone' => self::OTHER])->assertRedirect();

        $this->assertNull($this->customer->fresh()->contact_phone);
    }

    public function test_a_one_click_order_leaves_its_number_too(): void
    {
        $this->oneClickOn();

        $this->actingAs($this->customer)->post('http://localhost/one-click', [
            'product_id' => Product::where('slug', 'pc-helmet')->value('id'),
            'phone' => self::NUMBER,
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ])->assertRedirect();

        $this->assertSame(self::NUMBER, $this->customer->fresh()->contact_phone);
    }

    public function test_a_manager_looking_as_the_customer_leaves_nothing_behind(): void
    {
        $role = Role::findOrCreate('phone-desk');
        $role->givePermissionTo(['admin.access', 'customers.manage', Impersonation::PERMISSION]);
        $manager = User::factory()->create();
        $manager->assignRole($role);

        $this->inside($manager)->post('http://localhost/cart/add/pc-helmet', ['qty' => 1])->assertOk();
        $this->inside($manager)->post('http://localhost/checkout', $this->form(['phone' => self::OTHER]))->assertRedirect();

        $this->assertSame($this->customer->id, Order::latest('id')->value('user_id'));
        $this->assertNull($this->customer->fresh()->contact_phone, 'a manager may type anybody\'s number');
    }

    // ------------------------------------------------------- forms start with it

    public function test_the_checkout_starts_with_the_accounts_number(): void
    {
        $this->customer->forceFill(['contact_phone' => self::NUMBER])->save();

        $this->actingAs($this->customer)->post('http://localhost/cart/add/pc-helmet', ['qty' => 1])->assertOk();

        $this->actingAs($this->customer)->get('http://localhost/checkout')
            ->assertOk()->assertSee('value="'.Phones::display(self::NUMBER).'"', false);
    }

    public function test_a_proven_phone_reaches_the_checkout(): void
    {
        $this->customer->forceFill(['phone' => self::NUMBER, 'phone_verified_at' => now()])->save();

        $this->actingAs($this->customer)->post('http://localhost/cart/add/pc-helmet', ['qty' => 1])->assertOk();

        $this->actingAs($this->customer)->get('http://localhost/checkout')
            ->assertOk()->assertSee('value="'.Phones::display(self::NUMBER).'"', false);
    }

    public function test_the_default_addresss_own_number_goes_first(): void
    {
        $this->customer->forceFill(['contact_phone' => self::NUMBER])->save();
        $this->customer->addresses()->create([
            'full_name' => 'Olha Buyer', 'phone' => self::OTHER, 'address_line' => '1 Lane', 'city' => 'Lviv', 'is_default' => true,
        ]);

        $this->actingAs($this->customer)->post('http://localhost/cart/add/pc-helmet', ['qty' => 1])->assertOk();

        $this->actingAs($this->customer)->get('http://localhost/checkout')
            ->assertOk()
            ->assertSee('value="'.self::OTHER.'"', false)
            ->assertDontSee('value="'.Phones::display(self::NUMBER).'"', false);
    }

    public function test_one_click_starts_with_the_name_and_the_number_for_their_owner_alone(): void
    {
        $this->oneClickOn();
        $this->customer->forceFill(['contact_phone' => self::NUMBER])->save();

        $this->get('http://localhost/product/pc-helmet')->assertOk()->assertDontSee(Phones::display(self::NUMBER));

        $this->actingAs($this->customer)->get('http://localhost/product/pc-helmet')
            ->assertOk()
            ->assertSee('value="'.Phones::display(self::NUMBER).'"', false)
            ->assertSee('value="Olha Buyer"', false);
    }

    public function test_a_callback_is_asked_for_without_typing_the_number(): void
    {
        $this->customer->forceFill(['contact_phone' => self::NUMBER])->save();
        $this->actingAs($this->customer);

        $html = view('torgash.enquiries::partials.form', ['enquiryKind' => 'callback', 'errors' => new ViewErrorBag])->render();
        $this->assertStringContainsString('name="contact_from_account" value="1"', $html);
        $this->assertStringContainsString(self::NUMBER, $html);

        $this->post('http://localhost/enquiry', [
            'kind' => 'callback',
            'time' => 'evening',
            'contact_from_account' => '1',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(self::NUMBER, Enquiry::latest('id')->firstOrFail()->phone);
    }

    // ---------------------------------------------------------- the shop's side

    public function test_a_manager_sees_it_rings_it_and_finds_the_customer_by_it(): void
    {
        $this->customer->forceFill(['contact_phone' => self::NUMBER])->save();
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/customers/'.$this->customer->id)
            ->assertOk()
            ->assertSee(Phones::display(self::NUMBER))
            ->assertSee('tel:'.self::NUMBER, false);

        $this->actingAs($admin)->get('http://localhost/admin/customers?q=935550107')
            ->assertOk()
            ->assertSee('olha@phone-to-call.test');

        // ...and so does picking the customer for a new order, which starts with it.
        $this->actingAs($admin)->get('http://localhost/admin/orders/create/customers?q=935550107')
            ->assertOk()
            ->assertSee('olha@phone-to-call.test')
            ->assertSee(self::NUMBER);
    }

    public function test_it_goes_out_with_the_accounts_data(): void
    {
        $this->customer->forceFill(['contact_phone' => self::NUMBER])->save();

        $export = app(PersonalData::class)->export(Subject::of($this->customer));

        $this->assertSame(self::NUMBER, $export[label_text('Account', null, '?')][0]['contact_phone']);
    }

    // ------------------------------------------------------------------- helpers

    protected function inside(User $manager): static
    {
        $this->app->forgetInstance(CartManager::class);

        return $this->actingAs($manager)->withSession([
            Impersonation::SESSION => ['manager' => $manager->id, 'customer' => $this->customer->id],
        ]);
    }

    protected function oneClickOn(): void
    {
        $shipping = ShippingMethod::create(['name' => ['en' => 'Arrange'], 'price_mode' => 'quote', 'position' => 90, 'enabled' => true]);
        $shipping->rates()->create(['zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true]);
        $payment = PaymentMethod::create(['name' => ['en' => 'Agree'], 'enabled' => true, 'position' => 90]);

        $settings = app(SettingsManager::class);
        $settings->set('torgash.checkout', 'one_click', true);
        $settings->set('torgash.checkout', 'one_click_shipping_id', (string) $shipping->id);
        $settings->set('torgash.checkout', 'one_click_payment_id', $payment->id);
    }

    protected function order(array $overrides)
    {
        $this->actingAs($this->customer)->post('http://localhost/cart/add/pc-helmet', ['qty' => 1])->assertOk();

        return $this->actingAs($this->customer)->post('http://localhost/checkout', $this->form($overrides));
    }

    protected function form(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Olha Buyer',
            'email' => 'olha@phone-to-call.test',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'country' => 'UA',
            'postal_code' => '01001',
            'payment_method_id' => $this->payment->id,
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
        ];
    }
}
