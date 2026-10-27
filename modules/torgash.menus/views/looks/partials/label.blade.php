{{-- One entry's words: icon, name, count, badge. Shared by every look. --}}
@if ($menuShowIcons && $menuNode->image)
    <img src="{{ $menuNode->image }}" alt="" class="w-4 h-4 shrink-0 object-contain" loading="lazy">
@elseif ($menuShowIcons && $menuNode->icon)
    <x-icon :name="$menuNode->icon" size="sm" class="shrink-0 opacity-80" />
@endif
{{-- Cut short with an ellipsis where a look is one line (the header strip,
     a dropdown); wrapped where it is a column of words (the footer, a list),
     which sets $menuLabelWraps. --}}
<span @class(['min-w-0', 'truncate' => ! ($menuLabelWraps ?? false), 'break-words' => $menuLabelWraps ?? false])>{{ $menuNode->label }}</span>
@if ($menuNode->count !== null)
    <span class="text-xs opacity-50 tabular-nums">{{ $menuNode->count }}</span>
@endif
@if ($menuShowBadges && $menuNode->badge)
    <span class="badge badge-{{ $menuNode->badgeStyle }} badge-xs font-semibold whitespace-nowrap">{{ $menuNode->badge }}</span>
@endif
