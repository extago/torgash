@extends('admin.layout')

@section('title', __('Delete the places of :country', ['country' => $code]))

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-3 mb-4">
            <a href="{{ route('admin.locations.countries') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to countries') }}" aria-label="{{ __('Back to countries') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <h1 class="text-2xl font-semibold">
                {{ __('Delete the places of :country', ['country' => $country?->t('name', admin_content_locale()) ?? $code]) }}
            </h1>
        </div>

        @if ($errors->any())
            <div class="alert alert-error mb-4 text-sm">{{ $errors->first() }}</div>
        @endif

        @if ($counts['places'] === 0 && $counts['states'] === 0)
            <div class="alert alert-info">
                {{ __('There is nothing stored for this country.') }}
            </div>
        @else
            <div class="card bg-base-100 border border-error/40">
                <div class="card-body gap-4">
                    <div class="flex items-start gap-3">
                        <x-icon name="circle-alert" size="lg" class="text-error shrink-0 mt-0.5" />
                        <div>
                            <div class="font-semibold">{{ __('This cannot be undone.') }}</div>
                            <div class="text-sm opacity-70">
                                {{ __('The country itself stays in the list — only what is stored under it goes.') }}
                            </div>
                        </div>
                    </div>

                    <ul class="text-sm space-y-1">
                        <li>· {{ __(':count places', ['count' => number_format($counts['places'], 0, '.', ' ')]) }}</li>
                        <li>· {{ __(':count regions', ['count' => number_format($counts['states'], 0, '.', ' ')]) }}</li>
                    </ul>

                    @if ($counts['manual'] > 0 || $counts['edited'] > 0)
                        {{-- The part that re-importing cannot bring back, said
                             plainly and separately from the rest. --}}
                        <div class="alert alert-warning text-sm">
                            <div>
                                <div class="font-semibold">{{ __('Re-importing the pack will not bring all of this back.') }}</div>
                                @if ($counts['manual'] > 0)
                                    <div>{{ __(':count places were added by hand and exist nowhere else.', ['count' => $counts['manual']]) }}</div>
                                @endif
                                @if ($counts['edited'] > 0)
                                    <div>{{ __(':count places carry edits of yours that the pack does not have.', ['count' => $counts['edited']]) }}</div>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="text-sm opacity-70">
                            {{ __('Nothing here was edited or added by hand, so importing the pack again restores it.') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.locations.countries.purge') }}" class="flex flex-col gap-3">
                        @csrf
                        <input type="hidden" name="code" value="{{ $code }}">

                        <fieldset class="fieldset p-0 max-w-xs">
                            <legend class="fieldset-legend">
                                {{ __('Type :code to confirm', ['code' => $code]) }}
                            </legend>
                            <input name="confirm_code" class="input input-bordered w-full uppercase font-mono"
                                   autocomplete="off" required>
                        </fieldset>

                        <div class="flex gap-2">
                            <button class="btn btn-error btn-sm">
                                {{ __('Delete :count places', ['count' => number_format($counts['places'], 0, '.', ' ')]) }}
                            </button>
                            <a href="{{ route('admin.locations.countries') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
