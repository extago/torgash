{{--
    A row of links (the header's top strip). An entry with children opens a
    drop-down under the pointer or on keyboard focus — daisyUI's hover
    dropdown, so no script. The panel carries torgash-bar-panel: on a dark bar
    it goes back to the page's palette, on a light one it changes nothing.
    The row never wraps; what does not fit is cut, which is the prompt to
    move an entry into a drop-down.
--}}
<nav class="torgash-menu torgash-menu-inline flex items-center gap-5 min-w-0" @if ($menuHeading !== '') aria-label="{{ $menuHeading }}" @endif>
    @foreach ($menuNodes as $menuNode)
        @continue($menuNode->isDivider())
        @if ($menuNode->hasChildren() && $menuDepth > 1)
            <div class="dropdown dropdown-hover {{ $menuNode->screenClass() }} {{ $menuNode->cssClass }}">
                @if ($menuNode->href)
                    <a href="{{ $menuNode->href }}"{!! $menuNode->linkAttributes() !!} tabindex="0"
                       @class(['flex items-center gap-1.5 whitespace-nowrap hover:text-primary', 'text-primary font-semibold' => $menuNode->highlight || $menuNode->active || $menuNode->trail])>
                        @include('torgash.menus::looks.partials.label')
                        <x-icon name="chevron-down" size="sm" class="opacity-60" />
                    </a>
                @else
                    <span tabindex="0" role="button" @class(['flex items-center gap-1.5 whitespace-nowrap hover:text-primary cursor-pointer', 'text-primary font-semibold' => $menuNode->highlight || $menuNode->trail])>
                        @include('torgash.menus::looks.partials.label')
                        <x-icon name="chevron-down" size="sm" class="opacity-60" />
                    </span>
                @endif
                <ul tabindex="0" class="torgash-bar-panel dropdown-content menu menu-sm bg-base-100 text-base-content rounded-box shadow-lg border border-base-300 w-60 p-2 z-50">
                    @include('torgash.menus::looks.partials.tree', ['menuTreeNodes' => $menuNode->children, 'menuTreeLevel' => 2])
                </ul>
            </div>
        @elseif ($menuNode->href)
            <a href="{{ $menuNode->href }}"{!! $menuNode->linkAttributes() !!}
               @class(['flex items-center gap-1.5 whitespace-nowrap hover:text-primary', $menuNode->screenClass(), $menuNode->cssClass, 'text-primary font-semibold' => $menuNode->highlight || $menuNode->active])>
                @include('torgash.menus::looks.partials.label')
            </a>
        @else
            <span @class(['flex items-center gap-1.5 whitespace-nowrap opacity-70', $menuNode->screenClass(), $menuNode->cssClass])>@include('torgash.menus::looks.partials.label')</span>
        @endif
    @endforeach
</nav>
