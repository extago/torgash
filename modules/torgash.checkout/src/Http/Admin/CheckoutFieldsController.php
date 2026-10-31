<?php

namespace Torgash\Checkout\Http\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Checkout\Models\CheckoutField;

/**
 * Admin → Checkout fields: the shop's own questions at checkout
 * (Models\CheckoutField). One language of the words per save — the content
 * language picked at the top (put_translation).
 */
class CheckoutFieldsController extends Controller
{
    public function index()
    {
        return view('torgash.checkout::admin.fields.index', [
            'fields' => CheckoutField::query()->orderBy('sort')->orderBy('id')->get(),
            'methods' => $this->methods(),
        ]);
    }

    public function create()
    {
        return $this->form(new CheckoutField(['type' => 'text', 'place' => 'recipient', 'buyer' => 'all', 'enabled' => true]));
    }

    public function edit(CheckoutField $field)
    {
        return $this->form($field);
    }

    public function store(Request $request)
    {
        $field = new CheckoutField(['sort' => ((int) CheckoutField::max('sort')) + 10]);
        $this->fill($request, $field);

        return $this->afterSave($request, $field, __('Field created.'));
    }

    public function update(Request $request, CheckoutField $field)
    {
        $this->fill($request, $field);

        return $this->afterSave($request, $field, __('Field saved.'));
    }

    /** Orders keep their answers: they are a copy of what was asked. */
    public function destroy(CheckoutField $field)
    {
        $field->delete();

        return redirect()->route('admin.checkout.fields.index')->with('status', __('Field deleted. Orders keep the answers they were given.'));
    }

    /** The order as dragged: ids first to last. */
    public function sort(Request $request)
    {
        foreach (array_values((array) $request->input('ids', [])) as $position => $id) {
            CheckoutField::whereKey((int) $id)->update(['sort' => ($position + 1) * 10]);
        }

        return response()->json(['ok' => true]);
    }

    protected function form(CheckoutField $field)
    {
        return view('torgash.checkout::admin.fields.form', ['field' => $field, 'methods' => $this->methods()]);
    }

    /** @return array<string, string> the shipping methods by id — none without the shipping module */
    protected function methods(): array
    {
        return (array) hook_filter('checkout.shipping_methods.choices', []);
    }

    protected function fill(Request $request, CheckoutField $field): void
    {
        $base = content_locale_is_base();
        $validated = $request->validate([
            'label' => [$base ? 'required' : 'nullable', 'string', 'max:190'],
            'help' => 'nullable|string|max:500',
            'placeholder' => 'nullable|string|max:190',
            'options' => [Rule::requiredIf($base && in_array($request->input('type'), CheckoutField::WITH_OPTIONS, true)), 'nullable', 'string', 'max:4000'],
            'code' => ['nullable', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('m_checkout_fields', 'code')->ignore($field)],
            'type' => ['required', Rule::in(array_keys(CheckoutField::TYPES))],
            'place' => ['required', Rule::in(array_keys(CheckoutField::PLACES))],
            'buyer' => ['required', Rule::in(array_keys(CheckoutField::BUYERS))],
            'shipping_methods' => 'nullable|array',
            'shipping_methods.*' => ['string', Rule::in(array_keys($this->methods()))],
        ], [
            'code.regex' => __('Small Latin letters, digits and underscores, starting with a letter.'),
        ]);

        $field->fill([
            'label' => put_translation($field->label, $validated['label'] ?? null),
            'help' => put_translation($field->help, $validated['help'] ?? null) ?: null,
            'placeholder' => put_translation($field->placeholder, $validated['placeholder'] ?? null) ?: null,
            'options' => put_translation($field->options, $validated['options'] ?? null) ?: null,
            'type' => $validated['type'],
            'place' => $validated['place'],
            'buyer' => $validated['buyer'],
            'shipping_methods' => array_values($validated['shipping_methods'] ?? []) ?: null,
            'required' => $request->boolean('required'),
            'enabled' => $request->boolean('enabled'),
        ]);

        // The code is what a letter and an automation name it by
        // (`order.fields.<code>`): typed, or made from the label once.
        $field->code = $validated['code'] ?? null ?: $field->code ?: $this->codeFrom((string) $field->t('label', content_base_locale()));
        $field->save();
    }

    protected function codeFrom(string $label): string
    {
        $code = Str::slug(Str::ascii($label), '_') ?: 'field';
        $code = preg_match('/^[a-z]/', $code) ? $code : 'field_'.$code;
        $code = Str::limit($code, 50, '');
        $candidate = $code;

        for ($n = 2; CheckoutField::where('code', $candidate)->exists(); $n++) {
            $candidate = $code.'_'.$n;
        }

        return $candidate;
    }

    protected function afterSave(Request $request, CheckoutField $field, string $status)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.checkout.fields.index')
            : redirect()->route('admin.checkout.fields.edit', $field))
            ->with('status', $status);
    }
}
