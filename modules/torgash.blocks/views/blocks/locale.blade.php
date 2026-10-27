{{-- The storefront's languages (links to this same page in each) and, when
     asked, its currencies (a POST each — a GET switcher is followed by the
     browser's prefetcher on its own). Drawn only when there is a choice. --}}
@php
    $localeStorefront = current_storefront();
    $localeLanguages = \App\Models\Language::whereIn('code', $localeStorefront?->locales ?? [])->where('enabled', true)->orderBy('sort')->get();
    $localeCurrencies = ($settings['currencies'] ?? true)
        ? \App\Models\Currency::whereIn('code', $localeStorefront?->currencies ?? [])->where('enabled', true)->orderBy('sort')->get()
        : collect();
    $localeActiveCurrency = session('currency', $localeStorefront?->default_currency);

    // Same page, other language: this request's own locale segment off, the
    // other one on (no prefix for the storefront's default).
    $localeSegments = array_values(array_filter(explode('/', request()->path()), fn ($s) => $s !== ''));
    if ($localeSegments && in_array($localeSegments[0], $localeStorefront?->locales ?? [], true)) {
        array_shift($localeSegments);
    }
    $localeQuery = (string) request()->server('QUERY_STRING');
@endphp
@if ($localeLanguages->count() > 1 || $localeCurrencies->count() > 1)
    <div class="torgash-block torgash-block-locale px-5 py-3 flex flex-col gap-3">
        @if ($localeLanguages->count() > 1)
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm opacity-60 mr-1">{{ __('Language') }}:</span>
                @foreach ($localeLanguages as $localeLanguage)
                    @php $localeUrl = url(($localeLanguage->code === $localeStorefront?->default_locale ? '' : $localeLanguage->code.'/').implode('/', $localeSegments)).($localeQuery ? '?'.$localeQuery : ''); @endphp
                    <a href="{{ $localeUrl }}" @class([
                        'btn btn-sm btn-touch min-w-12',
                        'btn-neutral' => app()->getLocale() === $localeLanguage->code,
                        'btn-outline border-base-300' => app()->getLocale() !== $localeLanguage->code,
                    ])>{{ strtoupper($localeLanguage->code) }}</a>
                @endforeach
            </div>
        @endif
        @if ($localeCurrencies->count() > 1)
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm opacity-60 mr-1">{{ __('Currency') }}:</span>
                @foreach ($localeCurrencies as $localeCurrency)
                    <form method="POST" action="{{ route('currency.set') }}">
                        @csrf
                        <input type="hidden" name="currency" value="{{ $localeCurrency->code }}">
                        <button @class([
                            'btn btn-sm btn-touch min-w-12',
                            'btn-neutral' => $localeActiveCurrency === $localeCurrency->code,
                            'btn-outline border-base-300' => $localeActiveCurrency !== $localeCurrency->code,
                        ])>{{ $localeCurrency->code }}</button>
                    </form>
                @endforeach
            </div>
        @endif
    </div>
@endif
