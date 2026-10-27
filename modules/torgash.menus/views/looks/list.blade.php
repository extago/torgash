{{-- A plain list with its levels indented — a footer column, a sidebar. --}}
@php $menuLabelWraps = true; @endphp
<nav class="torgash-menu torgash-menu-list" @if ($menuHeading !== '') aria-label="{{ $menuHeading }}" @endif>
    @if ($menuHeading !== '')
        <div class="font-semibold mb-2 flex items-center gap-2">
            @if ($menuHeadingIcon)
                <x-icon :name="$menuHeadingIcon" size="sm" />
            @endif
            {{ $menuHeading }}
        </div>
    @endif
    <ul class="menu menu-sm p-0 w-full">
        @include('torgash.menus::looks.partials.tree', ['menuTreeNodes' => $menuNodes, 'menuTreeLevel' => 1])
    </ul>
</nav>
