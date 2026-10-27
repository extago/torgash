<?php

namespace Tests\Feature\Newsletter;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Newsletter\Campaigns\CampaignLetter;
use Torgash\Newsletter\Campaigns\CampaignMail;
use Torgash\Newsletter\Campaigns\Segments;
use Torgash\Newsletter\Campaigns\SendCampaigns;
use Torgash\Newsletter\Models\Campaign;
use Torgash\Newsletter\Models\Delivery;
use Torgash\Newsletter\Models\Link;
use Torgash\Newsletter\Models\Subscriber;

/**
 * Campaigns (PLAN §57.4): a letter to the subscribed addresses that meet ALL
 * of its conditions, written in core's frame, sent at the shop's pace with
 * a row per address; opens, clicks and unsubscribes counted once per letter;
 * the click route goes only where the letter pointed.
 */
class CampaignsTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        app(SettingsManager::class)->set('torgash.newsletter', 'per_minute', 60000);
        $this->admin = User::factory()->create(['email' => 'boss@example.com']);
        $this->admin->assignRole('admin');
    }

    public function test_a_campaign_goes_to_its_segment_once_and_counts_what_readers_do(): void
    {
        $buyer = $this->subscriber('buyer@example.com');
        $this->subscriber('browser@example.com');
        $this->subscriber('gone@example.com', Subscriber::UNSUBSCRIBED);
        $this->subscriber('waiting@example.com', Subscriber::PENDING);
        $this->order('BUYER@example.com');
        $this->order('browser@example.com', 'cancelled');

        Product::create(['sku' => 'NEWS-1', 'name' => ['en' => 'Lamp of the month'], 'slug' => 'news-lamp', 'price' => 49_00, 'list_price' => 59_00, 'stock' => 3, 'status' => 'active']);
        Product::create(['sku' => 'NEWS-2', 'name' => ['en' => 'Shade of the month'], 'slug' => 'news-shade', 'price' => 19_00, 'stock' => 3, 'status' => 'active']);

        $this->actingAs($this->admin)->post('http://localhost/admin/newsletter/campaigns', [
            'name' => 'November', 'subject' => '{name}, the lamps are here', 'heading' => 'New lamps',
            'body' => '<p>Look at <a href="https://example.org/lamps">the lamps</a>.</p><p>{product:NEWS-1}</p><p>{products:NEWS-2,NOPE,NEWS-1|2}</p><script>alert(1)</script>',
            'rules' => [['kind' => 'bought', 'days' => '30'], ['kind' => 'nobody-knows-me']],
        ])->assertRedirect();
        $campaign = Campaign::where('name', 'November')->sole();
        $this->assertEquals([['kind' => 'bought', 'days' => '30']], $campaign->segment['rules'], 'an unknown kind is not stored');

        // Only the buyer: a cancelled order is not a purchase, and only SUBSCRIBED addresses count.
        $this->assertSame(['buyer@example.com'], app(Segments::class)->query($campaign->segment)->pluck('email')->all());
        $this->actingAs($this->admin)->get('http://localhost/admin/newsletter/campaigns/count?rules[0][kind]=not_bought')->assertSee('1 address');

        // The test letter: to the admin, nothing counted, no tracking.
        $this->actingAs($this->admin)->post('http://localhost/admin/newsletter/campaigns/'.$campaign->id.'/test', [])->assertRedirect();
        Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('boss@example.com') && ! str_contains($m->letterHtml, '/newsletter/o/'));
        $this->assertSame(0, $campaign->deliveries()->count());

        $this->actingAs($this->admin)->post('http://localhost/admin/newsletter/campaigns/'.$campaign->id.'/send', ['when' => 'now'])->assertRedirect();

        $campaign->refresh();
        $this->assertSame(Campaign::SENT, $campaign->status);
        $this->assertSame([1, 1, 0], [$campaign->recipients, $campaign->sent, $campaign->failed]);

        /** @var CampaignMail $mail */
        $mail = Mail::sent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('buyer@example.com'))->sole();
        $delivery = Delivery::where('email', 'buyer@example.com')->sole();
        $this->assertSame('The lamps are here', $mail->letterSubject, 'no name: the token goes with its comma');
        $this->assertStringNotContainsString('<script', $mail->letterHtml, 'the letter is cleaned like any staff HTML');
        $this->assertStringContainsString('Lamp of the month', $mail->letterHtml, '{product:SKU} is the product\'s card');
        $this->assertStringContainsString('Shade of the month', $mail->letterHtml, '{products:…} is a grid of tiles');
        $this->assertStringContainsString('max-width:264px', $mail->letterHtml, '|2: two tiles a row, an unknown code left out');
        $this->assertStringNotContainsString('{products:', $mail->letterHtml);
        $this->assertStringContainsString('text-decoration:line-through;', $mail->letterHtml, 'a discount shows the old price, as the card does');
        $this->assertStringContainsString('−17%', $mail->letterHtml);
        $this->assertStringContainsString('/newsletter/o/'.$delivery->token.'.gif', $mail->letterHtml);
        $this->assertStringContainsString('/newsletter/unsubscribe/'.$buyer->token.'?d='.$delivery->token, $mail->letterHtml);
        $this->assertStringNotContainsString('href="https://example.org/lamps"', $mail->letterHtml, 'links go through the click route');
        $this->assertSame(CampaignLetter::oneClickUrl($delivery), trim($mail->headers()->text['List-Unsubscribe'], '<>'));

        // Sent once: the next run has nothing to send.
        SendCampaigns::start();
        Mail::assertSentCount(2);

        // An open counts once; a click is an open too, and goes where the letter pointed.
        $this->get('http://localhost/newsletter/o/'.$delivery->token.'.gif')->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get('http://localhost/newsletter/o/'.$delivery->token.'.gif');
        $link = Link::where('campaign_id', $campaign->id)->where('url', 'https://example.org/lamps')->sole();
        $this->get('http://localhost/newsletter/c/'.$delivery->token.'/'.$link->id)->assertRedirect('https://example.org/lamps');
        $this->get('http://localhost/newsletter/c/'.$delivery->token.'/'.$link->id);
        $this->assertSame([1, 1, 2], [$campaign->fresh()->opened, $campaign->fresh()->clicked, $link->fresh()->clicks]);

        // Not a redirector: a link of another campaign is not followed from this letter.
        $other = Campaign::create(['name' => 'Other']);
        $foreign = Link::for($other, 'https://evil.example/');
        $this->get('http://localhost/newsletter/c/'.$delivery->token.'/'.$foreign->id)->assertNotFound();

        // The mail client's own button: one-click, no page, no CSRF.
        $this->post('http://localhost/newsletter/u/'.$delivery->token)->assertNoContent();
        $this->assertSame(Subscriber::UNSUBSCRIBED, $buyer->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->unsubscribed);

        // Sent: the words stay as they were sent.
        $this->actingAs($this->admin)->put('http://localhost/admin/newsletter/campaigns/'.$campaign->id, ['name' => 'Changed'])->assertStatus(409);
        $this->actingAs($this->admin)->get('http://localhost/admin/newsletter/campaigns/'.$campaign->id.'/edit')->assertOk()->assertSee('https://example.org/lamps');
        $this->actingAs($this->admin)->get('http://localhost/admin/newsletter/campaigns')->assertOk()->assertSee('November');
    }

    public function test_a_scheduled_campaign_waits_and_an_address_gone_meanwhile_is_skipped(): void
    {
        $this->subscriber('one@example.com');
        $two = $this->subscriber('two@example.com');
        $campaign = Campaign::create(['name' => 'Later', 'subject' => ['en' => 'Hello'], 'body' => ['en' => '<p>Hi</p>'], 'segment' => ['rules' => []]]);

        $this->actingAs($this->admin)->post('http://localhost/admin/newsletter/campaigns/'.$campaign->id.'/send', [
            'when' => 'at', 'scheduled_at' => now()->addHour()->format('Y-m-d\TH:i'),
        ])->assertRedirect();
        SendCampaigns::start();
        $this->assertSame(Campaign::SCHEDULED, $campaign->fresh()->status);
        Mail::assertNothingSent();

        // Its time comes; the deliveries are made, then one address leaves before its turn.
        $campaign->update(['scheduled_at' => now()->subMinute()]);
        (new \ReflectionMethod(SendCampaigns::class, 'prepareDue'))->invoke(new SendCampaigns);
        $two->update(['status' => Subscriber::UNSUBSCRIBED]);
        SendCampaigns::start();

        $this->assertSame([Campaign::SENT, 2, 1], [$campaign->fresh()->status, $campaign->fresh()->recipients, $campaign->fresh()->sent]);
        $this->assertSame(Delivery::SKIPPED, Delivery::where('email', 'two@example.com')->value('status'));
        Mail::assertSentCount(1);
    }

    public function test_a_letter_without_its_words_does_not_go_and_a_kind_nobody_answers_sends_to_nobody(): void
    {
        $this->subscriber('one@example.com');
        $empty = Campaign::create(['name' => 'Empty', 'segment' => ['rules' => []]]);
        $this->actingAs($this->admin)->post('http://localhost/admin/newsletter/campaigns/'.$empty->id.'/send', ['when' => 'now'])->assertSessionHasErrors('subject');
        $this->assertSame(Campaign::DRAFT, $empty->fresh()->status);

        $this->assertSame(0, app(Segments::class)->query(['rules' => [['kind' => 'switched-off-module']]])->count());
    }

    /** The account's language wins over the one the address subscribed in — it follows the customer's switches. */
    public function test_a_letter_is_written_in_the_language_of_the_readers_account(): void
    {
        $user = User::factory()->create(['email' => 'ivan@example.com']);
        $user->forceFill(['locale' => 'ru'])->save();
        $this->subscriber('ivan@example.com');           // subscribed on the English page, no user_id
        $this->subscriber('anna@example.com');            // no account: her own 'en'

        $campaign = Campaign::create([
            'name' => 'Both', 'subject' => ['en' => 'Hello', 'ru' => 'Привет'], 'body' => ['en' => '<p>Hi</p>', 'ru' => '<p>Привет</p>'],
            'segment' => ['rules' => []], 'status' => Campaign::SCHEDULED, 'scheduled_at' => now()->subMinute(),
        ]);
        $this->assertSame(['ivan@example.com'], app(Segments::class)->query(['rules' => [['kind' => 'language', 'locale' => 'ru']]])->pluck('email')->all());

        SendCampaigns::start();

        $this->assertSame('ru', Delivery::where('campaign_id', $campaign->id)->where('email', 'ivan@example.com')->value('locale'));
        Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('ivan@example.com') && $m->letterSubject === 'Привет');
        Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('anna@example.com') && $m->letterSubject === 'Hello');
    }

    protected function subscriber(string $email, string $status = Subscriber::SUBSCRIBED): Subscriber
    {
        return Subscriber::create(['email' => $email, 'status' => $status, 'source' => 'block', 'locale' => 'en', 'token' => Str::random(40), 'confirmed_at' => now()]);
    }

    protected function order(string $email, string $status = 'complete'): void
    {
        DB::table('m_checkout_orders')->insert([
            'order_number' => 'N-'.Str::random(6), 'email' => $email, 'status' => $status,
            'items' => '[]', 'subtotal' => 100_00, 'total' => 100_00, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
