<?php

namespace Torgash\Enquiries\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Form;

/**
 * The shop's own forms (PLAN §48): a name, the questions, what the button and
 * the thank-you say, an "I agree" when wanted, and where the letters go.
 *
 * Every word is written in ONE language at a time — the content language at
 * the top — like any other text the shop types; the structure (which fields,
 * of what type, in what order) is the same in every language. Options are
 * matched by POSITION across languages, so a list translated line by line
 * keeps the values its answers were stored under.
 */
class FormsController extends Controller
{
    public function index()
    {
        $forms = Form::query()->orderBy('id')->get();

        // One query for every form's count, not one per row.
        $counts = Enquiry::query()
            ->whereIn('kind', $forms->map->kind())
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return view('torgash.enquiries::admin.forms.index', compact('forms', 'counts'));
    }

    public function create()
    {
        // A start rather than a blank sheet: nearly every form asks who and how
        // to reach them. In the language being written, like anything typed.
        $locale = admin_content_locale();

        return $this->editor(new Form([
            'enabled' => true,
            'fields' => [
                ['key' => 'name', 'type' => 'name', 'label' => [$locale => trans('Your name', [], $locale)], 'required' => false],
                ['key' => 'phone', 'type' => 'tel', 'label' => [$locale => trans('Phone', [], $locale)], 'required' => true],
            ],
        ]));
    }

    public function store(Request $request)
    {
        $form = new Form;
        $this->save($request, $form);

        return $this->afterSave($request, $form, __('Form created.'));
    }

    public function edit(Form $form)
    {
        return $this->editor($form);
    }

    public function update(Request $request, Form $form)
    {
        $this->save($request, $form);

        return $this->afterSave($request, $form, __('Form saved.'));
    }

    /**
     * A form that has brought enquiries is never deleted: they keep its kind,
     * and an inbox row titled "form.12" helps nobody. Switching it off hides
     * it everywhere it was placed, which is what deleting was for.
     */
    public function destroy(Form $form)
    {
        if (Enquiry::query()->where('kind', $form->kind())->exists()) {
            return back()->with('warning', __('This form has brought enquiries, so it stays — switch it off instead, and it disappears from every page.'));
        }

        $form->delete();

        return redirect()->route('admin.enquiries.forms.index')->with('status', __('Form deleted.'));
    }

    protected function editor(Form $form)
    {
        return view('torgash.enquiries::admin.forms.form', [
            'form' => $form,
            'managers' => User::staff()->orderBy('name')->get(['id', 'name']),
            'types' => Form::TYPES,
        ]);
    }

    protected function afterSave(Request $request, Form $form, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.enquiries.forms.index')
            : redirect()->route('admin.enquiries.forms.edit', $form))->with('status', $message);
    }

    protected function save(Request $request, Form $form): void
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'name' => [content_locale_is_base() && ! $form->exists ? 'required' : 'nullable', 'string', 'max:190'],
            'code' => ['nullable', 'string', 'max:64'],
            'intro' => ['nullable', 'string', 'max:2000'],
            'button' => ['nullable', 'string', 'max:64'],
            'success' => ['nullable', 'string', 'max:500'],
            'consent_text' => ['nullable', 'string', 'max:500'],
            // An address on this site or anywhere: "/page/privacy" or https://…
            'consent_url' => ['nullable', 'string', 'max:500', 'regex:~^(/|https?://)~i'],
            'notify_email' => ['nullable', 'string', 'max:500'],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'fields' => ['required', 'array', 'min:1', 'max:40'],
            'fields.*.key' => ['nullable', 'string', 'max:64'],
            'fields.*.type' => ['required', Rule::in(array_keys(Form::TYPES))],
            'fields.*.label' => ['nullable', 'string', 'max:190'],
            'fields.*.help' => ['nullable', 'string', 'max:300'],
            'fields.*.options' => ['nullable', 'string', 'max:4000'],
        ], [
            'fields.required' => __('A form needs at least one field.'),
            'fields.min' => __('A form needs at least one field.'),
        ]);

        $emails = array_values(array_filter(array_map('trim', explode(',', (string) ($validated['notify_email'] ?? '')))));
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['notify_email' => __(':value is not an email address.', ['value' => $email])]);
            }
        }

        $name = put_translation($form->name, $validated['name'] ?? '', $locale);

        $form->fill([
            'name' => $name,
            'code' => $this->code($validated['code'] ?? '', $name, $form),
            'intro' => put_translation($form->intro, $validated['intro'] ?? '', $locale),
            'button' => put_translation($form->button, $validated['button'] ?? '', $locale),
            'success' => put_translation($form->success, $validated['success'] ?? '', $locale),
            'consent' => $request->boolean('consent'),
            'consent_text' => put_translation($form->consent_text, $validated['consent_text'] ?? '', $locale),
            'consent_url' => $validated['consent_url'] ?? null,
            'notify_email' => $emails ? implode(', ', $emails) : null,
            'manager_id' => $validated['manager_id'] ?? null,
            'enabled' => $request->boolean('enabled'),
            'fields' => $this->fields($request->input('fields', []), $form->fields ?? [], $locale),
        ])->save();
    }

    /** What `{form:…}` will name it: typed, or made from the name; unique. */
    protected function code(string $typed, array $name, Form $form): string
    {
        $source = $typed !== '' ? $typed : (string) ($name[content_base_locale()] ?? reset($name) ?: 'form');
        $base = Str::slug($source) ?: 'form';
        $code = $base;

        for ($n = 2; Form::where('code', $code)->whereKeyNot($form->id ?? 0)->exists(); $n++) {
            $code = $base.'-'.$n;
        }

        return $code;
    }

    /**
     * The questions as typed in THIS language, merged into what is stored.
     *
     * A row keeps its key while it keeps its place in the answers' history:
     * the key is what an enquiry's values are stored under, so renaming a
     * label must never mint a new one.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $stored
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    protected function fields(array $rows, array $stored, string $locale): array
    {
        $previous = collect($stored)->keyBy('key');
        $own = array_flip(Form::OWN_KEYS);
        $taken = [];
        $fields = [];

        foreach (array_values($rows) as $i => $row) {
            $type = (string) ($row['type'] ?? 'text');
            $before = $previous->get((string) ($row['key'] ?? ''));
            $label = put_translation($before['label'] ?? null, (string) ($row['label'] ?? ''), $locale);

            if ($label === []) {
                throw ValidationException::withMessages(["fields.$i.label" => __('Every field needs a label.')]);
            }

            if (isset(Form::OWN_KEYS[$type])) {
                $key = Form::OWN_KEYS[$type];

                if (isset($taken[$key])) {
                    throw ValidationException::withMessages(["fields.$i.type" => __('A form has one field of the type ":type".', ['type' => __(Form::TYPES[$type])])]);
                }
            } else {
                // Its stored key, unless that key belonged to a "name / phone /
                // email" field this row has stopped being.
                $key = $before && ! isset($own[$before['key']]) && ! isset($taken[$before['key']])
                    ? $before['key']
                    : $this->freshKey($label, $taken, $i);
            }

            $taken[$key] = true;

            $fields[] = [
                'key' => $key,
                'type' => $type,
                'label' => $label,
                'help' => put_translation($before['help'] ?? null, (string) ($row['help'] ?? ''), $locale),
                'required' => filter_var($row['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'options' => in_array($type, Form::WITH_OPTIONS, true)
                    ? $this->options((string) ($row['options'] ?? ''), $before['options'] ?? [], $locale, $i)
                    : [],
            ];
        }

        return $fields;
    }

    /** @param  array<string, true>  $taken */
    protected function freshKey(array $label, array $taken, int $i): string
    {
        $base = Str::slug((string) ($label[content_base_locale()] ?? reset($label)), '_') ?: 'field_'.($i + 1);
        $base = in_array($base, Form::OWN_KEYS, true) || $base === 'consent' ? $base.'_answer' : $base;
        $key = $base;

        for ($n = 2; isset($taken[$key]); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }

    /**
     * One answer per line, in this language, matched to the stored ones by
     * POSITION. In the base language a removed line removes the answer; in a
     * translation it only removes that language's words.
     *
     * @param  list<array{value: string, label: array<string, string>}>  $stored
     * @return list<array{value: string, label: array<string, string>}>
     *
     * @throws ValidationException
     */
    protected function options(string $typed, array $stored, string $locale, int $i): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $typed) ?: []), fn ($line) => $line !== ''));
        $base = content_locale_is_base();
        $count = $base ? count($lines) : max(count($lines), count($stored));
        $options = [];
        $values = [];

        for ($n = 0; $n < $count; $n++) {
            $before = $stored[$n] ?? null;
            $label = put_translation($before['label'] ?? null, $lines[$n] ?? '', $locale);

            if ($label === []) {
                continue;
            }

            $value = $before['value'] ?? (Str::slug($lines[$n] ?? '', '_') ?: 'option_'.($n + 1));
            for ($k = 2; isset($values[$value]); $k++) {
                $value = ($before['value'] ?? 'option').'_'.$k;
            }

            $values[$value] = true;
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        if ($options === []) {
            throw ValidationException::withMessages(["fields.$i.options" => __('A list needs at least one answer — one per line.')]);
        }

        return $options;
    }
}
