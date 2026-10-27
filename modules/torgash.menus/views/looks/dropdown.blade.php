{{--
    One button — the block's heading, or "Menu" — with the whole menu under
    it. Opens on click (a hover-only drop-down cannot be opened on a touch
    screen), nested entries as daisyUI's own nested menu.
--}}
{{-- A popover (core's component), like every small menu off a button. --}}
<div class="torgash-menu torgash-menu-dropdown">
    <x-popover class="btn btn-ghost btn-sm btn-touch gap-1.5 normal-case font-normal" panel-class="w-64 p-2">
        <ul class="menu menu-sm w-full p-0">
            @include('torgash.menus::looks.partials.tree', ['menuTreeNodes' => $menuNodes, 'menuTreeLevel' => 1])
        </ul>

        <x-slot:trigger>
            @if ($menuHeadingIcon)
                <x-icon :name="$menuHeadingIcon" size="sm" />
            @endif
            {{ $menuHeading !== '' ? $menuHeading : __('Menu') }}
            <x-icon name="chevron-down" size="sm" class="opacity-60" />
        </x-slot:trigger>
    </x-popover>
</div>
