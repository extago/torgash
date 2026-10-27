{{-- The heart. One form that TOGGLES, answered with itself in its new state
     (and the header counter out of band — toggled.blade.php); without
     JavaScript it posts and comes back to the page. wishLabelled draws it with
     words, for under the product page's buy button; wishCompact small, for a
     cart line. Variables are named wish*:
     an @include hands a partial every variable its caller has. --}}
@php $wishIn = app(\Torgash\Wishlist\Wishlists::class)->has($wishProduct); @endphp
<form method="POST" action="{{ route('wishlist.toggle', $wishProduct->slug) }}"
      hx-post="{{ route('wishlist.toggle', $wishProduct->slug) }}" hx-target="this" hx-swap="outerHTML"
      class="shrink-0" data-wishlist-toggle @if ($wishJust ?? false) data-just-toggled @endif>
    @csrf
    @if ($wishLabelled ?? false)
        <input type="hidden" name="labelled" value="1">
        <button class="btn btn-ghost btn-sm btn-touch gap-1.5 px-2 font-normal" aria-pressed="{{ $wishIn ? 'true' : 'false' }}">
            <span @class(['text-error [&_svg]:fill-current' => $wishIn])><x-icon name="heart" size="sm" /></span>
            {{ $wishIn ? __('In your wishlist') : __('Add to wishlist') }}
        </button>
    @else
        @if ($wishCompact ?? false)
            <input type="hidden" name="compact" value="1">
        @endif
        {{-- A card draws it in the photograph's corner, where app.css gives
             every .btn the frosted look and shows it on hover — or always,
             once it is pressed. --}}
        {{-- wishCompact: the small square one beside a cart line's bin. --}}
        <button @class(['btn btn-ghost btn-sm', 'btn-square btn-touch opacity-60 hover:opacity-100' => $wishCompact ?? false, 'btn-square btn-touch' => ! ($wishCompact ?? false)]) aria-pressed="{{ $wishIn ? 'true' : 'false' }}"
                title="{{ $wishIn ? __('Remove from wishlist') : __('Add to wishlist') }}"
                aria-label="{{ $wishIn ? __('Remove from wishlist') : __('Add to wishlist') }}: {{ $wishProduct->t('name') }}">
            <span @class(['text-error [&_svg]:fill-current' => $wishIn])><x-icon name="heart" :size="($wishCompact ?? false) ? 'sm' : 'lg'" /></span>
        </button>
    @endif
</form>
