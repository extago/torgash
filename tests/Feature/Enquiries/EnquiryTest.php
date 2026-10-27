<?php

namespace Tests\Feature\Enquiries;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use App\Core\Mail\EventMail;
use Torgash\Enquiries\Mail\EnquiryLetters;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Status;

/**
 * The public form that writes rows, and everything that stops it being a hole.
 *
 * Most of what is pinned here is about the two ways this module fails in the
 * real world: an enquiry nobody sees (a typical form builder emails and stores
 * nothing) and an inbox full of casino adverts, which is the same outcome as
 * not shipping it because the shop switches it off.
 */
class EnquiryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('form:enquiry:ip:127.0.0.1');
    }

    /** A form stamp that is old enough to have been typed into. */
    protected function stamp(int $secondsAgo = 30): string
    {
        return Crypt::encryptString((string) (time() - $secondsAgo));
    }

    /** @param array<string, mixed> $overrides */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'callback',
            'name' => 'Иван',
            'phone' => '+380 (67) 123-45-67',
            'time' => 'evening',
            FormGuard::STAMP => $this->stamp(),
            FormGuard::TRAP => '',
        ], $overrides);
    }

    public function test_a_callback_is_stored_with_its_answers_and_their_labels(): void
    {
        $this->post('http://localhost/enquiry', $this->payload())->assertRedirect();

        $enquiry = Enquiry::latest('id')->firstOrFail();

        $this->assertSame('callback', $enquiry->kind);
        $this->assertSame(Status::NEW, $enquiry->status);
        $this->assertSame('+380 (67) 123-45-67', $enquiry->phone);

        // The labels are the half that makes an enquiry from March still
        // readable after the form has been edited.
        $answers = collect($enquiry->answers())->keyBy('key');
        $this->assertSame('Phone', $answers['phone']['label']);
        $this->assertSame('Evening', $answers['time']['value'], 'a select printed its own key at a human');
    }

    /**
     * A select stored its key, so the inbox and the customer's email both read
     * "When to call: evening" — an internal token printed at a human. The
     * option's own label is snapshotted beside the field's, and for the same
     * reason: the list gets edited, and March must still read as it was
     * answered.
     */
    public function test_a_chosen_option_is_stored_under_the_name_it_was_offered_under(): void
    {
        $this->post('http://localhost/enquiry', $this->payload(['time' => 'evening']))->assertRedirect();

        $enquiry = Enquiry::latest('id')->firstOrFail();
        $answer = collect($enquiry->answers())->firstWhere('key', 'time');

        $this->assertSame('Evening', $answer['value']);
        $this->assertSame('evening', $enquiry->answer('time'), 'the stored value stopped being the key');
    }

    /** A row written before the options were snapshotted still reads. */
    public function test_an_older_answer_with_no_option_labels_still_reads(): void
    {
        $enquiry = Enquiry::create([
            'kind' => 'callback',
            'phone' => '+380671234567',
            'fields' => ['values' => ['time' => 'evening'], 'labels' => ['time' => 'When to call']],
        ]);

        $this->assertSame('evening', collect($enquiry->answers())->firstWhere('key', 'time')['value']);
    }

    /**
     * The whole point of the module over a typical form builder: the row exists
     * whether or not any mail was sent, and the mail is queued rather than made
     * the visitor's problem.
     */
    public function test_the_shop_is_emailed_and_the_row_is_kept_either_way(): void
    {
        Mail::fake();

        $this->set(['notify_email' => 'manager@shop.test']);

        $this->post('http://localhost/enquiry', $this->payload());

        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === EnquiryLetters::RECEIVED);
        $this->assertSame(1, Enquiry::count());
    }

    public function test_an_enquiry_announces_itself_so_a_rule_can_act_on_it(): void
    {
        $seen = null;
        hook_listen('enquiries.enquiry.created', function (array $context) use (&$seen) {
            $seen = $context['enquiry'];
        });

        $this->post('http://localhost/enquiry', $this->payload());

        $this->assertNotNull($seen, 'nothing was announced, so no automation could ever run');
        $this->assertSame('callback', $seen->kind);
    }

    public function test_a_question_about_a_product_is_filed_against_it(): void
    {
        $product = $this->product();

        $this->post('http://localhost/enquiry', $this->payload([
            'kind' => 'product_question',
            'subject_id' => $product->id,
            'question' => 'Есть ли гарантия два года?',
        ]));

        $enquiry = Enquiry::latest('id')->firstOrFail();

        $this->assertSame('product', $enquiry->subject_type);
        $this->assertSame($product->id, (int) $enquiry->subject_id);
        $this->assertSame($product->t('name'), $enquiry->subject()['label']);
    }

    /**
     * subject_type comes from the KIND, never from the request: a form that
     * could name its own subject type could file a callback against an order.
     */
    public function test_the_subject_type_cannot_be_chosen_by_the_form(): void
    {
        $product = $this->product();

        $this->post('http://localhost/enquiry', $this->payload([
            'kind' => 'product_question',
            'subject_id' => $product->id,
            'subject_type' => 'order',
            'question' => 'Что это?',
        ]));

        $this->assertSame('product', Enquiry::latest('id')->firstOrFail()->subject_type);
    }

    public function test_an_enquiry_with_no_way_to_reply_is_refused(): void
    {
        $this->post('http://localhost/enquiry', $this->payload([
            'kind' => 'message',
            'phone' => null,
            'email' => null,
            'message' => 'hello',
        ]))->assertSessionHasErrors('enquiry');

        $this->assertSame(0, Enquiry::count());
    }

    // ------------------------------------------------------------- the guard

    public function test_a_filled_honeypot_is_answered_as_success_and_written_nowhere(): void
    {
        // Answered as success on purpose: a bot that learns which of its
        // submissions failed is a bot that stops filling the trap.
        $this->post('http://localhost/enquiry', $this->payload([FormGuard::TRAP => 'http://spam.example']))
            ->assertRedirect();

        $this->assertSame(0, Enquiry::count());
    }

    public function test_a_form_submitted_instantly_was_not_typed_into(): void
    {
        $this->post('http://localhost/enquiry', $this->payload([FormGuard::STAMP => $this->stamp(0)]))
            ->assertSessionHasErrors('enquiry');

        $this->assertSame(0, Enquiry::count());
    }

    public function test_a_stamp_that_did_not_come_from_this_shop_is_refused(): void
    {
        $this->post('http://localhost/enquiry', $this->payload([FormGuard::STAMP => (string) (time() - 30)]))
            ->assertSessionHasErrors('enquiry');

        $this->assertSame(0, Enquiry::count());
    }

    public function test_a_stale_form_is_refused_rather_than_replayed_for_a_week(): void
    {
        $this->post('http://localhost/enquiry', $this->payload([FormGuard::STAMP => $this->stamp(90000)]))
            ->assertSessionHasErrors('enquiry');

        $this->assertSame(0, Enquiry::count());
    }

    public function test_the_same_number_cannot_send_thirty_enquiries(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('http://localhost/enquiry', $this->payload());
        }

        // Three per identity within the window, and the rest are turned away.
        $this->assertSame(3, Enquiry::count());
    }

    public function test_a_module_gets_the_last_word_on_a_submission(): void
    {
        hook_listen('core.form.check', fn () => 'Prove you are human.');

        $this->post('http://localhost/enquiry', $this->payload())
            ->assertSessionHasErrors('enquiry');

        $this->assertSame(0, Enquiry::count());
    }

    // -------------------------------------------------------------- the inbox

    public function test_the_inbox_lists_and_answers_an_enquiry(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $enquiry = Enquiry::create(['kind' => 'callback', 'phone' => '+380671234567', 'name' => 'Иван']);

        $this->actingAs($admin)
            ->get('http://localhost/admin/enquiries')
            ->assertOk()
            ->assertSee('Иван');

        $this->actingAs($admin)
            ->patch('http://localhost/admin/enquiries/'.$enquiry->id, [
                'status' => Status::DONE,
                'manager_id' => $admin->id,
                'notes' => 'Rang back, sold it.',
            ])
            ->assertRedirect();

        $enquiry->refresh();

        $this->assertSame(Status::DONE, $enquiry->status);
        $this->assertNotNull($enquiry->answered_at, 'the wait was never measured');
    }

    /**
     * answered_at measures how long the person waited, so the FIRST finish
     * stamps it and a status corrected afterwards must not move it.
     */
    public function test_the_moment_it_was_answered_is_not_moved_by_a_later_correction(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $enquiry = Enquiry::create(['kind' => 'callback', 'phone' => '+380671234567']);

        $this->actingAs($admin)->patch('http://localhost/admin/enquiries/'.$enquiry->id, ['status' => Status::DONE]);
        $first = $enquiry->refresh()->answered_at;

        $this->travel(2)->hours();

        $this->actingAs($admin)->patch('http://localhost/admin/enquiries/'.$enquiry->id, ['status' => Status::IN_PROGRESS]);
        $this->actingAs($admin)->patch('http://localhost/admin/enquiries/'.$enquiry->id, ['status' => Status::DONE]);

        $this->assertEquals($first, $enquiry->refresh()->answered_at);
    }

    public function test_the_inbox_is_staff_only(): void
    {
        $this->get('http://localhost/admin/enquiries')->assertRedirect();
    }

    protected function product(): Product
    {
        return Product::create([
            'sku' => 'ENQ-1',
            'name' => ['en' => 'Phone with a question'],
            'slug' => 'phone-with-a-question',
            'price' => 100_00,
            'stock' => 3,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $values */
    protected function set(array $values): void
    {
        $settings = app(SettingsManager::class);

        foreach ($values as $key => $value) {
            $settings->set('torgash.enquiries', $key, $value);
        }
    }

    public function test_the_shop_can_hold_a_callback_until_the_phone_is_confirmed(): void
    {
        $sent = $this->confirmByCode('enquiry.callback');

        $this->post('http://localhost/enquiry', $this->payload())->assertSessionHasErrors('enquiry');
        $this->assertSame(0, Enquiry::count());

        $cookie = $this->confirmedCookie('enquiry.callback', '+380 (67) 123-45-67', $sent);

        $this->withCookie(\App\Core\Verification\FormVerification::COOKIE, $cookie)
            ->post('http://localhost/enquiry', $this->payload())->assertRedirect();
        $this->assertSame(1, Enquiry::count());
    }

    /**
     * PLAN §44: with an SMS channel and the form ticked, the contact must be
     * confirmed by a code first — and then the very same submission goes.
     * Returns the list the test channel fills with every code it sends.
     */
    protected function confirmByCode(string $form): \ArrayObject
    {
        $sent = new \ArrayObject;
        $channel = new class($sent) implements \App\Core\Verification\Channel
        {
            public function __construct(private \ArrayObject $sent) {}

            public function label(): string { return 'SMS'; }

            public function kinds(): array { return ['phone']; }

            public function available(): bool { return true; }

            public function supports(string $kind, string $address): bool { return true; }

            public function send(string $kind, string $address, array $message): \App\Core\Verification\SendResult
            {
                $this->sent[] = $message['code'];

                return \App\Core\Verification\SendResult::sent();
            }
        };
        hook_listen('core.verification.channels', fn (array $all) => $all + ['sms' => $channel]);
        app(SettingsManager::class)->set('core', 'verify_forms', [$form]);

        return $sent;
    }

    /** Confirms `$phone` for `$form` and hands back the browser's cookie. */
    protected function confirmedCookie(string $form, string $phone, \ArrayObject $sent): string
    {
        $this->post('http://localhost/verify-contact', ['verify_form' => $form, 'phone' => $phone])->assertOk();

        return $this->post('http://localhost/verify-contact/check', ['verify_form' => $form, 'phone' => $phone, 'verify_code' => $sent[count($sent) - 1]])
            ->getCookie(\App\Core\Verification\FormVerification::COOKIE)->getValue();
    }
}
