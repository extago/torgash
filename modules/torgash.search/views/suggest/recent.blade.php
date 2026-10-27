{{-- What the window shows before enough is typed: the visitor's own recent
     searches, then what others search for most (Stats::popular — only
     queries that find something). Nothing at all when there is neither — an
     empty panel with a heading is noise. --}}
@php $popular = $popular ?? []; @endphp
@if ($recent !== [] || $popular !== [])
    <div class="flex flex-col min-h-0 overflow-y-auto text-sm">
        @if ($recent !== [])
            <div class="flex items-center justify-between px-3 pt-2 pb-1">
                <span class="text-xs uppercase tracking-wide opacity-50">{{ __('You searched for') }}</span>
                <button type="button" class="link link-hover text-xs opacity-60" data-live-search-forget>{{ __('Clear') }}</button>
            </div>
            <ul class="flex flex-col pb-1">
                @foreach ($recent as $query)
                    <li>
                        <a href="{{ route('catalog.search', ['q' => $query]) }}" role="option" class="flex items-center gap-2 px-3 py-1.5 hover:bg-base-200 aria-selected:bg-base-200">
                            <x-icon name="history" size="sm" class="opacity-40" /> {{ $query }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($popular !== [])
            <div class="px-3 pt-2 pb-1 text-xs uppercase tracking-wide opacity-50">{{ __('Popular searches') }}</div>
            <ul class="flex flex-col pb-1">
                @foreach ($popular as $popularQuery)
                    <li>
                        <a href="{{ route('catalog.search', ['q' => $popularQuery]) }}" role="option" class="flex items-center gap-2 px-3 py-1.5 hover:bg-base-200 aria-selected:bg-base-200">
                            <x-icon name="trending-up" size="sm" class="opacity-40" /> {{ $popularQuery }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
