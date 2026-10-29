@extends('admin.layout')

@section('title', $status->exists ? __('Edit status') : __('New status'))
@section('own_heading', '1')

@section('content')
    @php
        $action = old('inventory_action', $status->inventory_action ?? 'none');
        $stockChoices = [
            'decrease' => ['package-minus', __('Takes the goods off the shelf'), __('While an order is in this status its goods are committed — "open", "shipped". Moving into it takes them once; moving on to another such status takes nothing more.')],
            'increase' => ['package-plus', __('Puts the goods back on the shelf'), __('For an order that will not go ahead — "cancelled", "declined". Moving into it gives back what the order had taken, once.')],
            'none' => ['minus', __('Does not touch the stock'), __('The order keeps whatever it already had: a step of your own in between, "waiting for a call".')],
        ];
    @endphp

    <form method="POST" class="flex flex-col gap-4"
          action="{{ $status->exists ? route('admin.orders.statuses.update', $status) : route('admin.orders.statuses.store') }}"
          x-data="{ color: @js(old('color', $status->color ?? '#97cf4d')), name: @js(old('name', $status->name[admin_content_locale()] ?? '')), fallback: @js($status->exists ? $status->t('name') : '') }">
        @csrf
        @if ($status->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.orders.statuses.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to statuses') }}" aria-label="{{ __('Back to statuses') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $status->exists ? __('Edit status') : __('New status') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $status->exists ? $status->t('name') : __('New status') }}</div>
            </div>
            @if ($status->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        <li>
                            <a href="{{ route('admin.orders.index', ['status' => [$status->code]]) }}">
                                <x-icon name="list" /> {{ __('Orders in this status') }}
                            </a>
                        </li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.orders.statuses.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $status->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>
                        <x-admin.translatable-field name="name" :label="__('Name')" :value="$status->name ?? []" required x-model="name"
                                                    :placeholder="__('e.g. Shipped')" />
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }} *</legend>
                            <input name="code" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('code', $status->code) }}" required pattern="[A-Za-z0-9_-]+"
                                   class="input input-bordered w-full max-w-xs font-mono" placeholder="shipped" @disabled($status->exists)>
                            <p class="label text-xs opacity-60 whitespace-normal">
                                {{ $status->exists
                                    ? __('The code cannot be changed: orders, automations and 1C refer to the status by it.')
                                    : __('Latin letters, digits, dashes and underscores. Orders, automations and 1C refer to the status by it, so it cannot be changed later.') }}
                            </p>
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('What it does to the stock') }}</h3>
                        <div class="flex flex-col gap-2">
                            @foreach ($stockChoices as $value => [$icon, $title, $text])
                                <label class="flex items-start gap-3 rounded-box border p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5 border-base-300">
                                    <input type="radio" name="inventory_action" value="{{ $value }}" class="radio radio-sm mt-0.5" @checked($action === $value)>
                                    <div>
                                        <div class="font-medium flex items-center gap-1.5"><x-icon :name="$icon" size="sm" class="opacity-60" /> {{ $title }}</div>
                                        <div class="text-xs opacity-60 mt-0.5">{{ $text }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Look') }}</h3>
                        <div class="flex items-center gap-3">
                            <input name="color" type="color" x-model="color" class="w-12 h-10 rounded cursor-pointer border border-base-300" aria-label="{{ __('Colour') }}">
                            {{-- What the lists will show. --}}
                            <span class="badge gap-1.5 bg-base-100 border-base-300">
                                <span class="h-2 w-2 rounded-full inline-block" :style="'background-color:' + color"></span>
                                <span x-text="name || fallback || @js(__('Status'))"></span>
                            </span>
                        </div>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                            <input name="position" type="number" value="{{ old('position', $status->position ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('The order of the tabs over the orders list and of the choices in a status select.') }}</p>
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-2">
                        <h3 class="font-semibold">{{ __('For new orders') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="is_default" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('is_default', $status->is_default ?? false))>
                            <span class="label-text whitespace-normal">{{ __('A new order gets this status') }}</span>
                        </label>
                        <p class="text-xs opacity-60">{{ __('Only one status can be it: switching it on here switches it off on the other. An order paid online first waits in “awaiting payment”, and gets this one when the money arrives.') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </form>
@endsection
