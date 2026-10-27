<?php

namespace Tests\Feature\Enquiries;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use App\Core\Mail\EventMail;
use Torgash\Enquiries\Mail\EnquiryLetters;
use Tests\TestCase;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;

/**
 * Neither side of a conversation talks into a void.
 *
 * The shop's copy is not what stops a customer's reply being lost — that is the
 * status going back into the queue — it is what stops it waiting a day for
 * somebody to open the list. The customer's copy is the whole thing for a
 * guest: they have no account to come back to, so the email IS the answer.
 */
class EnquiryMailTest extends TestCase
{
    /**
     * Mail::fake() per test rather than in setUp: one of these has to go all the
     * way through the mailer into the array transport, and a fake installed for
     * everybody cannot be taken back off.
     */
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)
            ->set('torgash.enquiries', 'notify_email', 'manager@shop.test');
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    protected function enquiry(array $overrides = []): Enquiry
    {
        return Enquiry::create(array_merge([
            'kind' => 'callback',
            'name' => 'Иван',
            'phone' => '+380671234567',
            'email' => 'buyer@example.com',
            'locale' => 'en',
        ], $overrides));
    }

    public function test_the_customer_is_told_the_shop_has_answered(): void
    {
        Mail::fake();

        $enquiry = $this->enquiry();

        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Перезвоним завтра.');

        Mail::assertQueued(EventMail::class,
            fn (EventMail $mail) => $mail->event === EnquiryLetters::REPLIED && $mail->hasTo('buyer@example.com'));

        Mail::assertNotQueued(EventMail::class, fn (EventMail $mail) => $mail->event === EnquiryLetters::MESSAGE);
    }

    /**
     * A callback left with a phone number and nothing else gets no mail, rather
     * than a mail to an address composed out of thin air — the same rule that
     * keeps one-click orders from inventing an email for the buyer.
     */
    public function test_an_enquiry_with_no_email_is_not_mailed_at_all(): void
    {
        Mail::fake();

        $enquiry = $this->enquiry(['email' => null]);

        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Перезвоним завтра.');

        Mail::assertNotQueued(EventMail::class, fn (EventMail $mail) => $mail->event === EnquiryLetters::REPLIED);
    }

    public function test_the_shop_is_told_the_customer_has_written_back(): void
    {
        Mail::fake();

        $enquiry = $this->enquiry();

        app(PostMessage::class)->fromCustomer($enquiry, 'А доставка входит в цену?');

        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === EnquiryLetters::MESSAGE);
        Mail::assertNotQueued(EventMail::class, fn (EventMail $mail) => $mail->event === EnquiryLetters::REPLIED);
    }

    /**
     * A queued mailable renders in the WORKER's locale, which is the app default
     * and has nothing to do with the person reading it. The language the enquiry
     * was written in is on the row, and the mail carries it.
     *
     * Sent for real into the array transport rather than faked, because the bug
     * this pins is in the SUBJECT: Mailable::send() reads $locale before it
     * calls build(), so a locale set inside build() came out as a Russian body
     * under an English subject and no assertion on the mailable object would
     * have noticed.
     */
    public function test_the_answer_is_written_in_the_language_they_asked_in(): void
    {
        $enquiry = $this->enquiry(['locale' => 'ru']);

        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Перезвоним завтра.');

        $sent = collect(Mail::mailer()->getSymfonyTransport()->messages())
            ->last()?->getOriginalMessage();

        $this->assertNotNull($sent, 'nothing was sent at all');
        $this->assertStringContainsString('Ответ на вашу заявку', $sent->getSubject());
        $this->assertStringContainsString('Вот наш ответ', $sent->getHtmlBody());
    }

    /**
     * A guest cannot answer in the thread (they have no account), so hitting
     * reply has to reach somebody — without this it reaches the address the app
     * sends from, which nobody reads.
     */
    public function test_the_customers_copy_can_be_replied_to(): void
    {
        Mail::fake();

        $enquiry = $this->enquiry();

        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Перезвоним завтра.');

        Mail::assertQueued(EventMail::class, function (EventMail $mail) {
            $mail->build();

            return $mail->replyTo !== [];
        });
    }

    /**
     * route() in a worker has no request to take the locale shape from, so the
     * bare name would send a Russian customer to the English page.
     */
    public function test_the_link_points_at_the_page_in_their_own_language(): void
    {
        Mail::fake();

        $enquiry = $this->enquiry([
            'locale' => 'ru',
            'user_id' => User::create([
                'name' => 'Иван', 'email' => 'ru-member@example.com', 'password' => 'secret123',
            ])->id,
        ]);

        app(PostMessage::class)->fromStaff($enquiry, $this->admin(), 'Ответ.');

        Mail::assertQueued(EventMail::class,
            fn (EventMail $mail) => $mail->event === EnquiryLetters::REPLIED && str_contains($mail->render(), '/ru/account/enquiries/'.$enquiry->id));
    }

    /**
     * A link to a login page for somebody who has never registered is a dead
     * end: a guest gets the answer and no link, an account holder gets both.
     */
    public function test_only_an_account_holder_gets_a_link_to_the_thread(): void
    {
        Mail::fake();

        $guest = $this->enquiry();
        $member = $this->enquiry([
            'user_id' => User::create([
                'name' => 'Иван', 'email' => 'member@example.com', 'password' => 'secret123',
            ])->id,
        ]);

        app(PostMessage::class)->fromStaff($guest, $this->admin(), 'Ответ гостю.');
        app(PostMessage::class)->fromStaff($member, $this->admin(), 'Ответ с аккаунтом.');

        Mail::assertQueued(EventMail::class, function (EventMail $mail) use ($guest) {
            return $mail->payload['enquiry']['id'] === $guest->id
                && ! str_contains($mail->render(), '/account/enquiries/');
        });

        Mail::assertQueued(EventMail::class, function (EventMail $mail) use ($member) {
            return $mail->payload['enquiry']['id'] === $member->id
                && str_contains($mail->render(), '/account/enquiries/'.$member->id);
        });
    }
}
