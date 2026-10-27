{{-- One entry on the mega menu's right side: a link, or a heading when it has no address. --}}
@if ($megaLink->href)
    <a href="{{ $megaLink->href }}" @if ($megaLink->newTab) target="_blank" rel="noopener" @endif
       @class(['flex items-center gap-2 rounded-lg py-1 text-sm hover:text-primary', $megaLinkClass, $megaLink->cssClass, 'text-primary font-semibold' => $megaLink->highlight])>
@else
    <span @class(['flex items-center gap-2 py-1 text-sm', $megaLinkClass, $megaLink->cssClass])>
@endif
    @if ($megaLink->image ?? null)
        <img src="{{ $megaLink->image }}" alt="" class="w-4 h-4 shrink-0 object-contain" loading="lazy">
    @elseif ($megaLink->icon)
        <x-icon :name="$megaLink->icon" size="sm" class="shrink-0 opacity-70" />
    @endif
    <span class="min-w-0">{{ $megaLink->label }}</span>
    @if ($megaLink->count !== null)
        <span class="text-xs opacity-50 tabular-nums">{{ $megaLink->count }}</span>
    @endif
    @if ($megaLink->badge)
        <span class="badge badge-{{ $megaLink->badgeStyle }} badge-xs font-semibold">{{ $megaLink->badge }}</span>
    @endif
@if ($megaLink->href)
    </a>
@else
    </span>
@endif
