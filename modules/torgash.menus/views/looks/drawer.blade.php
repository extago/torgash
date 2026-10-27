{{--
    The phone's drawer (PLAN §39.6): the top entries — icon, name, a chevron
    where there is more — and a panel per entry with children that slides over
    the list, with "back", "Everything in X" and the next level. As deep as the
    block says (up to three levels); the chevron is a button, so every name is
    still a link and without a script the list is simply the top level.
    Panels are numbered here rather than named by entry: one category can
    stand in a menu twice.
--}}
@php
    $drawerPanels = [];
    $drawerIds = new \SplObjectStorage;
    $drawerWalk = function (array $nodes, int $level) use (&$drawerWalk, &$drawerPanels, $drawerIds, $menuDepth) {
        foreach ($nodes as $node) {
            if ($node->hasChildren() && $level < $menuDepth) {
                $drawerIds[$node] = 'p'.count($drawerPanels);
                $drawerPanels[] = $node;
                $drawerWalk($node->children, $level + 1);
            }
        }
    };
    $drawerWalk($menuNodes, 1);
@endphp
<nav class="torgash-menu torgash-menu-drawer relative overflow-hidden py-2" x-data="{ path: [] }" @if ($menuHeading !== '') aria-label="{{ $menuHeading }}" @endif>
    <div x-show="path.length === 0" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-4 opacity-0">
        @if ($menuHeading !== '')
            <div class="px-5 pt-2 pb-1 text-sm opacity-60">{{ $menuHeading }}</div>
        @endif
        @include('torgash.menus::looks.partials.drawer-rows', ['drawerRows' => $menuNodes])
    </div>

    @foreach ($drawerPanels as $drawerPanel)
        <div x-show="path[path.length - 1] === @js($drawerIds[$drawerPanel])" x-cloak
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-4 opacity-0">
            <button type="button" x-on:click="path.pop()" class="flex items-center gap-2 px-5 py-3 min-h-12 w-full text-left font-semibold hover:bg-base-200">
                <x-icon name="chevron-left" /> {{ $drawerPanel->label }}
            </button>
            @if ($drawerPanel->href && ! collect($drawerPanel->children)->contains(fn ($c) => $c->kind === 'all'))
                <a href="{{ $drawerPanel->href }}" class="flex items-center px-5 py-2.5 min-h-12 text-primary font-medium hover:bg-base-200">
                    {{ __('Everything in :category', ['category' => $drawerPanel->label]) }}
                </a>
            @endif
            @include('torgash.menus::looks.partials.drawer-rows', ['drawerRows' => $drawerPanel->children])
        </div>
    @endforeach
</nav>
