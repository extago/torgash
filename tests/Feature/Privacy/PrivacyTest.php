<?php

namespace Tests\Feature\Privacy;

use App\Core\Privacy\PersonalData;
use App\Core\Settings\SettingsManager;
use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Privacy\ConsentState;
use Torgash\Privacy\Models\ConsentRecord;
use Torgash\Privacy\Models\PrivacyRequest;
use Torgash\Privacy\Retention;
use Torgash\Reviews\Models\Review;
use Torgash\Snippets\Models\Snippet;

/**
 * Personal data (PLAN §49): third-party code waits for consent and switches on
 * when given; every consent is written down; a customer's data is downloaded
 * or erased on request — orders kept for the books, without IP and browser;
 * and IP addresses expire on their own.
 */
class PrivacyTest extends TestCase
{
    protected SettingsManager $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsManager::class);
        $this->settings->set('torgash.privacy', 'consent_mode', 'opt_in');
        $this->settings->set('torgash.privacy', 'google_consent_mode', true);
    }

    public function test_third_party_code_waits_for_consent_and_runs_once_given(): void
    {
        $this->snippet('Own helper', 'necessary', '<script>window.ownHelper=1</script>');
        $this->snippet('Analytics', 'statistics', '<script>window.analyticsRan=1</script>');
        $this->snippet('Pixel', 'marketing', '<script>window.pixelRan=1</script>');

        $html = preg_replace('/ nonce="[^"]*"/', '', $this->get('http://localhost/')->assertOk()->getContent());

        $this->assertStringContainsString('<script>window.ownHelper=1</script>', $html);
        $this->assertStringContainsString('<template data-consent="statistics"><script>window.analyticsRan=1</script></template>', $html);
        $this->assertStringContainsString('<template data-consent="marketing">', $html);
        $this->assertStringContainsString('snippets/assets/consent.js', $html);
        $this->assertStringContainsString('data-privacy-banner', $html);
        $this->assertStringContainsString('"analytics_storage":"denied"', $html);

        // The answer from the banner: remembered, and written down.
        $this->postJson('http://localhost/privacy/consent', ['choice' => 'custom', 'categories' => ['statistics', 'bogus']])
            ->assertOk()->assertJsonPath('categories', ['statistics'])
            ->assertCookie(ConsentState::COOKIE);
        $this->assertSame(['statistics'], ConsentRecord::where('kind', 'cookies')->latest('id')->value('categories'));

        $html = preg_replace('/ nonce="[^"]*"/', '', $this->withCookie(ConsentState::COOKIE, '1:statistics')->get('http://localhost/')->getContent());

        $this->assertStringContainsString('<script>window.analyticsRan=1</script>', $html);
        $this->assertStringNotContainsString('<template data-consent="statistics">', $html);
        $this->assertStringContainsString('<template data-consent="marketing">', $html);
        $this->assertStringContainsString('"analytics_storage":"granted"', $html);
        $this->assertStringNotContainsString('data-privacy-banner', $html);
    }

    public function test_a_chat_waits_behind_its_own_button(): void
    {
        $this->snippet('Chat', 'functional', '<script>window.chat=1</script>', ['on_click' => true, 'click_label' => ['en' => 'Ask us']]);

        $this->get('http://localhost/')->assertOk()
            ->assertSee('data-consent-click', false)
            ->assertSee('data-consent-click-button', false)
            ->assertSee('Ask us');
    }

    public function test_where_the_law_allows_until_refused_only_a_privacy_signal_holds_ads_back(): void
    {
        $this->settings->set('torgash.privacy', 'consent_mode', 'opt_out');
        $this->snippet('Analytics', 'statistics', '<script>window.analyticsRan=1</script>');
        $this->snippet('Pixel', 'marketing', '<script>window.pixelRan=1</script>');

        $html = preg_replace('/ nonce="[^"]*"/', '', $this->get('http://localhost/')->getContent());
        $this->assertStringContainsString('<script>window.pixelRan=1</script>', $html);
        $this->assertStringNotContainsString('data-privacy-banner', $html);

        // Global Privacy Control: "do not sell or share".
        $html = preg_replace('/ nonce="[^"]*"/', '', $this->withHeader('Sec-GPC', '1')->get('http://localhost/')->getContent());
        $this->assertStringContainsString('<template data-consent="marketing">', $html);
        $this->assertStringContainsString('<script>window.analyticsRan=1</script>', $html);

        // And with the module told not to ask at all, nothing waits.
        $this->settings->set('torgash.privacy', 'consent_mode', 'off');
        $html = preg_replace('/ nonce="[^"]*"/', '', $this->get('http://localhost/')->getContent());
        $this->assertStringNotContainsString('<template data-consent', $html);
        $this->assertStringNotContainsString("gtag('consent'", $html);
    }

    public function test_a_customer_asks_and_a_manager_erases_what_the_law_does_not_keep(): void
    {
        $customer = User::create(['name' => 'Olena Leaving', 'email' => 'olena@example.test', 'password' => bcrypt('right-password')]);
        $customer->forceFill(['email_verified_at' => now()])->save();
        $product = Product::create(['sku' => 'PRV-1', 'name' => ['en' => 'Kettle'], 'slug' => 'prv-1', 'price' => 10_00, 'stock' => 3, 'status' => 'active']);
        Address::create(['user_id' => $customer->id, 'full_name' => 'Olena', 'phone' => '+380671112233', 'address_line' => '1 Street', 'city' => 'Kyiv']);
        $order = $this->order(['user_id' => $customer->id, 'email' => 'olena@example.test', 'ip_address' => '10.0.0.7', 'client' => ['user_agent' => 'Firefox']]);
        $guestOrder = $this->order(['email' => 'OLENA@example.test', 'ip_address' => '10.0.0.8']);
        $enquiry = Enquiry::create(['kind' => 'message', 'status' => 'new', 'name' => 'Olena', 'email' => 'olena@example.test', 'fields' => ['values' => ['message' => 'Hi'], 'labels' => []]]);
        $review = Review::create(['product_id' => $product->id, 'user_id' => $customer->id, 'author_name' => 'Olena Leaving', 'body' => 'Fine', 'rating' => 5, 'status' => Review::PUBLISHED, 'verified' => false]);

        // "My data": the account's own rows — never everything under the email.
        $download = $this->actingAs($customer)->get('http://localhost/account/privacy/download')->assertOk()->streamedContent();
        $this->assertStringContainsString($order->order_number, $download);
        $this->assertStringNotContainsString($guestOrder->order_number, $download);

        // Not without the password: a signed-in phone in somebody else's hands.
        $this->post('http://localhost/account/privacy/erase', ['note' => 'Please'])->assertSessionHasErrors('current_password');
        $this->post('http://localhost/account/privacy/erase', ['note' => 'Please', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertFalse(PrivacyRequest::where('user_id', $customer->id)->exists());

        $this->post('http://localhost/account/privacy/erase', ['note' => 'Please', 'current_password' => 'right-password'])->assertRedirect();
        $request = PrivacyRequest::where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('erase', $request->type);
        $this->assertSame('olena@example.test', $request->email); // confirmed, so it counts
        $this->assertNotNull($request->due_at);

        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->get('http://localhost/admin/privacy/requests/'.$request->id)->assertOk()->assertSee('Olena Leaving');
        $this->post('http://localhost/admin/privacy/requests/'.$request->id.'/erase')->assertRedirect()->assertSessionHas('status');

        $this->assertNull(User::find($customer->id));
        $this->assertSame(0, Address::where('user_id', $customer->id)->count());

        // Orders stay — the confirmed email's guest order too — without IP and browser.
        foreach ([$order, $guestOrder] as $kept) {
            $kept = Order::withTrashed()->find($kept->id);
            $this->assertNotNull($kept);
            $this->assertNull($kept->ip_address);
            $this->assertNull($kept->client);
        }

        $enquiry->refresh();
        $this->assertNull($enquiry->name);
        $this->assertNull($enquiry->email);
        $this->assertSame([], $enquiry->fields['values']);

        $review->refresh();
        $this->assertSame('Customer', $review->author_name);
        $this->assertNull($review->user_id);

        $request->refresh();
        $this->assertSame('done', $request->status);
        $this->assertContains('orders', array_column($request->report, 'key'));
    }

    public function test_a_staff_account_is_not_erased_from_here(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $request = PrivacyRequest::create(['user_id' => $admin->id, 'type' => 'erase', 'status' => 'new', 'name' => $admin->name, 'due_at' => now()->addDay()]);

        $this->actingAs($admin)->post('http://localhost/admin/privacy/requests/'.$request->id.'/erase')->assertSessionHas('warning');
        $this->assertNotNull(User::find($admin->id));
        $this->assertSame('new', $request->fresh()->status);
    }

    public function test_ip_addresses_expire_and_the_shops_days_win_over_the_default(): void
    {
        $old = $this->order(['ip_address' => '10.0.0.1', 'client' => ['user_agent' => 'Old']]);
        $old->forceFill(['created_at' => now()->subDays(200)])->saveQuietly();
        $recent = $this->order(['ip_address' => '10.0.0.2']);

        $this->settings->set('torgash.privacy', 'retention', ['order_ip' => 180]);
        app(Retention::class)->run();

        $this->assertNull($old->fresh()->ip_address);
        $this->assertNull($old->fresh()->client);
        $this->assertSame('10.0.0.2', $recent->fresh()->ip_address);

        // 0 keeps it.
        $older = $this->order(['ip_address' => '10.0.0.3']);
        $older->forceFill(['created_at' => now()->subDays(900)])->saveQuietly();
        $this->settings->set('torgash.privacy', 'retention', ['order_ip' => 0]);
        app(Retention::class)->run();
        $this->assertSame('10.0.0.3', $older->fresh()->ip_address);

        $this->assertArrayHasKey('order_ip', app(PersonalData::class)->retained());
        $this->assertArrayHasKey('guard_ip', app(PersonalData::class)->retained());
    }

    public function test_the_admin_screens_open(): void
    {
        $this->snippet('GA', 'necessary', '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>');
        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin);

        $this->get('http://localhost/admin/privacy/requests')->assertOk();
        $this->get('http://localhost/admin/privacy/requests/create')->assertOk();
        $this->get('http://localhost/admin/privacy/consents')->assertOk();
        // The snippet is listed with what its addresses look like.
        $this->get('http://localhost/admin/privacy/settings')->assertOk()->assertSee('GA')->assertSee(__('looks like ":category"', ['category' => __('Statistics')]));

        $this->put('http://localhost/admin/privacy/settings', [
            'regime' => 'us', 'consent_mode' => 'opt_out', 'banner_style' => 'corner', 'answer_days' => 45,
            'retention' => ['order_ip' => 365, 'guard_ip' => 365, 'verification_codes' => 30, 'consent_ip' => 730],
        ])->assertRedirect();
        $this->assertSame('opt_out', setting('torgash.privacy', 'consent_mode'));
        $this->assertSame(365, setting('torgash.privacy', 'retention')['order_ip']);

        $this->post('http://localhost/admin/privacy/requests', ['type' => 'export', 'email' => 'someone@example.test'])->assertRedirect();
        $this->assertSame(1, PrivacyRequest::where('email', 'someone@example.test')->count());
    }

    protected function snippet(string $name, string $category, string $code, array $extra = []): Snippet
    {
        return Snippet::create($extra + [
            'name' => $name, 'placement' => 'head', 'code' => $code, 'rules' => [], 'rules_match' => 'all',
            'position' => 0, 'enabled' => true, 'category' => $category,
        ]);
    }

    protected function order(array $attributes): Order
    {
        return Order::create($attributes + [
            'order_number' => 'PRV-'.Str::upper(Str::random(6)),
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => 'USD', 'subtotal' => 10_00, 'shipping_cost' => 0, 'total' => 10_00,
            'status' => 'open', 'payment_state' => PaymentState::PENDING, 'stock_deducted' => false,
            'items' => [],
        ]);
    }
}
