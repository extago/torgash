@extends('admin.layout')

@section('title', $criterion->exists ? __('Edit criterion') : __('New criterion'))
@section('own_heading', '1')

@section('content')
    <form method="POST" class="flex flex-col gap-4"
          action="{{ $criterion->exists ? route('admin.shopreviews.criteria.update', $criterion) : route('admin.shopreviews.criteria.store') }}">
        @csrf
        @if ($criterion->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.shopreviews.criteria.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to criteria') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $criterion->exists ? __('Edit criterion') : __('New criterion') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $criterion->exists ? $criterion->label() : __('New criterion') }}</div>
            </div>
            @if ($criterion->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="criterion-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.shopreviews.criteria.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $criterion->exists ? __('Save') : __('Create') }}</button>
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
                    <h3 class="font-semibold">{{ __('Basics') }}</h3>
                    <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$criterion->name ?? []" required />
                    <fieldset class="fieldset p-0 max-w-xs"><legend class="fieldset-legend">{{ __('Code') }}</legend>
                        <input name="code" value="{{ old('code', $criterion->code) }}" class="input input-bordered w-full font-mono" placeholder="{{ __('from the name') }}">
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('Latin letters, digits and underscores; made from the name when empty.') }}</p>
                    </fieldset>
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Status') }}</h3>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $criterion->enabled ?? true))>
                        <span class="label-text">{{ __('Asked') }}</span>
                    </label>
                    <p class="text-xs opacity-60">{{ __('Switched off, it is not asked any more; scores already given stay in the reviews and the averages.') }}</p>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                        <input type="number" name="sort" min="0" value="{{ old('sort', $criterion->sort ?? 0) }}" class="input input-bordered w-full">
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('The order of the questions in the review form.') }}</p>
                    </fieldset>
                </div>
            </section>
        </div>
    </form>

    @if ($criterion->exists)
        <form method="POST" id="criterion-delete" action="{{ route('admin.shopreviews.criteria.destroy', $criterion) }}" class="hidden"
              data-confirm="{{ __('Delete this criterion? Scores already given keep their numbers.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
