@php $menuNode = $menuColumnLink; @endphp
@if ($menuNode->href)
    <a href="{{ $menuNode->href }}"{!! $menuNode->linkAttributes() !!} @class(['inline-flex items-start gap-2 hover:text-primary', 'text-primary font-semibold' => $menuNode->highlight || $menuNode->active])>@include('torgash.menus::looks.partials.label')</a>
@else
    <span class="inline-flex items-center gap-2 opacity-60">@include('torgash.menus::looks.partials.label')</span>
@endif
