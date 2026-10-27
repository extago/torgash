{{-- The answer to a toggle: the heart in its new state, the header counter
     out of band and, when it was an ADD, the corner notice (the core stack).
     Taking a product out says nothing: the heart that just emptied says it. --}}
@include('torgash.wishlist::button', ['wishProduct' => $wishProduct, 'wishLabelled' => $wishLabelled, 'wishCompact' => $wishCompact ?? false])
@include('torgash.wishlist::counter', ['wishCounterOob' => true])
@if (setting('torgash.wishlist', 'notice_on_add') && app(\Torgash\Wishlist\Wishlists::class)->has($wishProduct))
    <x-notice :title="__('Added to your wishlist')" :text="$wishProduct->t('name')"
              :image="$wishProduct->primaryImageUrl(160)" icon="heart" icon-class="text-error fill-current"
              :href="route('wishlist.index')" :action="__('Open the wishlist')" />
@endif
