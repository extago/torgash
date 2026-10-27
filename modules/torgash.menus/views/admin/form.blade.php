@extends('admin.layout')

@section('title', $menu->exists ? __('Edit menu') : __('New menu'))
@section('own_heading', '1')

@section('content')
    <form method="POST" action="{{ $menu->exists ? route('admin.menus.update', $menu) : route('admin.menus.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($menu->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.menus.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to menus') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $menu->exists ? __('Edit menu') : __('New menu') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $menu->exists ? ($menu->t('name', admin_content_locale()) ?: $menu->code) : __('New menu') }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($menu->exists)
                        <li><a href="{{ route('admin.menus.items', $menu) }}"><x-icon name="list-tree" /> {{ __('Menu items') }}</a></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="menu-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <a href="{{ route('admin.menus.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $menu->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Menu') }}</h3>
                        <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$menu->name ?? []" required />

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }} *</legend>
                            <input name="code" value="{{ old('code', $menu->code) }}" required
                                   class="input input-bordered w-full font-mono" placeholder="catalog">
                            <p class="label text-xs opacity-60 whitespace-normal">
                                {{ __('Lowercase letters, digits and dashes. Templates reference the menu by this — the header mega menu looks for "catalog".') }}
                            </p>
                        </fieldset>
                    </div>
                </section>

                @if ($menu->exists)
                    @include('torgash.menus::admin.partials.placements', ['menuPlaces' => $placements])
                @endif
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('enabled', $menu->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <p class="text-xs opacity-60">{{ __('A disabled menu draws nothing wherever it stands.') }}</p>
                    </div>
                </section>

                @if ($menu->exists)
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-2 text-sm">
                            <h3 class="font-semibold">{{ __('Menu items') }}</h3>
                            <div class="flex justify-between gap-2">
                                <span class="opacity-60">{{ __('Items in the menu') }}</span>
                                <span class="font-semibold tabular-nums">{{ $menu->items_count }}</span>
                            </div>
                            <a href="{{ route('admin.menus.items', $menu) }}" class="btn btn-ghost btn-sm gap-1.5 self-start -ml-2">
                                <x-icon name="list-tree" size="sm" /> {{ __('Open the items') }}
                            </a>
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </form>

    @if ($menu->exists)
        <form id="menu-delete" method="POST" action="{{ route('admin.menus.destroy', $menu) }}"
              data-confirm="{{ __('Delete :name?', ['name' => $menu->t('name', admin_content_locale()) ?: $menu->code]) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
