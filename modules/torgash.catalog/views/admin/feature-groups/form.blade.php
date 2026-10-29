@extends('admin.layout')

@section('title', $group->exists ? __('Edit group') : __('New group'))
@section('own_heading', '1')

{{-- One language of the name per save, the one picked at the top
     (x-admin.translatable-field + put_translation in the controller). --}}
@section('content')
    @php $groupLocale = admin_content_locale(); @endphp

    <form method="POST" class="flex flex-col gap-4"
          action="{{ $group->exists ? route('admin.catalog.feature-groups.update', $group) : route('admin.catalog.feature-groups.store') }}">
        @csrf
        @if ($group->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.catalog.feature-groups.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to groups') }}" aria-label="{{ __('Back to groups') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $group->exists ? __('Edit group') : __('New group') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $group->exists ? ($group->t('name', $groupLocale) ?: '#'.$group->id) : __('New group') }}</div>
            </div>
            @if ($group->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="feature-group-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.catalog.feature-groups.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
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
            <section class="card bg-base-100 border border-base-300 min-w-0">
                <div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Basics') }}</h3>
                    <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$group->name ?? []" :required="content_locale_is_base()" />
                    <p class="text-xs opacity-60">{{ __('The heading over these characteristics on the product page and in the product form. Switch the content language at the top to write it in another.') }}</p>
                </div>
            </section>

            @if ($group->exists)
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Characteristics in this group') }} <span class="badge badge-sm badge-ghost">{{ $features->count() }}</span></h3>
                        @if ($features->isEmpty())
                            <p class="text-sm opacity-60">{{ __('None yet — a characteristic picks its group on its own form.') }}</p>
                        @else
                            <ul class="text-sm flex flex-col gap-1">
                                @foreach ($features as $feature)
                                    <li><a href="{{ route('admin.catalog.features.edit', $feature) }}" class="link link-hover">{{ $feature->t('name', $groupLocale) ?: $feature->code }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    </form>

    @if ($group->exists)
        {{-- Beside the form, never inside it: a nested form's inputs leak. --}}
        <form id="feature-group-delete" method="POST" action="{{ route('admin.catalog.feature-groups.destroy', $group) }}"
              data-confirm="{{ __('Delete this group? Its characteristics stay, without a group.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
