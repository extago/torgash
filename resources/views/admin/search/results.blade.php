{{-- One list for both the header's dropdown and the search page. Plain links,
     so arrow keys, Enter and a middle click all work the way links do. --}}
@if ($query === '' || mb_strlen($query) < 2)
    <div class="px-4 py-3 text-sm opacity-60">{{ __('Type what you are looking for: a screen, a setting, a word from its description.') }}</div>
@elseif ($results === [])
    <div class="px-4 py-3 text-sm opacity-60">{{ __('Nothing found for “:query”.', ['query' => $query]) }}</div>
@else
    <ul class="menu menu-sm w-full p-1" data-admin-search-results data-query="{{ $query }}">
        @foreach ($results as $result)
            <li>
                <a href="{{ $result['url'] }}" class="flex items-start gap-3 py-2">
                    <x-icon :name="$result['icon']" class="mt-0.5 opacity-60 shrink-0" />
                    <span class="flex flex-col min-w-0">
                        <span class="font-medium">{{ $result['title'] }}</span>
                        @if ($result['context'] !== '')
                            <span class="text-xs opacity-50 truncate">{{ $result['context'] }}</span>
                        @endif
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
