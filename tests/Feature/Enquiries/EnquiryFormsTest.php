<?php

namespace Tests\Feature\Enquiries;

use App\Core\Mail\EventMail;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Blocks\BlockTypes;
use Torgash\Enquiries\Kinds;
use Torgash\Enquiries\Mail\EnquiryLetters;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Form;
use Torgash\Pages\Models\Page;

/**
 * The shop's own forms (PLAN §48): built in the admin one language at a time,
 * each one a kind of enquiry — so what it brings lands in the same inbox, with
 * its answers in the order they were asked, its own letter address and its
 * manager — and placed in a text as {form:code} or in a zone as a block.
 */
class EnquiryFormsTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_a_form_is_built_in_the_admin_and_translated_without_losing_its_answers(): void
    {
        $this->actingAs($this->admin)->withSession(['admin_content_locale' => 'en'])
            ->post('http://localhost/admin/enquiries/forms', [
                'name' => 'Wholesale request',
                'enabled' => 1,
                'fields' => [
                    ['type' => 'name', 'label' => 'Your name'],
                    ['type' => 'tel', 'label' => 'Phone', 'required' => 1],
                    ['type' => 'select', 'label' => 'Business type', 'required' => 1, 'options' => "Online shop\nRetail store"],
                    ['type' => 'checkboxes', 'label' => 'What interests you', 'options' => "Phones\nLaptops"],
                ],
            ])->assertRedirect();

        $form = Form::where('code', 'wholesale-request')->firstOrFail();
        $this->assertSame(['name', 'phone', 'business_type', 'what_interests_you'], array_column($form->fields, 'key'));
        $this->assertSame(['online_shop', 'retail_store'], array_column($form->fields[2]['options'], 'value'));

        // The same form in Russian: words change, keys and values stay, the
        // English is kept.
        $rows = collect($form->fields)->map(fn ($f) => ['key' => $f['key'], 'type' => $f['type'], 'required' => $f['required'] ? 1 : 0])->all();
        $rows[2]['label'] = 'Тип бизнеса';
        $rows[2]['options'] = "Интернет-магазин\nРозничный магазин";
        $rows[3]['options'] = "Телефоны\nНоутбуки";

        $this->withSession(['admin_content_locale' => 'ru'])
            ->put('http://localhost/admin/enquiries/forms/'.$form->id, ['name' => 'Оптовый запрос', 'enabled' => 1, 'fields' => $rows])
            ->assertRedirect();

        $form->refresh();
        $this->assertSame(['en' => 'Wholesale request', 'ru' => 'Оптовый запрос'], $form->name);
        $this->assertSame(['name', 'phone', 'business_type', 'what_interests_you'], array_column($form->fields, 'key'));
        $this->assertSame(['online_shop', 'retail_store'], array_column($form->fields[2]['options'], 'value'));
        $this->assertSame(['en' => 'Retail store', 'ru' => 'Розничный магазин'], $form->fields[2]['options'][1]['label']);
        $this->assertSame('Business type', $form->fields[2]['label']['en']);

        // Two phone fields are one too many — the enquiry has one phone column.
        $this->withSession(['admin_content_locale' => 'en'])
            ->put('http://localhost/admin/enquiries/forms/'.$form->id, ['name' => 'X', 'fields' => [
                ['type' => 'tel', 'label' => 'Phone'], ['type' => 'tel', 'label' => 'Second phone'],
            ]])->assertSessionHasErrors('fields.1.type');
    }

    public function test_what_a_form_brings_lands_in_the_inbox_in_the_order_it_asked(): void
    {
        Mail::fake();
        $form = $this->form(['notify_email' => 'opt@shop.test', 'manager_id' => $this->admin->id, 'consent' => true]);

        // Refused: no consent, a choice that was never offered.
        $this->post('http://localhost/enquiry', $this->payload($form, ['consent' => null, 'business_type' => 'bank']))
            ->assertSessionHasErrors(['consent', 'business_type']);
        $this->assertSame(0, Enquiry::where('kind', $form->kind())->count());

        $this->post('http://localhost/enquiry', $this->payload($form))->assertRedirect();

        $enquiry = Enquiry::where('kind', $form->kind())->firstOrFail();
        $this->assertSame('+380931112233', $enquiry->phone);
        $this->assertSame($this->admin->id, $enquiry->manager_id);
        $this->assertSame(['phones'], $enquiry->fields['values']['what_interests_you']);
        $this->assertSame(
            ['Your name', 'Phone', 'Business type', 'What interests you', 'Orders a month', 'I agree to the processing of my personal data'],
            array_column($enquiry->answers(), 'label'),
        );
        $this->assertSame('Retail store', $enquiry->answers()[2]['value']);
        $this->assertSame('Wholesale request', $enquiry->kindLabel());

        // The "I agree" is written down with its words (PLAN §49).
        $this->assertSame(
            'I agree to the processing of my personal data',
            \Torgash\Privacy\Models\ConsentRecord::where('kind', 'enquiry_form')->latest('id')->value('text'),
        );

        // To the form's own address, not the shop's inbox.
        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === EnquiryLetters::RECEIVED && $mail->hasTo('opt@shop.test'));

        // It has brought an enquiry, so it cannot be deleted — only switched off.
        $this->actingAs($this->admin)->delete('http://localhost/admin/enquiries/forms/'.$form->id)->assertSessionHas('warning');
        $this->assertNotNull($form->fresh());
    }

    public function test_a_form_without_a_phone_or_email_asks_for_neither(): void
    {
        $form = $this->form(['fields' => [
            ['key' => 'comment', 'type' => 'textarea', 'label' => ['en' => 'What could we do better?'], 'required' => true, 'options' => []],
        ]]);

        $this->post('http://localhost/enquiry', [
            'kind' => $form->kind(), 'comment' => 'Faster delivery.',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)), FormGuard::TRAP => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Enquiry::where('kind', $form->kind())->count());
    }

    public function test_it_goes_into_a_text_as_a_token_and_into_a_zone_as_a_block(): void
    {
        $form = $this->form();
        $page = Page::create(['slug' => 'wholesale', 'title' => ['en' => 'Wholesale'], 'enabled' => true,
            'body' => ['en' => '<p>Prices for shops.</p><p>{form:wholesale-request}</p><p>Thank you.</p>']]);

        $html = $this->get('http://localhost/wholesale')->assertOk()
            ->assertSee('name="kind" value="'.$form->kind().'"', false)
            ->assertSee('What interests you')
            ->assertDontSee('{form:wholesale-request}')
            ->getContent();

        // The paragraph around the token is gone: a form inside a <p> is split
        // apart by the browser.
        $this->assertDoesNotMatchRegularExpression('~<p>\s*<div data-text-skip~', $html);

        // Its .md rendition leaves the form out rather than flattening it.
        $this->get('http://localhost/wholesale.md')->assertOk()->assertSee('Prices for shops.')->assertDontSee('What interests you');

        // The block's choice of form lists it.
        $kindField = collect(BlockTypes::find('enquiry')['schema'])->firstWhere('key', 'kind');
        $this->assertContains($form->kind(), array_column(BlockTypes::options($kindField), 'value'));

        // Switched off: gone from the text, and its POST answers like a form
        // that does not exist.
        $form->update(['enabled' => false]);
        $this->get('http://localhost/wholesale')->assertOk()->assertDontSee('What interests you');
        $this->post('http://localhost/enquiry', $this->payload($form))->assertNotFound();
        $this->assertTrue(Kinds::exists($form->kind()));
    }

    public function test_the_admin_screens_open(): void
    {
        $form = $this->form();
        $this->actingAs($this->admin);

        $this->get('http://localhost/admin/enquiries/forms')->assertOk()->assertSee('Wholesale request')->assertSee('{form:wholesale-request}', false);
        $this->get('http://localhost/admin/enquiries/forms/create')->assertOk();
        $this->get('http://localhost/admin/enquiries/forms/'.$form->id.'/edit')->assertOk()->assertSee('Business type');
    }

    protected function form(array $attributes = []): Form
    {
        return Form::create($attributes + [
            'code' => 'wholesale-request',
            'name' => ['en' => 'Wholesale request'],
            'enabled' => true,
            'fields' => [
                ['key' => 'name', 'type' => 'name', 'label' => ['en' => 'Your name'], 'required' => false, 'options' => []],
                ['key' => 'phone', 'type' => 'tel', 'label' => ['en' => 'Phone'], 'required' => true, 'options' => []],
                ['key' => 'business_type', 'type' => 'select', 'label' => ['en' => 'Business type'], 'required' => true, 'options' => [
                    ['value' => 'online_shop', 'label' => ['en' => 'Online shop']],
                    ['value' => 'retail_store', 'label' => ['en' => 'Retail store']],
                ]],
                ['key' => 'what_interests_you', 'type' => 'checkboxes', 'label' => ['en' => 'What interests you'], 'required' => false, 'options' => [
                    ['value' => 'phones', 'label' => ['en' => 'Phones']],
                    ['value' => 'laptops', 'label' => ['en' => 'Laptops']],
                ]],
                ['key' => 'orders_a_month', 'type' => 'number', 'label' => ['en' => 'Orders a month'], 'required' => false, 'options' => []],
            ],
        ]);
    }

    protected function payload(Form $form, array $overrides = []): array
    {
        return array_filter(array_merge([
            'kind' => $form->kind(),
            'name' => 'Ivan',
            'phone' => '+380931112233',
            'business_type' => 'retail_store',
            'what_interests_you' => ['phones'],
            'orders_a_month' => '25',
            'consent' => '1',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ], $overrides), fn ($value) => $value !== null);
    }
}
