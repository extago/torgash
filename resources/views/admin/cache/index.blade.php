@extends('admin.layout')

@section('title', __('Cache and sessions'))

{{--
    Where the cache and the sessions live (App\Http\Controllers\Admin\CacheController,
    App\Core\Performance\CacheSetup): chosen here, written to .env, tried first.
--}}
@section('content')
    @php
        $choices = [
            'file' => ['folder', __('A folder on this server'), __('Nothing to set up. Right for a shop on one server.')],
            'database' => ['database', __('The database'), __('Shared by every server that reaches the database — for a shop on several servers.')],
            'redis' => ['zap', __('Redis or Valkey'), __('The fastest: kept in memory by a server of its own — Redis or Valkey, they speak the same language. If it stops answering, the shop carries on with the database.')],
        ];
        $picked = [
            'cache' => old('cache', in_array($current['cache'], array_keys($choices), true) ? $current['cache'] : 'file'),
            'sessions' => old('sessions', in_array($current['sessions'], array_keys($choices), true) ? $current['sessions'] : 'file'),
        ];
        $when = fn (?int $ts) => $ts ? \Illuminate\Support\Carbon::createFromTimestamp($ts)->timezone(config('app.timezone'))->isoFormat('D MMM, HH:mm') : '';
    @endphp

    <div class="flex flex-col gap-4">
        @if (session('status'))
            <div class="alert alert-success text-sm"><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert alert-error text-sm"><x-icon name="circle-x" /><span>{{ session('error') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm"><x-icon name="circle-x" /><span>{{ $errors->first() }}</span></div>
        @endif
        @if ($outage && ! $outage['up_at'])
            <div class="alert alert-warning text-sm">
                <x-icon name="triangle-alert" />
                <span>{{ __('Redis is not answering since :time — the shop is working on the database cache. Start Redis or check its address.', ['time' => $when($outage['down_at'])]) }}
                    <span class="opacity-70 font-mono text-xs block mt-1">{{ $outage['error'] }}</span></span>
            </div>
        @endif

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_26rem] items-start">
        <form method="POST" action="{{ route('admin.cache.update') }}" class="flex flex-col gap-4 min-w-0"
              x-data="{ cache: @js($picked['cache']), sessions: @js($picked['sessions']), was: @js($current['sessions']) }">
            @csrf

            @foreach (['cache' => [__('Cache'), __('Answers the shop has already worked out once — menus, settings, counts — kept so the next page does not work them out again.')], 'sessions' => [__('Sessions'), __('Who is signed in, and a guest\'s basket and chosen town.')]] as $field => [$heading, $hint])
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div>
                            <h3 class="font-semibold">{{ $heading }}</h3>
                            <p class="text-sm opacity-60">{{ $hint }}</p>
                        </div>

                        <div class="grid sm:grid-cols-3 gap-2">
                            @foreach ($choices as $key => [$icon, $label, $explain])
                                <label class="flex gap-3 rounded-box border border-base-300 p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                    <input type="radio" name="{{ $field }}" value="{{ $key }}" class="radio radio-sm mt-0.5" x-model="{{ $field }}" @checked($picked[$field] === $key)>
                                    <span class="flex flex-col gap-0.5">
                                        <span class="font-medium flex items-center gap-1.5">
                                            <x-icon :name="$icon" size="sm" /> {{ $label }}
                                            @if ($current[$field] === $key)
                                                <span class="badge badge-sm badge-success">{{ __('now') }}</span>
                                            @endif
                                        </span>
                                        <span class="text-xs opacity-60">{{ $explain }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        @if (! in_array($current[$field], array_keys($choices), true))
                            <p class="text-xs opacity-70">{{ __('.env names something this screen does not offer: :name. Saving replaces it.', ['name' => $current[$field]]) }}</p>
                        @endif

                        @if ($probes)
                            @foreach ($probes as $probeChoice => $probe)
                                @if ($picked[$field] === $probeChoice)
                                    <div @class(['alert text-sm py-2', 'alert-success' => $probe['ok'], 'alert-error' => ! $probe['ok']])>
                                        <x-icon :name="$probe['ok'] ? 'circle-check' : 'circle-x'" />
                                        <span>
                                            {{ $probe['ok']
                                                ? __('Written, read back and deleted in :ms ms.', ['ms' => $probe['ms']])
                                                : __('Did not answer: :reason', ['reason' => $probe['error']]) }}
                                            @php
                                                $redisLine = empty($probe['version']) ? '' : '· '.$probe['version']
                                                    .(empty($probe['memory']) ? '' : ', '.__(':size in use', ['size' => $probe['memory']]));
                                            @endphp
                                            <span class="opacity-70">{{ $redisLine }}</span>
                                        </span>
                                    </div>
                                @endif
                            @endforeach
                        @endif
                    </div>
                </section>
            @endforeach

            <section class="card bg-base-100 border border-base-300" x-show="cache === 'redis' || sessions === 'redis'" x-cloak>
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Redis or Valkey connection') }}</h3>

                    @unless ($redisClient)
                        <div class="alert alert-warning text-sm"><x-icon name="triangle-alert" /><span>{{ __('PHP has no Redis client: the phpredis extension is not loaded.') }}</span></div>
                    @endunless

                    <div class="grid sm:grid-cols-[minmax(0,1fr)_8rem] gap-3">
                        <label class="flex flex-col gap-1">
                            <span class="text-sm">{{ __('Address') }}</span>
                            <input name="redis_host" class="input input-sm w-full font-mono" value="{{ old('redis_host', $redis['host']) }}" placeholder="127.0.0.1">
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-sm">{{ __('Port') }}</span>
                            <input name="redis_port" type="number" class="input input-sm w-full font-mono" value="{{ old('redis_port', $redis['port']) }}" placeholder="6379">
                        </label>
                    </div>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm">{{ __('Password') }}</span>
                        <input name="redis_password" type="password" autocomplete="new-password" class="input input-sm w-full"
                               placeholder="{{ $redis['has_password'] ? __('saved — leave blank to keep it') : __('none') }}">
                    </label>
                    @if ($redis['has_password'])
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="redis_clear_password" value="1" class="checkbox checkbox-sm"> {{ __('Remove the saved password') }}
                        </label>
                    @endif
                    <p class="text-xs opacity-60">{{ __('The cache uses Redis database 1 and sessions database 2, so clearing the cache never signs anybody out. Clearing empties database 1 as a whole — do not share it with another site.') }}</p>
                </div>
            </section>

            <div class="alert alert-warning text-sm" x-show="sessions !== was" x-cloak>
                <x-icon name="log-out" />
                <span>{{ __('Moving sessions signs every visitor out and empties guests\' baskets — only you stay signed in. Do it when the shop is quiet.') }}</span>
            </div>

            @if ($cached)
                <p class="text-xs opacity-70">{{ __('The configuration is cached; it is rebuilt on save.') }}</p>
            @endif

            @if ($lines)
                <pre class="p-3 rounded-box bg-base-200 text-xs overflow-x-auto">@foreach ($lines as $key => $value){{ $key }}={{ \App\Core\Support\EnvFile::quote($key === 'REDIS_PASSWORD' ? '…' : $value) }}
@endforeach</pre>
            @endif

            <div class="flex flex-wrap gap-2">
                <button name="action" value="save" class="btn btn-primary btn-sm">{{ __('Check and save') }}</button>
                <button name="action" value="check" class="btn btn-sm gap-1.5"><x-icon name="plug-zap" size="sm" /> {{ __('Only check') }}</button>
                @unless ($writable)
                    <span class="text-xs opacity-70 self-center">{{ __('The .env file cannot be written — you will be given the lines to put into it.') }}</span>
                @endunless
            </div>
        </form>

        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3 text-sm">
                    <h3 class="font-semibold">{{ __('Clear') }}</h3>
                    <div class="rounded-box bg-base-200/60 px-4 py-3">
                        <div class="text-xs opacity-60">{{ __('Entries in the cache') }}</div>
                        <div class="text-xl font-bold tabular-nums">{{ $entries === null ? '—' : number_format($entries, 0, ',', ' ') }}</div>
                    </div>
                    <form method="POST" action="{{ route('admin.cache.clear') }}" class="flex flex-col gap-2">
                        @csrf
                        <button name="what" value="cache" class="btn btn-sm justify-start gap-1.5"><x-icon name="eraser" size="sm" /> {{ __('Clear the cache') }}</button>
                        <p class="text-xs opacity-60">{{ __('Everything is worked out again on the next pages — they are slower for a minute. Nobody is signed out.') }}</p>
                        <button name="what" value="views" class="btn btn-sm justify-start gap-1.5"><x-icon name="file-code" size="sm" /> {{ __('Clear compiled templates') }}</button>
                        <p class="text-xs opacity-60">{{ __('For a template edited on the server that the site does not pick up.') }}</p>
                    </form>
                </div>
            </section>

            @include('admin.cache._pages', ['keptPages' => $pages, 'keptWhen' => $when])

            @if ($outage)
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-1 text-sm">
                        <h3 class="font-semibold">{{ __('Last Redis outage') }}</h3>
                        <p class="opacity-80">
                            {{ $outage['up_at']
                                ? __('From :from to :to the shop worked on the database cache.', ['from' => $when($outage['down_at']), 'to' => $when($outage['up_at'])])
                                : __('Since :from — still going.', ['from' => $when($outage['down_at'])]) }}
                        </p>
                        <p class="text-xs opacity-60 font-mono break-all">{{ $outage['error'] }}</p>
                    </div>
                </section>
            @endif
        </div>
    </div>
    </div>
@endsection
