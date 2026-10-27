{{-- Where this menu stands: the blocks that chose it and the theme settings
     that name it. Nothing is drawn by a menu standing nowhere, which is worth
     saying before somebody edits it and wonders why the site did not change. --}}
<div class="card bg-base-100 border border-base-300">
    <div class="card-body py-4 gap-2">
        <h3 class="font-semibold">{{ __('Where it stands') }}</h3>
        @if ($menuPlaces === [])
            <div class="text-sm opacity-70">{{ __('Nowhere yet: no block shows this menu and no theme setting names it, so the site does not draw it.') }}</div>
        @else
            <ul class="flex flex-col gap-1 text-sm">
                @foreach ($menuPlaces as $menuPlace)
                    <li class="flex items-center gap-2 flex-wrap">
                        <x-icon :name="$menuPlace['kind'] === 'theme' ? 'palette' : 'layout-template'" size="sm" class="opacity-50 shrink-0" />
                        @if ($menuPlace['url'])
                            <a href="{{ $menuPlace['url'] }}" class="link link-hover">{{ $menuPlace['label'] }}</a>
                        @else
                            <span>{{ $menuPlace['label'] }}</span>
                        @endif
                        <span class="opacity-60">· {{ $menuPlace['where'] }}</span>
                        @if ($menuPlace['off'])
                            <span class="badge badge-ghost badge-sm">{{ __('off') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
