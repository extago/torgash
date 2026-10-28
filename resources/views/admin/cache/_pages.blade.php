{{--
    Pages kept ready for guests (PLAN §61, App\Core\PageCache\PageCache):
    how many there are, the last time all of them were thrown away and why,
    and why the latest pages were not kept — the reasons are the engine's
    own words, for whoever looks into a module that forgot to declare
    something. Named kept* — an @include hands a partial everything its
    caller has.
--}}
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3 text-sm">
        <div class="flex items-center gap-2">
            <h3 class="font-semibold grow">{{ __('Pages kept for guests') }}</h3>
            <span @class(['badge badge-sm', 'badge-success' => $keptPages['enabled'], 'badge-ghost' => ! $keptPages['enabled']])>
                {{ $keptPages['enabled'] ? __('on') : __('off') }}
            </span>
        </div>

        @if (! $keptPages['enabled'])
            <p class="opacity-70">{{ __('Every page is drawn afresh for every visitor.') }}</p>
        @elseif ($keptPages['stats'])
            <div class="grid grid-cols-2 gap-2">
                <div class="rounded-box bg-base-200/60 px-4 py-3">
                    <div class="text-xs opacity-60">{{ __('Pages kept') }}</div>
                    <div class="text-xl font-bold tabular-nums">{{ number_format($keptPages['stats']['entries'], 0, ',', ' ') }}</div>
                </div>
                <div class="rounded-box bg-base-200/60 px-4 py-3">
                    <div class="text-xs opacity-60">{{ __('Space taken') }}</div>
                    <div class="text-xl font-bold tabular-nums">{{ \Illuminate\Support\Number::fileSize($keptPages['stats']['bytes'], 1) }}</div>
                </div>
            </div>
        @endif

        @if ($keptPages['flush'])
            <p class="text-xs opacity-70">
                {{ __('All thrown away :time', ['time' => $keptWhen($keptPages['flush']['at'])]) }}:
                <span class="font-mono break-words">{{ $keptPages['flush']['why'] }}</span>
            </p>
        @endif

        @if ($keptPages['enabled'] && $keptPages['refusals'] !== [])
            <details class="text-xs">
                <summary class="cursor-pointer opacity-80">{{ __('Why pages were not kept') }} ({{ count($keptPages['refusals']) }})</summary>
                <ul class="flex flex-col gap-2 mt-2">
                    @foreach ($keptPages['refusals'] as $keptRefusal)
                        <li class="flex flex-col gap-0.5">
                            <span class="opacity-60">{{ $keptWhen($keptRefusal['at']) }} · {{ label_text($keptPages['kinds'][$keptRefusal['kind']] ?? $keptRefusal['kind']) }}</span>
                            <a href="{{ $keptRefusal['url'] }}" class="link link-hover break-all" target="_blank" rel="noopener">{{ $keptRefusal['url'] }}</a>
                            <span class="font-mono break-words">{{ $keptRefusal['reason'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        <form method="POST" action="{{ route('admin.cache.clear') }}" class="flex flex-col gap-2">
            @csrf
            @if ($keptPages['enabled'])
                <button name="what" value="pages" class="btn btn-sm justify-start gap-1.5"><x-icon name="files" size="sm" /> {{ __('Throw the kept pages away') }}</button>
                <p class="text-xs opacity-60">{{ __('Not needed after a change in the admin — that throws them away by itself. For a template or a file changed on the server.') }}</p>
            @endif
            @can('settings.manage')
                <a href="{{ route('admin.settings.edit', 'core') }}#setting-{{ \App\Core\PageCache\PageCache::ENABLED }}" class="link link-hover text-xs">{{ __('Settings of the page cache') }}</a>
            @endcan
        </form>
    </div>
</section>
