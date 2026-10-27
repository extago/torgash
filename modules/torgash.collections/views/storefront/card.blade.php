{{-- One collection as a tile — the list's and the "Collections" block's.
     Named collectionCard*: an @include hands a partial every variable its caller has. --}}
<a href="{{ route('collections.show', $collectionCard->slug) }}"
   class="group relative block overflow-hidden rounded-box bg-base-200 aspect-[4/3]">
    @if ($collectionCardCover = $collectionCard->coverUrl())
        <img src="{{ $collectionCardCover }}" alt="" loading="lazy"
             class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
    @else
        <span class="absolute inset-0 grid place-items-center opacity-30"><x-icon name="layers" size="xl" /></span>
    @endif
    <span class="absolute inset-x-0 bottom-0 p-4 bg-gradient-to-t from-black/70 to-transparent text-white">
        <span class="block font-semibold text-lg leading-snug">{{ $collectionCard->t('name') }}</span>
    </span>
</a>
