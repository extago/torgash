<?php

namespace Tests\Feature\Core;

use App\Core\Mail\EventMail;
use App\Core\Mail\Letters;
use App\Models\MailText;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Checkout\Mail\CheckoutLetters;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;

/**
 * Every letter in one frame, with words the shop can change (PLAN §41): the
 * module's default speaks the reader's language, the shop's own text
 * replaces it only in the language it was written in, a typed word cannot
 * inject HTML while a block (an order's lines) is drawn whole, and a letter
 * the shop switched off is not sent — unless it is one that may not be.
 */
class LettersTest extends TestCase
{
    protected function order(): Order
    {
        return Order::create([
            'order_number' => 'LT-'.uniqid(), 'customer_name' => 'Olena <b>O</b>', 'email' => 'olena@example.com',
            'items' => [['product_id' => null, 'name' => 'Lamp', 'qty' => 2, 'price' => 5_00, 'unit_price' => 5_00, 'line_total' => 10_00]],
            'subtotal' => 10_00, 'total' => 10_00, 'status' => OrderStatus::query()->value('code'),
            'currency_code' => shop_currency(),
        ]);
    }

    public function test_the_default_words_speak_the_readers_language(): void
    {
        $order = $this->order();

        $en = Letters::compose(CheckoutLetters::PLACED, ['order' => $order], 'en');
        $ru = Letters::compose(CheckoutLetters::PLACED, ['order' => $order], 'ru');

        $this->assertSame('Order '.$order->order_number.' confirmed', $en['subject']);
        $this->assertSame('Заказ '.$order->order_number.' принят', $ru['subject']);
        $this->assertStringContainsString('Lamp', $ru['html'], 'the order block is drawn');
    }

    public function test_the_shops_words_replace_the_default_only_in_their_language(): void
    {
        $order = $this->order();
        MailText::create(['event' => CheckoutLetters::PLACED, 'subject' => ['en' => 'We got it: {order_number}'], 'enabled' => true]);

        $this->assertSame('We got it: '.$order->order_number, Letters::compose(CheckoutLetters::PLACED, ['order' => $order], 'en')['subject']);
        $this->assertSame('Заказ '.$order->order_number.' принят', Letters::compose(CheckoutLetters::PLACED, ['order' => $order], 'ru')['subject'],
            'a Russian reader gets the Russian default, not the shop\'s English');
    }

    public function test_a_typed_value_cannot_inject_html(): void
    {
        $html = Letters::compose(CheckoutLetters::PLACED, ['order' => $this->order()], 'en')['html'];

        $this->assertStringContainsString('Olena &lt;b&gt;O&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('Olena <b>O</b>', $html);
    }

    public function test_a_switched_off_letter_is_not_sent_but_a_required_one_always_is(): void
    {
        Mail::fake();
        $status = OrderStatus::query()->firstOrFail();
        $event = CheckoutLetters::statusEvent($status->code);

        MailText::create(['event' => $event, 'enabled' => false]);
        $this->assertFalse(Letters::send($event, 'olena@example.com', ['order' => $this->order(), 'status' => $status]));
        Mail::assertNothingQueued();

        MailText::create(['event' => 'account.code', 'enabled' => false]);
        $this->assertTrue(Letters::send('account.code', 'olena@example.com', ['code' => '123456', 'purpose' => 'sign_in']));
        Mail::assertQueued(EventMail::class, fn ($mail) => $mail->event === 'account.code');
    }

    public function test_the_editor_saves_one_language_and_previews_unsaved_words(): void
    {
        $this->order();
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/letters')->assertOk()->assertSee(__('Order confirmation'));
        $this->get('http://localhost/admin/letters/'.CheckoutLetters::PLACED)->assertOk();

        $this->post('http://localhost/admin/letters/'.CheckoutLetters::PLACED.'/preview', ['subject' => 'Draft {order_number}'])
            ->assertOk()->assertSee('Draft LT-', false);
        $this->assertNull(MailText::where('event', CheckoutLetters::PLACED)->first(), 'a preview saves nothing');

        $this->post('http://localhost/admin/letters/'.CheckoutLetters::PLACED, ['subject' => 'Saved {order_number}', 'enabled' => '1'])
            ->assertRedirect();
        $this->assertSame(['en' => 'Saved {order_number}'], MailText::where('event', CheckoutLetters::PLACED)->value('subject'));
    }

    public function test_a_letter_is_drawn_in_the_theme_of_its_storefront(): void
    {
        // A theme of its own overriding the frame, in a folder of its own.
        $themes = storage_path('framework/testing/themes-'.uniqid());
        \Illuminate\Support\Facades\File::ensureDirectoryExists($themes.'/mailtest/views/mail');
        file_put_contents($themes.'/mailtest/theme.json', json_encode(['name' => 'Mail test', 'parent' => null]));
        file_put_contents($themes.'/mailtest/views/mail/frame.blade.php', 'MAILTEST-FRAME {!! $body !!}');
        config(['torgash.themes_path' => $themes]);

        $storefront = \App\Models\Storefront::query()->firstOrFail();
        $storefront->forceFill(['theme' => 'mailtest'])->save();
        $order = $this->order();
        $order->forceFill(['storefront_id' => $storefront->id])->save();

        $html = Letters::compose(CheckoutLetters::PLACED, ['order' => $order->fresh()], 'en')['html'];

        $this->assertStringStartsWith('MAILTEST-FRAME', $html, 'the storefront\'s theme drew the frame');
        $this->assertStringContainsString('Lamp', $html, 'the module\'s block is still inside it');

        \Illuminate\Support\Facades\File::deleteDirectory($themes);
    }

    public function test_the_preheader_is_the_heading_unless_written(): void
    {
        $order = $this->order();

        $this->assertStringContainsString('Thank you for your order!&#847;', Letters::compose(CheckoutLetters::PLACED, ['order' => $order], 'en')['html']);

        MailText::create(['event' => CheckoutLetters::PLACED, 'preheader' => ['en' => 'Your lamp is on its way soon'], 'enabled' => true]);
        $this->assertStringContainsString('Your lamp is on its way soon&#847;', Letters::compose(CheckoutLetters::PLACED, ['order' => $order], 'en')['html']);
    }
}
