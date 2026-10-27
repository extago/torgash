<?php

namespace Tests\Feature\Newsletter;

use App\Core\Mail\EventMail;
use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Newsletter\Models\Subscriber;
use Torgash\Newsletter\Subscriptions;

/**
 * torgash.newsletter (PLAN §57.2): subscribers collected by a block the shop
 * lays out itself, the basket page and the checkout tick; every address
 * confirmed by a letter whose link opens a page with a button; a way out;
 * the account's switch; the admin list, CSV in and out; personal data.
 */
class NewsletterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_an_address_waits_for_its_letter_and_a_link_scanner_confirms_nothing(): void
    {
        $this->subscribe('Reader@Example.com')->assertOk()->assertSee('follow its link');

        $subscriber = Subscriber::where('email', 'reader@example.com')->firstOrFail();
        $this->assertSame(Subscriber::PENDING, $subscriber->status);
        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === Subscriptions::LETTER && $mail->hasTo('reader@example.com'));

        // Opening the link is a page with a button; only the button confirms.
        $this->get('http://localhost/newsletter/confirm/'.$subscriber->token)->assertOk()->assertSee('Confirm the subscription');
        $this->assertSame(Subscriber::PENDING, $subscriber->fresh()->status);
        $this->post('http://localhost/newsletter/confirm/'.$subscriber->token)->assertOk();
        $this->assertSame(Subscriber::SUBSCRIBED, $subscriber->fresh()->status);

        // Asking again: already there, no second letter.
        $this->subscribe('reader@example.com')->assertSee('already subscribed');
        Mail::assertQueuedCount(1);
    }

    public function test_without_double_opt_in_and_for_a_confirmed_account_it_is_immediate(): void
    {
        app(SettingsManager::class)->set('torgash.newsletter', 'double_opt_in', false);
        $this->subscribe('quick@example.com');
        $this->assertSame(Subscriber::SUBSCRIBED, Subscriber::where('email', 'quick@example.com')->value('status'));

        app(SettingsManager::class)->set('torgash.newsletter', 'double_opt_in', true);
        $user = User::factory()->create(['email' => 'mine@example.com', 'email_verified_at' => now()]);
        $this->actingAs($user)->post('http://localhost/account/newsletter', ['subscribed' => '1'])->assertRedirect();
        $this->assertSame(Subscriber::SUBSCRIBED, Subscriber::where('email', 'mine@example.com')->value('status'));
        Mail::assertNothingQueued();

        $this->actingAs($user)->post('http://localhost/account/newsletter', ['subscribed' => '0'])->assertRedirect();
        $this->assertSame(Subscriber::UNSUBSCRIBED, Subscriber::where('email', 'mine@example.com')->value('status'));
    }

    public function test_the_way_out(): void
    {
        $subscriber = Subscriber::create(['email' => 'leaving@example.com', 'status' => 'subscribed', 'token' => str_repeat('a', 40)]);

        $this->get('http://localhost/newsletter/unsubscribe/'.$subscriber->token)->assertOk()->assertSee('leaving@example.com');
        $this->assertSame(Subscriber::SUBSCRIBED, $subscriber->fresh()->status, 'opening the link changes nothing');

        $this->post('http://localhost/newsletter/unsubscribe/'.$subscriber->token)->assertOk()->assertSee('You are unsubscribed');
        $this->assertSame(Subscriber::UNSUBSCRIBED, $subscriber->fresh()->status);
    }

    public function test_a_bot_filling_the_trap_is_thanked_and_kept_off_the_list(): void
    {
        $this->post('http://localhost/newsletter/subscribe', [
            'email' => 'bot@example.com', FormGuard::TRAP => 'http://spam', FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
        ], ['HX-Request' => 'true'])->assertOk();

        $this->assertNull(Subscriber::where('email', 'bot@example.com')->first());
    }

    public function test_the_basket_offer_and_the_checkout_tick_are_settings(): void
    {
        $this->assertStringContainsString('name="source" value="cart"', $this->get('http://localhost/cart')->getContent());
        app(SettingsManager::class)->set('torgash.newsletter', 'offer_in_cart', false);
        $this->assertStringNotContainsString('name="source" value="cart"', $this->get('http://localhost/cart')->getContent());

        // The tick: read when the order is placed, for the order's email.
        $subscriptions = app(Subscriptions::class);
        $subscriptions->fromOrder('buyer@example.com', 'T-1', ticked: true);
        $this->assertSame('checkout', Subscriber::where('email', 'buyer@example.com')->value('source'));
        $subscriptions->fromOrder('unticked@example.com', 'T-2', ticked: false);
        $this->assertNull(Subscriber::where('email', 'unticked@example.com')->first());

        app(SettingsManager::class)->set('torgash.newsletter', 'offer_at_checkout', false);
        $subscriptions->fromOrder('second@example.com', 'T-3', ticked: true);
        $this->assertNull(Subscriber::where('email', 'second@example.com')->first(), 'a tick the shop does not offer is not read');
    }

    public function test_the_checkout_form_carries_the_tick(): void
    {
        $product = Product::create(['sku' => 'NL-1', 'name' => ['en' => 'NL'], 'slug' => 'nl-1', 'price' => 10_00, 'stock' => 5, 'status' => 'active']);
        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 1]);

        $this->get('http://localhost/checkout')->assertOk()->assertSee('name="newsletter_subscribe"', false);
    }

    public function test_the_block_as_the_theme_lays_it_out_or_in_the_shops_own_html(): void
    {
        $default = view('torgash.newsletter::blocks.newsletter', ['settings' => ['heading' => 'Stay close']])->render();
        $this->assertStringContainsString('Stay close', $default);
        $this->assertStringContainsString('name="email"', $default);

        $own = view('torgash.newsletter::blocks.newsletter', ['settings' => [
            'heading' => 'H', 'template' => '<section class="mine"><b>{heading}</b>{form}<small>{subscribers}</small></section>',
        ]])->render();
        $this->assertStringContainsString('<section class="mine"><b>H</b><div data-newsletter-form>', $own);
        $this->assertStringContainsString('<small>0</small>', $own);
    }

    public function test_the_admin_list_csv_in_and_out(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Subscriber::create(['email' => 'gone@example.com', 'status' => 'unsubscribed', 'token' => str_repeat('b', 40)]);

        $csv = UploadedFile::fake()->createWithContent('list.csv', "email\nnew@example.com\nGONE@example.com\nnot-an-address\n=cmd@example.com\n");
        $this->actingAs($admin)->post('http://localhost/admin/newsletter/import', ['file' => $csv])->assertRedirect();

        $this->assertSame(Subscriber::SUBSCRIBED, Subscriber::where('email', 'new@example.com')->value('status'));
        $this->assertSame(Subscriber::UNSUBSCRIBED, Subscriber::where('email', 'gone@example.com')->value('status'), 'who left stays gone');

        $this->actingAs($admin)->get('http://localhost/admin/newsletter')->assertOk()->assertSee('new@example.com');
        $export = $this->actingAs($admin)->get('http://localhost/admin/newsletter/export?tab=all')->assertOk()->streamedContent();
        $this->assertStringContainsString('new@example.com', $export);
        $this->assertStringNotContainsString("\n=cmd", $export, 'a formula-looking cell is text');
    }

    public function test_personal_data(): void
    {
        Subscriber::create(['email' => 'private@example.com', 'status' => 'subscribed', 'token' => str_repeat('c', 40), 'consent_ip' => '10.0.0.1']);
        $registry = app(PersonalData::class);
        $subject = Subject::of(null, 'private@example.com');

        $this->assertSame('10.0.0.1', $registry->export($subject)['Newsletter subscription'][0]['consent_ip'] ?? null);
        $registry->erase($subject);
        $this->assertNull(Subscriber::where('email', 'private@example.com')->first());
    }

    protected function subscribe(string $email)
    {
        return $this->post('http://localhost/newsletter/subscribe', [
            'email' => $email, 'source' => 'block', FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
        ], ['HX-Request' => 'true']);
    }
}
