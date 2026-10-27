{{-- The "Looks of a collection" block: the pictures with points, anywhere. --}}
@php
    $looksCollection = \Torgash\Collections\Models\Collection::shown()->find((int) ($settings['collection'] ?? 0));
    $looksShown = $looksCollection ? $looksCollection->looks()->limit(max(1, (int) ($settings['limit'] ?? 3)))->get() : collect();
    $looksHeading = trim((string) ($settings['heading'] ?? ''));
@endphp
@if ($looksShown->isNotEmpty())
    <section class="torgash-block torgash-block-looks flex flex-col gap-4">
        @if ($looksHeading !== '')
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-xl font-semibold">{{ $looksHeading }}</h2>
                <a href="{{ route('collections.show', $looksCollection->slug) }}" class="link link-hover text-sm">{{ __('The whole collection') }}</a>
            </div>
        @endif
        @include('torgash.collections::storefront.looks', ['looksShown' => $looksShown, 'looksClass' => ''])
    </section>
@endif
