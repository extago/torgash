<?php

namespace Torgash\Enquiries;

use App\Core\Support\FormGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Cart\CartManager;
use Torgash\Checkout\Orders\CustomerRef;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Form;

/**
 * The one place an enquiry is created — the same rule, and for the same reason,
 * as PlaceOrder being the one place an order is created.
 *
 * The storefront form, the product page, a block in a zone and (later) the API
 * all arrive here, so the spam guard, the label snapshot, the basket snapshot,
 * the customer ref, the notification and the hook cannot be forgotten by one
 * caller and remembered by another.
 */
class Submit
{
    /** The form's name, so the rate limiter counts it apart from the checkout's. */
    public const FORM = 'enquiry';

    public function __construct(protected FormGuard $guard) {}

    /**
     * @param  array<string, mixed>  $context  subject_type/subject_id, page_url
     *
     * @throws ValidationException
     */
    /** The key a kind's form has in core's `verified_forms` registry. */
    public static function verifiedForm(string $kind): string
    {
        return 'enquiry.'.$kind;
    }

    public function handle(Request $request, string $kind, array $context = []): ?Enquiry
    {
        abort_unless(Kinds::exists($kind), 404);

        $fields = Kinds::fields($kind);
        $form = Kinds::find($kind)['form'] ?? null;
        // A form switched off answers like one that does not exist.
        abort_if($form && ! $form->enabled, 404);
        $values = $this->validated($request, $kind, $fields);
        $identity = $this->identity($values);

        if ($refusal = $this->guard->check($request, $identity, self::FORM)) {
            // A trap hit is answered as a success and written nowhere; anything
            // else is a message the person has to see, so it comes back as a
            // validation error on the form they are looking at.
            if ($refusal['reason'] === 'trap') {
                return null;
            }

            throw ValidationException::withMessages(['enquiry' => $refusal['message']]);
        }

        // A callback to somebody else's number is the abuse this module sees
        // most (PLAN §44): when the shop asks, the contact is confirmed first.
        if ($unconfirmed = app(\App\Core\Verification\FormVerification::class)->refusal($request, self::verifiedForm($kind), [
            'phone' => $values['phone'] ?? null,
            'email' => $values['email'] ?? null,
        ])) {
            throw ValidationException::withMessages(['enquiry' => $unconfirmed]);
        }

        $enquiry = Enquiry::create([
            'kind' => $kind,
            'status' => Status::NEW,
            'name' => $values['name'] ?? null,
            'phone' => $values['phone'] ?? null,
            'email' => $values['email'] ?? null,
            'subject_type' => $context['subject_type'] ?? null,
            'subject_id' => $context['subject_id'] ?? null,
            // The answers AND what they were asked under. The labels are the
            // half that makes an enquiry from March still readable after the
            // form has been edited — see Enquiry::answers().
            'fields' => [
                'values' => $values,
                'labels' => $this->labels($fields, $form),
                'options' => $this->optionLabels($fields, $form),
                // The order they were asked in: jsonb keeps no key order (it
                // sorts them), so without this list the inbox would read a
                // form back alphabetically.
                'order' => array_values(array_intersect([...array_column($fields, 'key'), 'consent'], array_keys($values))),
            ],
            // A form the shop built may name who handles what it brings.
            'manager_id' => $form?->manager_id,
            'cart' => $this->cartSnapshot(),
            'customer_ref' => $this->customerRef($values),
            'user_id' => auth()->id(),
            'storefront_id' => current_storefront()?->id,
            'locale' => app()->getLocale(),
            'page_url' => $context['page_url'] ?? $request->headers->get('referer'),
        ]);

        // On the ENQUIRY, not on a message: what the person typed is stored
        // field by field on this row and is not copied into the thread, so the
        // photograph they sent with it belongs in the same place. A refused
        // file is a warning on the redirect, never a rejection — the words are
        // the valuable half, and handing three paragraphs back as an error over
        // a 30 MB photo loses them.
        if ($refused = app(Attachments::class)->store($enquiry, $request->file('attachments'))) {
            session()->flash('warning', implode(' ', array_unique($refused)));
        }

        $this->guard->record($request, $identity, self::FORM);

        // What was agreed to, in the words it was agreed under (PLAN §49) —
        // kept by whatever privacy module listens.
        if ($form?->consent) {
            \App\Core\Privacy\Consent::given([
                'kind' => 'enquiry_form',
                'text' => self::consentText($form),
                'user_id' => auth()->id(),
                'email' => $values['email'] ?? null,
                'phone' => $values['phone'] ?? null,
            ]);
        }

        Notify::received($enquiry);

        // A hook name IS an automations trigger here — there is no event
        // registry to also register with. Announced after the notification is
        // queued, so a rule that edits the enquiry cannot race the email that
        // quotes it.
        hook_action('enquiries.enquiry.created', ['enquiry' => $enquiry]);

        return $enquiry;
    }

    /**
     * Rules built from the kind's own fields, plus the one rule a kind cannot
     * express field by field: SOME way of replying.
     *
     * "Tell me when it is back" offers a phone and an email and demands
     * neither, because demanding a specific one turns half the people who
     * would have left the other into people who leave nothing.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function validated(Request $request, string $kind, array $fields): array
    {
        $rules = [];
        $names = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $required = ! empty($field['required']);
            $presence = $required ? 'required' : 'nullable';
            $choices = Rule::in(array_map('strval', array_keys($field['options'] ?? [])));

            // Photos are files, not answers: they ride the same POST and are
            // kept by Attachments (images only, private disk). Only "is there
            // at least one" is a rule here.
            if ($field['type'] === 'file') {
                $rules['attachments'] = [$required && Attachments::enabled() ? 'required' : 'nullable', 'array'];
                $names['attachments'] = Kinds::localized($field['label'], $key);

                continue;
            }

            $rules[$key] = match ($field['type']) {
                'email' => [$presence, 'email', 'max:190'],
                'tel' => [$presence, 'string', 'max:40'],
                'textarea' => [$presence, 'string', 'max:2000'],
                'number' => [$presence, 'numeric', 'between:-1000000000,1000000000'],
                'date' => [$presence, 'date'],
                'select', 'radio' => [$presence, 'string', $choices],
                'checkboxes' => [$presence, 'array'],
                // A tick box ticked sends "yes"; a required one is "must be ticked".
                'yes' => [$required ? 'accepted' : 'nullable', 'in:yes'],
                default => [$presence, 'string', 'max:190'],
            };

            if ($field['type'] === 'checkboxes') {
                $rules[$key.'.*'] = ['string', $choices];
            }

            $names[$key] = Kinds::localized($field['label'], $key);
        }

        // The form's own "I agree", when it asks for one (PLAN §48).
        $form = Kinds::find($kind)['form'] ?? null;

        if ($form?->consent) {
            $rules['consent'] = ['accepted'];
            $names['consent'] = self::consentText($form);
        }

        $values = Validator::make($request->all(), $rules, [
            'consent.accepted' => __('Tick the box to agree, or the form cannot be sent.'),
        ], $names)->validate();

        // The answers only — what was ticked is kept as the agreement it was.
        unset($values['attachments']);

        if ($form?->consent) {
            $values['consent'] = 'yes';
        }

        // Bytes that are not UTF-8 at all. No browser sends these and no rule
        // catches them: the request validates, and the row then fails to encode
        // on the way into jsonb — a 500 and a stack trace for what is really
        // just rubbish. Found the honest way, by sending cp1251 at it.
        foreach ($values as $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                throw ValidationException::withMessages([
                    'enquiry' => __('Some of that text did not arrive readably. Please send it again.'),
                ]);
            }
        }

        // A kind that asks how to reach the person needs one way at least; a
        // form the shop built with no phone or email field asks for neither.
        $needsContact = (Kinds::find($kind)['contact'] ?? 'required') === 'required';

        if ($needsContact && ! $this->identity($values)) {
            throw ValidationException::withMessages([
                'enquiry' => __('Leave a phone number or an email, so we can reply.'),
            ]);
        }

        return $values;
    }

    /**
     * How to reach them, normalised — the key the rate limiter counts by, so
     * one number cannot send thirty enquiries from thirty addresses.
     *
     * @param  array<string, mixed>  $values
     */
    protected function identity(array $values): ?string
    {
        if ($phone = preg_replace('/\D/', '', (string) ($values['phone'] ?? ''))) {
            return 'phone:'.substr($phone, -9);
        }

        if ($email = trim((string) ($values['email'] ?? ''))) {
            return 'email:'.mb_strtolower($email);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, string>
     */
    protected function labels(array $fields, ?Form $form = null): array
    {
        $labels = [];

        foreach ($fields as $field) {
            $labels[$field['key']] = Kinds::localized($field['label'], $field['key']);
        }

        // What the person agreed to, in the words they were shown.
        if ($form?->consent) {
            $labels['consent'] = self::consentText($form);
        }

        return $labels;
    }

    /** The words beside a form's "I agree" box. */
    public static function consentText(Form $form): string
    {
        return $form->t('consent_text') ?: __('I agree to the processing of my personal data');
    }

    /**
     * What the chosen option was CALLED, for the fields that have options.
     *
     * The same argument as the field labels one line up, and it was missing:
     * a select stores its key, so the inbox and the customer's email both read
     * "When to call: evening" — an internal token printed at a human. Snapshot
     * rather than a lookup for the same reason as everything else here: the
     * options of a kind get edited, and March must still read the way it was
     * answered.
     *
     * Only the options actually offered, keyed by field — not the answer alone,
     * because a form redrawn from this row later wants the whole list.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, array<string, string>>
     */
    protected function optionLabels(array $fields, ?Form $form = null): array
    {
        $options = [];

        foreach ($fields as $field) {
            foreach ($field['options'] ?? [] as $value => $label) {
                $options[$field['key']][$value] = $field['type'] === 'yes'
                    ? __('Yes')
                    : Kinds::localized($label, (string) $value);
            }
        }

        if ($form?->consent) {
            $options['consent'] = ['yes' => __('Yes')];
        }

        return $options;
    }

    /**
     * What was in the basket at that moment, frozen.
     *
     * The manager's first question is "what were they looking at", and after
     * the visitor's next click nothing can answer it — this is the one thing
     * the usual call requests got right. class_exists is the test, and it is a
     * true one: a module's autoloading is registered only while it is enabled,
     * so this is "is there a cart in this shop at all", not a guess.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function cartSnapshot(): ?array
    {
        if (! class_exists(CartManager::class)) {
            return null;
        }

        $lines = [];

        foreach (app(CartManager::class)->items() as $item) {
            $lines[] = [
                'product_id' => $item->product->id,
                // The NAME as well as the id: a product deleted or renamed next
                // month must not turn a manager's notes into a dangling number.
                'name' => $item->product->t('name'),
                'qty' => $item->qty,
                // Minor units in the shop's books, like every stored amount.
                'unit_price' => $item->unit_price,
            ];
        }

        return $lines ?: null;
    }

    /**
     * The same name for this person that an order would carry, so a CRM or 1C
     * sees one counterparty rather than a lead and a customer who happen to
     * share a phone. Derived, never looked up — see Checkout\Orders\CustomerRef.
     *
     * @param  array<string, mixed>  $values
     */
    protected function customerRef(array $values): ?string
    {
        if (! class_exists(CustomerRef::class)) {
            return null;
        }

        return app(CustomerRef::class)->for([
            'user_id' => auth()->id(),
            'phone' => $values['phone'] ?? null,
            'email' => $values['email'] ?? null,
        ]);
    }
}
