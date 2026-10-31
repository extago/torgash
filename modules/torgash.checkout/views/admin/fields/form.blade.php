@extends('admin.layout')

@section('title', $field->exists ? __('Edit field') : __('New field'))
@section('own_heading', '1')

{{-- One language of the words per save, the one picked at the top
     (x-admin.translatable-field + put_translation in the controller). --}}
@section('content')
    @php
        $fieldLocale = admin_content_locale();
        $fieldType = old('type', $field->type);
        $fieldMethods = array_map('strval', old('shipping_methods', $field->methodIds()));
    @endphp

    <form method="POST" class="flex flex-col gap-4" x-data="{ type: @js($fieldType) }"
          action="{{ $field->exists ? route('admin.checkout.fields.update', $field) : route('admin.checkout.fields.store') }}">
        @csrf
        @if ($field->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.checkout.fields.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to fields') }}" aria-label="{{ __('Back to fields') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $field->exists ? __('Edit field') : __('New field') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $field->exists ? ($field->t('label', $fieldLocale) ?: $field->code) : __('New field') }}</div>
            </div>
            @if ($field->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="checkout-field-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.checkout.fields.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $field->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <section class="card bg-base-100 border border-base-300 min-w-0">
                <div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('The question') }}</h3>
                    <fieldset class="fieldset p-0 max-w-sm"><legend class="fieldset-legend">{{ __('Type') }} *</legend>
                        <select name="type" x-model="type" class="select select-bordered w-full">
                            @foreach (\Torgash\Checkout\Models\CheckoutField::TYPES as $typeKey => $typeLabel)
                                <option value="{{ $typeKey }}" @selected($fieldType === $typeKey)>{{ __($typeLabel) }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                    <x-admin.translatable-field name="label" label="{{ __('Name') }}" :value="$field->label ?? []" :required="content_locale_is_base()" />
                    <x-admin.translatable-field name="help" label="{{ __('Description under it') }}" :value="$field->help ?? []" textarea :rows="2" />
                    <div x-show="['text', 'textarea', 'select'].includes(type)">
                        <x-admin.translatable-field name="placeholder" label="{{ __('Hint inside the box') }}" :value="$field->placeholder ?? []" />
                    </div>
                    <div x-show="['select', 'radio'].includes(type)" x-cloak>
                        <x-admin.translatable-field name="options" label="{{ __('Answers, one per line') }}" :value="$field->options ?? []" textarea :rows="5" />
                        <p class="text-xs opacity-60 mt-1">{{ __("Another language's answers are matched by their line: keep the same order in every language. The order keeps the base language's words, so the answers can be counted.") }}</p>
                    </div>
                    <label class="label justify-start gap-2 p-0 cursor-pointer">
                        <input type="checkbox" name="required" value="1" class="toggle toggle-success toggle-sm" @checked(old('required', $field->required))>
                        <span class="label-text whitespace-normal">{{ __('Required') }}</span>
                    </label>
                    <p class="text-xs opacity-60 -mt-2">{{ __("Only where it is asked: a courier's question is not required of an order collected from a branch.") }}</p>
                </div>
            </section>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Where and of whom') }}</h3>
                        <label class="label justify-start gap-2 p-0 cursor-pointer">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-success toggle-sm" @checked(old('enabled', $field->enabled ?? true))>
                            <span class="label-text whitespace-normal">{{ __('Asked at checkout') }}</span>
                        </label>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Where on the page') }}</legend>
                            <select name="place" class="select select-bordered w-full">
                                @foreach (\Torgash\Checkout\Models\CheckoutField::PLACES as $placeKey => $placeLabel)
                                    <option value="{{ $placeKey }}" @selected(old('place', $field->place) === $placeKey)>{{ __($placeLabel) }}</option>
                                @endforeach
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Asked of') }}</legend>
                            <select name="buyer" class="select select-bordered w-full">
                                @foreach (\Torgash\Checkout\Models\CheckoutField::BUYERS as $buyerKey => $buyerLabel)
                                    <option value="{{ $buyerKey }}" @selected(old('buyer', $field->buyer) === $buyerKey)>{{ __($buyerLabel) }}</option>
                                @endforeach
                            </select>
                            <p class="label whitespace-normal">{{ __('Private person or company — when the shop takes orders from companies (checkout settings).') }}</p>
                        </fieldset>
                        @if ($methods)
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Only for these deliveries') }}</legend>
                                <div class="flex flex-col gap-1.5">
                                    @foreach ($methods as $methodId => $methodName)
                                        <label class="label justify-start gap-2 p-0 cursor-pointer">
                                            <input type="checkbox" name="shipping_methods[]" value="{{ $methodId }}" class="checkbox checkbox-sm" @checked(in_array((string) $methodId, $fieldMethods, true))>
                                            <span class="label-text whitespace-normal">{{ $methodName }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="label whitespace-normal">{{ __('None ticked — whatever the delivery.') }}</p>
                            </fieldset>
                        @endif
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Code') }}</h3>
                        <input name="code" value="{{ old('code', $field->code) }}" class="input input-bordered w-full font-mono" placeholder="recipient_name" autocomplete="off">
                        <p class="text-xs opacity-60">
                            {{-- The automation's variable built in PHP: braces in a Blade echo end it. --}}
                            {{ __('What an automation names the answer by — :variable; a letter prints every answer with {order_fields}. Left empty, it is made from the name.', ['variable' => '{'.'{ order.fields.'.($field->code ?: 'CODE').' }'.'}']) }}
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($field->exists)
        {{-- Beside the form, never inside it: a nested form's inputs leak. --}}
        <form id="checkout-field-delete" method="POST" action="{{ route('admin.checkout.fields.destroy', $field) }}"
              data-confirm="{{ __('Delete this field? Orders keep the answers they were given.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
