{{-- The "Collections" block: tiles of the collections picked (in their
     order), or of every listed one. --}}
@php
    $collectionsPicked = array_map('intval', (array) ($settings['collections'] ?? []));
    $collectionsShown = \Torgash\Collections\Models\Collection::shown()
        ->when($collectionsPicked !== [], fn ($q) => $q->whereIn('id', $collectionsPicked), fn ($q) => $q->where('listed', true))
        ->orderBy('sort')->orderByDesc('id')
        ->limit(max(1, (int) ($settings['limit'] ?? 6)))
        ->get();
    if ($collectionsPicked !== []) {
        $collectionsShown = $collectionsShown->sortBy(fn ($c) => array_search($c->id, $collectionsPicked))->values();
    }
    $collectionsHeading = trim((string) ($settings['heading'] ?? ''));
@endphp
@if ($collectionsShown->isNotEmpty())
    <section class="torgash-block torgash-block-collections flex flex-col gap-4">
        @if ($collectionsHeading !== '')
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-xl font-semibold">{{ $collectionsHeading }}</h2>
                <a href="{{ route('collections.index') }}" class="link link-hover text-sm">{{ __('All collections') }}</a>
            </div>
        @endif
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($collectionsShown as $collectionCard)
                @include('torgash.collections::storefront.card', ['collectionCard' => $collectionCard])
            @endforeach
        </div>
    </section>
@endif
