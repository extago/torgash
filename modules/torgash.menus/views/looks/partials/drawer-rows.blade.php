{{-- One level of the drawer: rows a thumb can hit (48px), a chevron into what is below. --}}
<ul>
    @foreach ($drawerRows as $drawerRow)
        @if ($drawerRow->isDivider())
            <li class="{{ $drawerRow->screenClass() }}"><hr class="my-1 border-base-300"></li>
            @continue
        @endif
        <li class="flex items-center {{ $drawerRow->screenClass() }} {{ $drawerRow->cssClass }}">
            @php $drawerTag = $drawerRow->href ? 'a' : 'span'; @endphp
            <{{ $drawerTag }} @if ($drawerRow->href) href="{{ $drawerRow->href }}"{!! $drawerRow->linkAttributes() !!} @endif
                @class(['flex-1 min-w-0 flex items-center gap-3 px-5 py-2.5 min-h-12', 'hover:bg-base-200' => $drawerRow->href, 'text-sm opacity-60' => ! $drawerRow->href, 'text-primary' => $drawerRow->highlight || $drawerRow->active])>
                @if ($menuShowIcons && $drawerRow->image)
                    <img src="{{ $drawerRow->image }}" alt="" class="w-6 h-6 shrink-0 object-contain" loading="lazy">
                @elseif ($menuShowIcons && $drawerRow->icon)
                    <x-icon :name="$drawerRow->icon" class="w-6 h-6 shrink-0 opacity-80" />
                @endif
                <span class="flex flex-col min-w-0">
                    <span @class(['truncate', 'font-medium' => $drawerRow->href])>{{ $drawerRow->label }}</span>
                    @if ($drawerRow->note)
                        <span class="text-xs opacity-60 text-base-content">{{ $drawerRow->note }}</span>
                    @endif
                </span>
                @if ($drawerRow->count !== null)
                    <span class="ml-auto text-xs opacity-50 tabular-nums">{{ $drawerRow->count }}</span>
                @endif
                @if ($drawerRow->counter)
                    <span class="ml-auto badge badge-primary badge-sm">{{ $drawerRow->counter }}</span>
                @endif
                @if ($menuShowBadges && $drawerRow->badge)
                    <span class="badge badge-{{ $drawerRow->badgeStyle }} badge-xs font-semibold">{{ $drawerRow->badge }}</span>
                @endif
            </{{ $drawerTag }}>
            @if (isset($drawerIds[$drawerRow]))
                <button type="button" x-on:click="path.push(@js($drawerIds[$drawerRow]))" class="btn btn-ghost btn-square btn-touch mr-2 opacity-60"
                        aria-label="{{ __('Open :category', ['category' => $drawerRow->label]) }}">
                    <x-icon name="chevron-right" />
                </button>
            @endif
        </li>
    @endforeach
</ul>
