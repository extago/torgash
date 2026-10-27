@extends('admin.layout')

@section('title', $platform->exists ? __('Edit site') : __('Add a site'))
@section('own_heading', '1')

@section('content')
    <form method="POST" class="flex flex-col gap-4"
          action="{{ $platform->exists ? route('admin.shopreviews.platforms.update', $platform) : route('admin.shopreviews.platforms.store') }}">
        @csrf
        @if ($platform->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.shopreviews.platforms.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('The shop\'s rating on other sites') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $platform->exists ? $platform->name : __('Add a site') }}</div>
            </div>
            @if ($platform->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="platform-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.shopreviews.platforms.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $platform->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Site') }}</legend>
                        <input name="name" value="{{ old('name', $platform->name) }}" required maxlength="80" class="input input-bordered w-full" placeholder="Google">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Link to the shop\'s page there') }}</legend>
                        <input type="url" name="url" value="{{ old('url', $platform->url) }}" maxlength="500" class="input input-bordered w-full" placeholder="https://">
                    </fieldset>
                    <div class="grid gap-3 md:grid-cols-2">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Rating') }}</legend>
                            <input type="number" name="rating" step="0.1" min="0" max="5" value="{{ old('rating', $platform->rating) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Out of 5, as the site shows it.') }}</p>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Reviews there') }}</legend>
                            <input type="number" name="reviews_count" min="0" value="{{ old('reviews_count', $platform->reviews_count) }}" class="input input-bordered w-full">
                        </fieldset>
                    </div>
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Status') }}</h3>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $platform->enabled ?? true))>
                        <span class="label-text">{{ __('Shown on the reviews page') }}</span>
                    </label>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                        <input type="number" name="sort" min="0" value="{{ old('sort', $platform->sort ?? 0) }}" class="input input-bordered w-full">
                    </fieldset>
                </div>
            </section>
        </div>
    </form>

    @if ($platform->exists)
        <form method="POST" id="platform-delete" action="{{ route('admin.shopreviews.platforms.destroy', $platform) }}" class="hidden"
              data-confirm="{{ __('Delete this site?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
