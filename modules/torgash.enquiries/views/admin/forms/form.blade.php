@extends('admin.layout')

{{--
    One of the shop's own forms (PLAN §48). The questions are an Alpine list —
    add, reorder, change a type — whose inputs carry ordinary names
    (fields[0][label] …), so the page posts like any form and a refused save
    comes back with what was typed. Words are in the content language picked
    at the top; the structure is the same in every language.
--}}
@php
    $formLocale = admin_content_locale();
    $baseLocale = content_base_locale();

    // The rows as this language shows them: its own words, and the base
    // language's beside an untranslated one, so a blank reads as "not yet
    // translated" rather than "lost".
    $wordsOf = fn ($map) => is_array($map) ? (string) ($map[$formLocale] ?? '') : '';
    $hintOf = fn ($map) => is_array($map) && ($map[$formLocale] ?? '') === '' ? (string) (collect($map)->except($formLocale)->filter()->first() ?? '') : '';

    $editorRows = old('fields') !== null
        ? collect(old('fields'))->values()->map(fn ($row) => [
            'key' => (string) ($row['key'] ?? ''),
            'type' => (string) ($row['type'] ?? 'text'),
            'label' => (string) ($row['label'] ?? ''),
            'help' => (string) ($row['help'] ?? ''),
            'required' => filter_var($row['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'options' => (string) ($row['options'] ?? ''),
            'labelHint' => '', 'helpHint' => '', 'optionsHint' => '',
        ])
        : collect($form->fields ?? [])->map(fn ($field) => [
            'key' => (string) $field['key'],
            'type' => (string) $field['type'],
            'label' => $wordsOf($field['label'] ?? null),
            'help' => $wordsOf($field['help'] ?? null),
            'required' => (bool) ($field['required'] ?? false),
            'options' => collect($field['options'] ?? [])->map(fn ($o) => $wordsOf($o['label'] ?? null))->implode("\n"),
            'labelHint' => $hintOf($field['label'] ?? null),
            'helpHint' => $hintOf($field['help'] ?? null),
            'optionsHint' => collect($field['options'] ?? [])->map(fn ($o) => $hintOf($o['label'] ?? null))->filter()->implode("\n"),
        ]);

    // Server-side errors, by row, for the row to show under itself.
    $rowErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key) => str_starts_with($key, 'fields.'))
        ->mapWithKeys(fn ($messages, $key) => [$key => $messages[0]]);

    $typeWords = collect($types)->map(fn ($label) => __($label));
@endphp

@section('title', $form->exists ? ($form->t('name', $formLocale) ?: __('Form')) : __('New form'))
@section('own_heading', '1')

@section('content')
    <form method="POST" class="flex flex-col gap-4"
          action="{{ $form->exists ? route('admin.enquiries.forms.update', $form) : route('admin.enquiries.forms.store') }}">
        @csrf
        @if ($form->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.enquiries.forms.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Forms') }}" aria-label="{{ __('Forms') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Form') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $form->t('name', $formLocale) ?: __('New form') }}</div>
            </div>
            @if ($form->exists)
                {{-- The record's tools behind the gear, as on every other edit
                     screen: the bar's own buttons are Cancel and Save alone. --}}
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="enquiry-form-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.enquiries.forms.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $form->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if (session('warning'))
            <div class="alert alert-warning text-sm">{{ session('warning') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <x-admin.translatable-field name="name" :label="__('Name')" :value="$form->name ?? []" required />
                        <x-admin.translatable-field name="intro" :label="__('Text above the form')" :value="$form->intro ?? []" textarea :rows="2" />
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300"
                         x-data="{
                             rows: {{ \Illuminate\Support\Js::from($editorRows) }},
                             errors: {{ \Illuminate\Support\Js::from($rowErrors) }},
                             withOptions: {{ \Illuminate\Support\Js::from(\Torgash\Enquiries\Models\Form::WITH_OPTIONS) }},
                             add(type) {
                                 this.rows.push({ key: '', type, label: '', help: '', required: false, options: '', labelHint: '', helpHint: '', optionsHint: '' });
                                 this.$nextTick(() => this.$refs.list.lastElementChild?.querySelector('input[data-label]')?.focus());
                             },
                             move(i, step) {
                                 const j = i + step;
                                 if (j < 0 || j >= this.rows.length) return;
                                 const [row] = this.rows.splice(i, 1);
                                 this.rows.splice(j, 0, row);
                             },
                             error(i, part) { return this.errors['fields.' + i + '.' + part] || ''; },
                         }">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <h3 class="font-semibold">{{ __('Fields') }}</h3>
                            <div class="dropdown dropdown-end">
                                <div tabindex="0" role="button" class="btn btn-sm gap-1.5"><x-icon name="plus" size="sm" /> {{ __('Add a field') }}</div>
                                <ul tabindex="0" class="dropdown-content menu bg-base-100 border border-base-300 rounded-box z-30 w-56 p-1 shadow">
                                    @foreach ($typeWords as $typeKey => $typeLabel)
                                        <li><button type="button" @click="add(@js($typeKey)); $el.closest('.dropdown').querySelector('[tabindex]').blur()">{{ $typeLabel }}</button></li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>

                        <p class="text-xs opacity-60">{{ __('Name, phone and email are the person\'s own details: the enquiry is filed under them, and a form that has a phone or an email needs at least one of them filled in.') }}</p>

                        <div x-ref="list" class="flex flex-col gap-3">
                            <template x-for="(row, i) in rows" :key="i + '-' + row.key + '-' + row.type">
                                <div class="rounded-box border border-base-300 bg-base-200/40 p-3 flex flex-col gap-2">
                                    <input type="hidden" :name="`fields[${i}][key]`" :value="row.key">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="badge badge-ghost badge-sm tabular-nums" x-text="i + 1"></span>
                                        <select :name="`fields[${i}][type]`" x-model="row.type" class="select select-bordered w-48">
                                            @foreach ($typeWords as $typeKey => $typeLabel)
                                                <option value="{{ $typeKey }}">{{ $typeLabel }}</option>
                                            @endforeach
                                        </select>
                                        <label class="label cursor-pointer gap-2 text-sm">
                                            <input type="hidden" :name="`fields[${i}][required]`" value="0">
                                            <input type="checkbox" class="checkbox checkbox-sm" :name="`fields[${i}][required]`" value="1" x-model="row.required">
                                            {{ __('Required field') }}
                                        </label>
                                        <span class="ms-auto flex items-center gap-1">
                                            <button type="button" class="btn btn-ghost btn-sm btn-square" :disabled="i === 0" @click="move(i, -1)" title="{{ __('Move up') }}" aria-label="{{ __('Move up') }}"><x-icon name="arrow-up" size="sm" /></button>
                                            <button type="button" class="btn btn-ghost btn-sm btn-square" :disabled="i === rows.length - 1" @click="move(i, 1)" title="{{ __('Move down') }}" aria-label="{{ __('Move down') }}"><x-icon name="arrow-down" size="sm" /></button>
                                            <span class="w-px h-5 bg-base-300 mx-1" aria-hidden="true"></span>
                                            <button type="button" class="btn btn-ghost btn-sm btn-square text-error" @click="rows.splice(i, 1)" title="{{ __('Remove the field') }}" aria-label="{{ __('Remove the field') }}"><x-icon name="trash-2" size="sm" /></button>
                                        </span>
                                    </div>
                                    <div class="grid gap-2 md:grid-cols-2">
                                        <label class="flex flex-col gap-1">
                                            <span class="text-xs opacity-60">{{ __('Label') }} <span class="opacity-60">({{ strtoupper($formLocale) }})</span></span>
                                            <input type="text" data-label :name="`fields[${i}][label]`" x-model="row.label" :placeholder="row.labelHint"
                                                   class="input input-bordered w-full" :class="error(i, 'label') && 'input-error'">
                                            <span class="text-xs text-error" x-show="error(i, 'label') || error(i, 'type')" x-text="error(i, 'label') || error(i, 'type')"></span>
                                        </label>
                                        <label class="flex flex-col gap-1">
                                            <span class="text-xs opacity-60">{{ __('Hint under the field') }}</span>
                                            <input type="text" :name="`fields[${i}][help]`" x-model="row.help" :placeholder="row.helpHint" class="input input-bordered w-full">
                                        </label>
                                    </div>
                                    <label class="flex flex-col gap-1" x-show="withOptions.includes(row.type)">
                                        <span class="text-xs opacity-60">{{ __('Answers, one per line') }}</span>
                                        <textarea :name="`fields[${i}][options]`" x-model="row.options" :placeholder="row.optionsHint" rows="3"
                                                  class="textarea textarea-bordered w-full" :class="error(i, 'options') && 'textarea-error'"
                                                  :disabled="!withOptions.includes(row.type)"></textarea>
                                        <span class="text-xs text-error" x-show="error(i, 'options')" x-text="error(i, 'options')"></span>
                                    </label>
                                    <p class="text-xs opacity-60" x-show="row.type === 'file'">{{ __('Photos only — kept privately with the enquiry. How many and how large is set in the Enquiries and Media settings.') }}</p>
                                </div>
                            </template>
                        </div>

                        <div x-show="!rows.length" class="text-sm opacity-60 text-center py-6">{{ __('No fields yet — add the first one.') }}</div>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Where it is shown') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $form->enabled ?? true))>
                            <span class="label-text">{{ __('On') }}</span>
                        </label>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Code') }}</legend>
                            <input name="code" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('code', $form->code) }}" class="input input-bordered w-full font-mono" placeholder="{{ __('made from the name') }}">
                        </fieldset>
                        @if ($form->exists)
                            <div class="text-sm flex flex-col gap-2">
                                <p class="opacity-70">{{ __('Put it into the text of a page or an article on a line of its own:') }}</p>
                                <div class="flex items-center gap-2" x-data="{ copied: false }">
                                    <code class="px-2 py-1 rounded bg-base-200 font-mono text-sm">{form:{{ $form->code }}}</code>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="navigator.clipboard?.writeText(@js('{form:'.$form->code.'}')); copied = true; setTimeout(() => copied = false, 1500)">
                                        <x-icon name="copy" size="sm" /> <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                                    </button>
                                </div>
                                <p class="opacity-70">{{ __('Or into a zone of the page: Blocks → "Enquiry form", and choose this form.') }}</p>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Sending') }}</h3>
                        <x-admin.translatable-field name="button" :label="__('Button')" :value="$form->button ?? []" :placeholder="__('Send')" />
                        <x-admin.translatable-field name="success" :label="__('After sending')" :value="$form->success ?? []" textarea :rows="2" :placeholder="__('Thank you — we will be in touch.')" />
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300" x-data="{ on: @js((bool) old('consent', $form->consent)) }">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Consent') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="consent" value="1" class="toggle toggle-sm toggle-success" x-model="on">
                            <span class="label-text whitespace-normal">{{ __('Ask to agree to the processing of personal data') }}</span>
                        </label>
                        <div x-show="on" class="flex flex-col gap-3">
                            <x-admin.translatable-field name="consent_text" :label="__('Words beside the box')" :value="$form->consent_text ?? []" :placeholder="__('I agree to the processing of my personal data')" />
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Link to the policy') }}</legend>
                                <input name="consent_url" value="{{ old('consent_url', $form->consent_url) }}" class="input input-bordered w-full" placeholder="/page/privacy">
                            </fieldset>
                            <p class="text-xs opacity-60">{{ __('The box must be ticked to send, and the words are kept with the enquiry as it was agreed to.') }}</p>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Where the enquiries go') }}</h3>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Send the letter to') }}</legend>
                            <input name="notify_email" value="{{ old('notify_email', $form->notify_email) }}" class="input input-bordered w-full" placeholder="hr@shop.com">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Empty — the shop\'s usual address for enquiries. Several are separated by commas.') }}</p>
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Manager') }}</legend>
                            <select name="manager_id" class="select select-bordered w-full">
                                <option value="">{{ __('Nobody yet') }}</option>
                                @foreach ($managers as $manager)
                                    <option value="{{ $manager->id }}" @selected((int) old('manager_id', $form->manager_id) === $manager->id)>{{ $manager->name }}</option>
                                @endforeach
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Every enquiry from this form is put in their hands.') }}</p>
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($form->exists)
        <form id="enquiry-form-delete" method="POST" action="{{ route('admin.enquiries.forms.destroy', $form) }}"
              data-confirm="{{ __('Delete this form? Wherever it was placed, it disappears.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
