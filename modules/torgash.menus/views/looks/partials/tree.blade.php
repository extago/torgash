{{--
    Entries as nested lists — the body of the drop-downs and the list look.
    $menuTreeNodes, $menuTreeLevel (1-based) and $menuDepth come from the caller.
--}}
@foreach ($menuTreeNodes as $menuNode)
    @if ($menuNode->isDivider())
        <li class="{{ $menuNode->screenClass() }}"><hr class="my-1 border-base-300 p-0"></li>
        @continue
    @endif
    <li class="{{ $menuNode->screenClass() }} {{ $menuNode->cssClass }}">
        @if ($menuNode->href)
            <a href="{{ $menuNode->href }}"{!! $menuNode->linkAttributes() !!}
               @class(['flex items-center gap-2', 'text-primary font-semibold' => $menuNode->highlight || $menuNode->active, 'font-medium' => $menuNode->trail])>
                @include('torgash.menus::looks.partials.label')
            </a>
        @else
            <span class="menu-title flex items-center gap-2">@include('torgash.menus::looks.partials.label')</span>
        @endif
        @if ($menuNode->hasChildren() && $menuTreeLevel < $menuDepth)
            <ul>
                @include('torgash.menus::looks.partials.tree', ['menuTreeNodes' => $menuNode->children, 'menuTreeLevel' => $menuTreeLevel + 1])
            </ul>
        @endif
    </li>
@endforeach
