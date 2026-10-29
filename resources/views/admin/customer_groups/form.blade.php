@extends('admin.layout')

@section('title', $group->exists ? $group->t('name', admin_content_locale()) : __('New group'))
@section('own_heading', '1')

@section('content')
    <form method="POST" id="customer-group-form"
          action="{{ $group->exists ? route('admin.customer-groups.update', $group) : route('admin.customer-groups.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($group->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.customer-groups.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to customer groups') }}" aria-label="{{ __('Back to customer groups') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Customer group') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $group->exists ? $group->t('name', admin_content_locale()) : __('New group') }}</div>
            </div>
            @if ($group->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="customer-group-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.customer-groups.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $group->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Group') }}</h3>

                    <x-admin.translatable-field name="name" :label="__('Name')" :value="$group->name ?? []" required :placeholder="__('e.g. Wholesale')" />

                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Discount off list price') }}</legend>
                        <label class="input input-bordered w-full max-w-48">
                            <input name="discount_percent" type="number" min="0" max="100"
                                   value="{{ old('discount_percent', $group->discount_percent ?? 0) }}" required class="grow">
                            <span class="opacity-50">%</span>
                        </label>
                        <p class="label text-xs opacity-60 whitespace-normal">
                            {{ __('Applied to every product\'s price while a customer of this group is signed in — the storefront, the cart and checkout all use it. For prices of their own per product, give the group a price type in the catalogue.') }}
                        </p>
                    </fieldset>
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Who is in it') }}</h3>
                    <label class="label cursor-pointer justify-start items-start gap-3 whitespace-normal">
                        <input type="checkbox" name="is_default" value="1" class="toggle toggle-sm toggle-success"
                               @checked(old('is_default', $group->is_default ?? false))>
                        <span class="label-text">{{ __('Every new registration joins this group') }}</span>
                    </label>
                    <p class="text-xs opacity-60">{{ __('One group at most; choosing it here takes it off any other.') }}</p>
                    @if ($group->exists)
                        <div class="flex justify-between gap-2 text-sm pt-1">
                            <span class="opacity-60">{{ __('Customers') }}</span>
                            @if ($group->users_count > 0)
                                <a href="{{ route('admin.customers.index', ['group' => $group->id]) }}" class="link link-hover font-semibold tabular-nums">{{ $group->users_count }}</a>
                            @else
                                <span class="opacity-50">{{ __('none yet') }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </form>

    {{-- Beside the form, never inside it — see docs/LESSONS.md. --}}
    @if ($group->exists)
        <form id="customer-group-delete" method="POST" action="{{ route('admin.customer-groups.destroy', $group) }}" class="hidden"
              data-confirm="{{ __('Delete this group? Its customers will see list prices.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
