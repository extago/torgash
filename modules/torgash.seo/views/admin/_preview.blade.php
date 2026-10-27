{{-- The template being typed, printed on one real page it covers. Drawn as a
     search result, because that is where a title and a description are read. --}}
@php
    $titleLength = mb_strlen($rendered['title'] ?? '');
    $descriptionLength = mb_strlen($rendered['description'] ?? '');
@endphp
@if (! $example)
    <p class="text-sm opacity-60">{{ __('There is no page of this kind here yet to try the template on.') }}</p>
@elseif ($template->mode === 'skip')
    <p class="text-sm opacity-60">{{ __('This template leaves these pages alone: each one keeps what is typed on it, or what the shop works out.') }}</p>
@else
    <div class="flex items-center gap-2 text-xs">
        <span class="opacity-60 min-w-0 truncate">{{ __('On the page') }} <span class="font-medium opacity-100">{{ $exampleTitle }}</span></span>
        @if ($count > 1)
            <button type="button" class="btn btn-ghost btn-sm ml-auto gap-1 shrink-0"
                    hx-post="{{ route('admin.seo.preview') }}" hx-include="#seo-template-form" hx-params="not _method" hx-vals='@json(['example' => $index + 1])'
                    hx-target="#seo-preview" title="{{ __('Another page') }}">
                {{ $index + 1 }}/{{ $count }} <x-icon name="chevron-right" size="sm" />
            </button>
        @endif
    </div>

    <div class="rounded-box border border-base-300 bg-base-100 p-3 flex flex-col gap-1">
        @if ($exampleUrl)
            <div class="text-xs opacity-60 truncate">{{ $exampleUrl }}</div>
        @endif
        <div class="text-info font-medium leading-snug break-words">{{ $rendered['title'] !== '' ? $rendered['title'] : __('worked out by the shop') }}</div>
        <div class="text-sm opacity-80 leading-snug break-words">{{ $rendered['description'] !== '' ? $rendered['description'] : __('worked out by the shop') }}</div>
    </div>

    <div class="text-xs flex flex-col gap-1">
        <div class="flex justify-between gap-2">
            <span class="opacity-60">{{ __('Title length') }}</span>
            <span @class(['tabular-nums', 'text-error font-semibold' => $titleLength > 60])>{{ $titleLength }} / 60</span>
        </div>
        <div class="flex justify-between gap-2">
            <span class="opacity-60">{{ __('Description length') }}</span>
            <span @class(['tabular-nums', 'text-error font-semibold' => $descriptionLength > $descriptionLimit])>{{ $descriptionLength }} / {{ $descriptionLimit }}</span>
        </div>
        @if (($rendered['h1'] ?? '') !== '')
            <div class="pt-1"><span class="opacity-60">H1:</span> {{ $rendered['h1'] }}</div>
        @endif
    </div>
@endif
