{{--
    Rows with an icon and a small line under the name — the drawer's list of
    shop links (Sale, Brands, Wishlist…), one level. A shortcut's live number
    (the wishlist's count) sits at the end of its row.
--}}
<nav class="torgash-menu torgash-menu-rows py-2" @if ($menuHeading !== '') aria-label="{{ $menuHeading }}" @endif>
    @if ($menuHeading !== '')
        <div class="px-5 pt-2 pb-1 text-sm opacity-60">{{ $menuHeading }}</div>
    @endif
    @include('torgash.menus::looks.partials.drawer-rows', ['drawerRows' => $menuNodes, 'drawerIds' => new \SplObjectStorage])
</nav>
