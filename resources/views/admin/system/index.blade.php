@extends('admin.layout')

@section('title', __('System'))

{{--
    What the server is (PLAN §45), read-only: versions, limits, the database
    and its locale, the disk, whether background work moves, and the
    libraries' known vulnerabilities. Problems first, as sentences.

    Laid out as the dashboard is (docs/architecture/admin.md): a row of cards
    only where they are of one kind and one height — three short lists of
    facts. Everything else stands in two columns, each a stack: blocks of
    different heights leave holes in a grid, and what grows (the release
    notes, the advisories, the tables) belongs in the wide one.
--}}
@section('content')
    @php
        $mb = fn (?int $bytes) => $bytes === null ? '—' : ($bytes >= 1073741824
            ? number_format($bytes / 1073741824, 1, ',', ' ').' GB'
            : number_format($bytes / 1048576, 1, ',', ' ').' MB');
        $ago = function (?int $seconds) {
            if ($seconds === null) return __('never');
            if ($seconds < 120) return __(':n s ago', ['n' => $seconds]);
            if ($seconds < 7200) return __(':n min ago', ['n' => intdiv($seconds, 60)]);
            return __(':n h ago', ['n' => intdiv($seconds, 3600)]);
        };
        $ok = fn (bool $good) => $good ? 'text-success' : 'text-error';
    @endphp

    <div class="flex flex-col gap-4">
        @include('admin.system._tabs')

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-error text-sm">{{ session('error') }}</div>
        @endif

        @if ($warnings)
            <div class="alert alert-warning text-sm">
                <div class="flex flex-col gap-1.5">
                    @foreach ($warnings as $warning)
                        <div class="flex gap-2"><x-icon name="triangle-alert" size="sm" class="mt-0.5 shrink-0" /> <span>{{ $warning }}</span></div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="alert alert-success alert-soft text-sm"><x-icon name="circle-check" size="sm" /> {{ __('Nothing needs attention.') }}</div>
        @endif

        {{-- PLAN §55: the demo shop stays in view until it is gone — removal
             takes exactly what the pack made, the owner's own records stay. --}}
        @if ($demo['records'] > 0)
            <section id="demo" class="scroll-mt-20 card bg-base-100 border border-warning">
                <div class="card-body gap-3 sm:flex-row sm:items-center">
                    <div class="flex-1 flex flex-col gap-1">
                        <h3 class="font-semibold flex items-center gap-2"><x-icon name="flask-conical" size="sm" /> {{ __('Demo data') }}</h3>
                        <p class="text-sm opacity-70">{{ __('The shop holds the demo data (:count records): products, categories, brands and the rest marked Demo. Remove it before you open the shop — only what the demo created goes, everything you added stays.', ['count' => $demo['records']]) }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.system.demo.remove') }}"
                          data-confirm="{{ __('Remove the demo data? Its products, categories, brands and pictures are deleted for good.') }}">
                        @csrf
                        <button class="btn btn-sm btn-error btn-outline gap-2" @disabled($demo['running'])>
                            @if ($demo['running']) <span class="loading loading-spinner loading-xs"></span> @else <x-icon name="trash-2" size="sm" /> @endif
                            {{ __('Remove the demo data') }}
                        </button>
                    </form>
                </div>
            </section>
        @endif

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold flex items-center gap-2"><x-icon name="package" size="sm" /> {{ __('Versions') }}</h3>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                        <dt class="opacity-60">Torgash</dt><dd class="font-medium">{{ $engine['engine'] }}</dd>
                        <dt class="opacity-60">Laravel</dt><dd>{{ $engine['laravel'] }}</dd>
                        <dt class="opacity-60">PHP</dt><dd>{{ $engine['php'] }} <span class="opacity-50">({{ $engine['sapi'] }}, {{ $engine['os'] }})</span></dd>
                        <dt class="opacity-60">PostgreSQL</dt><dd>{{ $database['version'] ?? '—' }}</dd>
                        <dt class="opacity-60">{{ __('Environment') }}</dt>
                        <dd>{{ $engine['env'] }} @if ($engine['debug']) <span class="badge badge-warning badge-sm ml-1">debug</span> @endif</dd>
                        <dt class="opacity-60">{{ __('Time zone') }}</dt><dd>{{ $engine['timezone'] }}</dd>
                    </dl>
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold flex items-center gap-2"><x-icon name="activity" size="sm" /> {{ __('Background work') }}</h3>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                        <dt class="opacity-60">{{ __('Scheduler') }}</dt>
                        <dd class="{{ $ok($background['scheduler_seconds'] !== null && $background['scheduler_seconds'] <= 300) }}">{{ $ago($background['scheduler_seconds']) }}</dd>
                        <dt class="opacity-60">{{ __('Queue') }}</dt>
                        <dd>{{ $background['queue'] }}@if ($background['waiting'] !== null) · {{ __(':n waiting', ['n' => $background['waiting']]) }}@endif</dd>
                        @if ($background['oldest_seconds'])
                            <dt class="opacity-60">{{ __('Oldest waiting') }}</dt>
                            <dd class="{{ $ok($background['oldest_seconds'] <= 600) }}">{{ $ago($background['oldest_seconds']) }}</dd>
                        @endif
                        <dt class="opacity-60">{{ __('Failed jobs') }}</dt>
                        <dd class="{{ $ok(! $background['failed']) }}">
                            {{ $background['failed'] ?? '—' }}
                            {{-- Their class is gone (a module removed): they can never be retried. --}}
                            @if ($background['failed_missing'])
                                <span class="opacity-70">· {{ __(':n without code', ['n' => $background['failed_missing']]) }}</span>
                                <button form="system-jobs-prune" class="link text-sm">{{ __('Remove') }}</button>
                            @endif
                        </dd>
                        <dt class="opacity-60">{{ __('Cache') }}</dt><dd>{{ $background['cache'] }} · {{ __('sessions') }}: {{ $background['session'] }}</dd>
                        <dt class="opacity-60">{{ __('Mail') }}</dt><dd>{{ $background['mail'] }}</dd>
                    </dl>
                    <div class="flex flex-wrap gap-2 text-sm mt-auto">
                        @can('tasks.manage') <a href="{{ route('admin.tasks') }}" class="link">{{ __('Background tasks') }}</a> @endcan
                        @can('cache.manage') <a href="{{ route('admin.cache') }}" class="link">{{ __('Cache and sessions') }}</a> @endcan
                    </div>
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold flex items-center gap-2"><x-icon name="cpu" size="sm" /> PHP</h3>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                        <dt class="opacity-60">{{ __('Memory') }}</dt><dd>{{ $limits['memory_limit'] }}</dd>
                        <dt class="opacity-60">{{ __('Largest upload') }}</dt><dd>{{ $limits['upload_max_filesize'] }} <span class="opacity-50">/ {{ __('form') }} {{ $limits['post_max_size'] }}</span></dd>
                        <dt class="opacity-60">{{ __('Time per request') }}</dt><dd>{{ $limits['max_execution_time'] === '0' ? __('unlimited') : $limits['max_execution_time'].' s' }}</dd>
                        <dt class="opacity-60">max_input_vars</dt><dd>{{ $limits['max_input_vars'] }}</dd>
                    </dl>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($extensions['required'] as $ext => $has)
                            <span @class(['badge badge-sm', 'badge-ghost' => $has, 'badge-error' => ! $has])>{{ $ext }}</span>
                        @endforeach
                        @foreach ($extensions['optional'] as $ext => $has)
                            <span @class(['badge badge-sm badge-ghost', 'opacity-40 line-through' => ! $has])>{{ $ext }}</span>
                        @endforeach
                    </div>
                    <p class="text-xs opacity-60 mt-auto">{{ __('As the web server runs PHP; the console may have other limits.') }}</p>
                </div>
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_26rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                {{-- PLAN §45.8: what the update server offers. Installing runs in the
                     background — backup, maintenance, swap, migrations — and puts
                     the previous version back by itself when anything fails. --}}
                <section id="updates" class="scroll-mt-20 card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-semibold flex items-center gap-2"><x-icon name="refresh-cw" size="sm" /> {{ __('Updates') }}</h3>
                            <form method="POST" action="{{ route('admin.system.updates.check') }}">
                                @csrf
                                <button class="btn btn-sm" @disabled($updates['running'])>{{ __('Check for updates') }}</button>
                            </form>
                        </div>

                        @if ($updates['offer'])
                            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                <div>
                                    <div class="font-medium">Torgash {{ $updates['offer']['version'] }}</div>
                                    <div class="text-xs opacity-60">{{ __('Installed: :version', ['version' => $updates['current']]) }}</div>
                                </div>
                                <form method="POST" action="{{ route('admin.system.updates.install') }}">
                                    @csrf
                                    <button class="btn btn-primary btn-sm" @disabled($updates['running'])>
                                        @if ($updates['running']) <span class="loading loading-spinner loading-xs"></span> @endif
                                        {{ __('Install :version', ['version' => $updates['offer']['version']]) }}
                                    </button>
                                </form>
                            </div>
                            @if (trim((string) ($updates['offer']['notes'] ?? '')) !== '')
                                {{-- Markdown, English first and each translation under a `---`: the
                                     admin's own section (ReleaseNotes). --}}
                                <div class="text-sm max-h-80 overflow-y-auto rounded-box bg-base-200/50 px-4 py-3 torgash-rich">{{ \App\Core\Updates\ReleaseNotes::html((string) $updates['offer']['notes']) }}</div>
                            @endif
                            <p class="text-xs opacity-60">{{ __("A backup of the database is made first; if anything fails, the previous version is put back by itself. The way back afterwards, from the server's console:") }} <code>php artisan torgash:update-rollback</code></p>
                        @elseif (! empty($updates['last']['error']))
                            <div class="alert alert-warning text-sm">{{ __('The update server could not be asked: :reason', ['reason' => $updates['last']['error']]) }}</div>
                        @elseif ($updates['last'])
                            <div class="text-sm text-success flex items-center gap-2"><x-icon name="circle-check" size="sm" /> {{ __('This is the newest version.') }}</div>
                        @else
                            <p class="text-sm opacity-70">{{ __('Not checked yet.') }}</p>
                        @endif

                        {{-- Packages already handed in (uploaded, or put in the folder by
                             FTP) wait here in plain sight: a second, deliberate click. --}}
                        @if ($updates['incoming'] !== [])
                            <div class="flex flex-col gap-2 text-sm">
                                @foreach ($updates['incoming'] as $package)
                                    <div class="flex flex-wrap items-center gap-2 border border-base-300 rounded-box p-2">
                                        <x-icon :name="$package['error'] ? 'circle-x' : 'package'" size="sm" @class(['text-error' => $package['error']]) />
                                        <span class="font-mono text-xs">{{ $package['name'] }}</span>
                                        <span class="text-xs opacity-60">{{ number_format($package['size'] / 1048576, 1) }} MB</span>
                                        @if ($package['error'])
                                            <span class="text-xs text-error basis-full">{{ $package['error'] }}</span>
                                        @elseif (! $package['newer'])
                                            <span class="text-xs opacity-60">{{ __('Torgash :version — not newer than this one', ['version' => $package['version']]) }}</span>
                                        @else
                                            <form method="POST" action="{{ route('admin.system.updates.install_file') }}" class="ms-auto"
                                                  data-confirm="{{ __('Install Torgash :version? A backup is made first; the shop is in maintenance for a minute or two.', ['version' => $package['version']]) }}">
                                                @csrf
                                                <input type="hidden" name="name" value="{{ $package['name'] }}">
                                                <button class="btn btn-primary btn-sm" @disabled($updates['running'])>{{ __('Install :version', ['version' => $package['version']]) }}</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('admin.system.updates.delete_file') }}" @class(['ms-auto' => $package['error'] || ! $package['newer']])>
                                            @csrf @method('DELETE')
                                            <input type="hidden" name="name" value="{{ $package['name'] }}">
                                            <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- By hand, for a shop that cannot download from the update
                             server (a blocked address, no outgoing connections): the
                             same signed package, the same checks, the same job. Rare
                             errands, so each is a window and not a part of the card. --}}
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-base-300 pt-3">
                            @if (! empty($updates['last']['checked_at']))
                                <p class="text-xs opacity-60 flex-1 min-w-60">{{ __('Checked :when', ['when' => \Illuminate\Support\Carbon::parse($updates['last']['checked_at'])->diffForHumans()]) }} · {{ __('checked by itself once a day') }}@if (! empty($updates['last']['source'])) · <span class="font-mono break-all">{{ $updates['last']['source'] }}</span>@endif</p>
                            @endif
                            <div class="flex flex-wrap gap-2 ms-auto">
                                <x-modal name="update-file" :title="__('Install from a file')" icon="upload"
                                         :trigger="__('Install from a file')" trigger-class="btn btn-ghost btn-sm gap-1.5" trigger-icon="upload">
                                    <form method="POST" action="{{ route('admin.system.updates.upload') }}" enctype="multipart/form-data" class="flex flex-col gap-3">
                                        @csrf
                                        <input type="file" name="package" accept=".zip" required class="file-input file-input-bordered w-full" aria-label="{{ __('Install from a file') }}">
                                        @error('package') <p class="text-xs text-error">{{ $message }}</p> @enderror
                                        <p class="text-xs opacity-60">{{ __('For a shop that cannot reach the update server: download the package (torgash-X.Y.Z.zip, not the -install archive) where it opens, and upload it here. Larger than :limit? Put it into :folder by FTP and reload this page. Only a package signed by the makers of Torgash is accepted.', ['limit' => number_format($updates['upload_limit'] / 1048576).' MB', 'folder' => 'storage/app/update/incoming']) }}</p>
                                        <div><button class="btn btn-primary btn-sm gap-1.5"><x-icon name="upload" size="sm" /> {{ __('Upload') }}</button></div>
                                    </form>
                                </x-modal>
                                <x-modal name="update-server" :title="__('Update server')" icon="server"
                                         :trigger="__('Update server')" trigger-class="btn btn-ghost btn-sm gap-1.5" trigger-icon="server">
                                    <form method="POST" action="{{ route('admin.system.updates.server') }}" class="flex flex-col gap-3">
                                        @csrf
                                        <input type="url" name="address" value="{{ old('address', $updates['address']) }}" placeholder="{{ $updates['default'] }}" class="input input-bordered w-full font-mono text-sm @error('address') input-error @enderror" aria-label="{{ __('Update server') }}">
                                        @error('address') <p class="text-xs text-error">{{ $message }}</p> @enderror
                                        <p class="text-xs opacity-60">{{ __('Blank: the built-in address. Another server can only offer what is signed by the key in this code, so a wrong address stops updates, it cannot install anything.') }}</p>
                                        <p class="text-xs opacity-60">{{ __('A GitHub repository works too — https://github.com/owner/repo: its releases are asked. TORGASH_GITHUB_REPO in .env names one to ask when this address does not answer.') }}</p>
                                        <div><button class="btn btn-sm btn-primary">{{ __('Save') }}</button></div>
                                    </form>
                                </x-modal>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-semibold flex items-center gap-2"><x-icon name="shield-alert" size="sm" /> {{ __('Libraries') }}</h3>
                            <form method="POST" action="{{ route('admin.system.audit') }}">
                                @csrf
                                <button class="btn btn-sm" @disabled($auditRunning)>
                                    @if ($auditRunning) <span class="loading loading-spinner loading-xs"></span> @endif
                                    {{ __('Check now') }}
                                </button>
                            </form>
                        </div>
                        <p class="text-xs opacity-60">{{ __('Asks the public advisories database whether any library the engine is built on has a known vulnerability.') }}</p>
                        @if (! $audit)
                            <p class="text-sm opacity-70">{{ __('Not checked yet.') }}</p>
                        @elseif (! empty($audit['error']))
                            <div class="alert alert-warning text-sm">{{ __('The check could not be made.') }} <span class="opacity-70">{{ $audit['error'] }}</span></div>
                        @elseif ($advisories === [])
                            <div class="text-sm text-success flex items-center gap-2"><x-icon name="circle-check" size="sm" /> {{ __('No known vulnerabilities.') }}</div>
                        @else
                            <div class="text-sm text-warning flex items-center gap-2"><x-icon name="triangle-alert" size="sm" class="shrink-0" /> {{ __('Known vulnerabilities: :count. Libraries affected: :packages.', ['count' => count($audit['advisories']), 'packages' => count($advisories)]) }}</div>
                            {{-- One row per library, with the version here and the one that closes it —
                                 not the ranges of every branch the project ever had. --}}
                            <div class="rounded-box border border-base-300 divide-y divide-base-300">
                                @foreach ($advisories as $library)
                                    <div class="grid gap-x-4 gap-y-1.5 p-3 md:grid-cols-[15rem_minmax(0,1fr)]">
                                        <div class="min-w-0">
                                            <div class="font-mono text-xs break-words">{{ $library['package'] }}</div>
                                            @if ($library['installed'])
                                                <div class="text-xs opacity-60 mt-0.5">{{ __('Installed: :version', ['version' => $library['installed']]) }}@if ($library['fixed']) · {{ __('fixed in :version', ['version' => $library['fixed']]) }}@endif</div>
                                            @endif
                                        </div>
                                        <ul class="text-sm flex flex-col gap-1.5 min-w-0">
                                            @foreach ($library['advisories'] as $advisory)
                                                <li>
                                                    @if ($advisory['link'])<a href="{{ $advisory['link'] }}" target="_blank" rel="noopener" class="link link-hover">{{ $advisory['title'] }}</a>@else{{ $advisory['title'] }}@endif
                                                    @if ($advisory['cve'] !== '') <span class="text-xs opacity-60 whitespace-nowrap">{{ $advisory['cve'] }}</span> @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if ($audit)
                            <p class="text-xs opacity-60">{{ __('Checked :when', ['when' => \Illuminate\Support\Carbon::parse($audit['checked_at'])->diffForHumans()]) }}</p>
                        @endif
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold flex items-center gap-2"><x-icon name="database" size="sm" /> {{ __('Database') }}</h3>
                        @if (! ($database['ok'] ?? false))
                            <div class="alert alert-error text-sm">{{ $database['error'] ?? '' }}</div>
                        @else
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                <dt class="opacity-60">{{ __('Name') }}</dt><dd class="font-mono">{{ $database['name'] }}</dd>
                                <dt class="opacity-60">{{ __('Size') }}</dt><dd>{{ $mb($database['bytes']) }}</dd>
                                <dt class="opacity-60">{{ __('Locale') }}</dt>
                                <dd>
                                    {{ $database['provider'] }} · {{ $database['ctype'] }}
                                    <span class="{{ $ok($database['cyrillic_ok']) }}">— {{ $database['cyrillic_ok'] ? __('Cyrillic search works') : __('Cyrillic is not folded') }}</span>
                                </dd>
                            </dl>
                            <div class="overflow-x-auto">
                                <table class="table table-sm">
                                    <thead><tr><th>{{ __('Largest tables') }}</th><th class="text-end">{{ __('Rows') }}</th><th class="text-end">{{ __('Size') }}</th></tr></thead>
                                    <tbody>
                                        @foreach ($database['tables'] as $table)
                                            <tr>
                                                <td class="font-mono text-xs">{{ $table['name'] }}</td>
                                                <td class="text-end tabular-nums">{{ number_format($table['rows'], 0, ',', ' ') }}</td>
                                                <td class="text-end tabular-nums">{{ $mb($table['bytes']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold flex items-center gap-2"><x-icon name="hard-drive" size="sm" /> {{ __('Disk') }}</h3>
                        @if ($disk['total'])
                            @php $used = 1 - $disk['free'] / $disk['total']; @endphp
                            <progress @class(['progress w-full', 'progress-error' => $used > 0.9, 'progress-primary' => $used <= 0.9]) value="{{ round($used * 100) }}" max="100"></progress>
                            <div class="text-sm">{{ __(':free free of :total', ['free' => $mb($disk['free']), 'total' => $mb($disk['total'])]) }}</div>
                        @else
                            <div class="text-sm opacity-60">{{ __('The server does not say.') }}</div>
                        @endif
                        <p class="text-xs opacity-60">
                            {{ $disk['files_local'] ? __('Photos and uploads are on this disk too.') : __('Photos and uploads are in the bucket, not on this disk.') }}
                            @can('settings.manage') <a href="{{ route('admin.storage') }}" class="link">{{ __('File storage') }}</a> @endcan
                        </p>
                    </div>
                </section>

                {{-- The stylesheet is built from the templates, so a module or a
                     theme bringing a class nobody else uses needs it built here,
                     on this server (PLAN §45.2). --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-semibold flex items-center gap-2"><x-icon name="palette" size="sm" /> {{ __('Stylesheet') }}</h3>
                            @if ($styles['builder'])
                                <form method="POST" action="{{ route('admin.system.styles') }}">
                                    @csrf
                                    <button class="btn btn-sm" @disabled($styles['running'])>
                                        @if ($styles['running']) <span class="loading loading-spinner loading-xs"></span> @endif
                                        {{ __('Rebuild') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                        <p class="text-xs opacity-60">{{ __('Built from the templates of the engine, the themes and the modules. Rebuilt by itself when a module or a theme changes; the button is for a template edited by hand.') }}</p>
                        @if (! $styles['builder'])
                            <div class="alert alert-warning text-sm flex-col items-start gap-1">
                                <span>{{ __('There is no style builder on this server, so new classes in templates are not drawn.') }}</span>
                                <code class="text-xs">php artisan torgash:styles --fetch</code>
                            </div>
                        @elseif (! empty($styles['last']['error']))
                            <div class="alert alert-warning text-sm">{{ __('The last build failed.') }} <span class="opacity-70">{{ $styles['last']['error'] }}</span></div>
                        @endif
                        @if (! empty($styles['last']['at']))
                            <p class="text-xs opacity-60">{{ __('Built :when', ['when' => \Illuminate\Support\Carbon::parse($styles['last']['at'])->diffForHumans()]) }}</p>
                        @endif
                    </div>
                </section>

                {{-- The storefront without what the shop added to it, for this
                     person only (App\Core\Support\SafeMode) — the way to look at
                     a page a theme, its CSS or a snippet has broken. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold flex items-center gap-2"><x-icon name="shield" size="sm" /> {{ __('Safe mode') }}</h3>
                        <p class="text-sm opacity-70">{{ __('The storefront in the engine\'s own theme, without your own CSS and code snippets — for you alone, customers still see the shop as it is. When a page broke after an edit, this tells you whether the theme or a snippet is to blame.') }}</p>
                        @if (\App\Core\Support\SafeMode::forced())
                            <div class="alert alert-warning text-sm py-2"><x-icon name="triangle-alert" size="sm" /> {{ __('TORGASH_SAFE_MODE in .env has it on for every visitor.') }}</div>
                        @else
                            <form method="POST" action="{{ route('admin.safe_mode') }}" class="flex flex-wrap gap-2">
                                @csrf
                                @if (session(\App\Core\Support\SafeMode::SESSION) === true)
                                    <input type="hidden" name="on" value="0">
                                    <input type="hidden" name="back" value="{{ url()->current() }}">
                                    <span class="badge badge-sm badge-warning">{{ __('On for you') }}</span>
                                    <button class="btn btn-sm">{{ __('Turn off') }}</button>
                                @else
                                    <input type="hidden" name="on" value="1">
                                    <button class="btn btn-sm gap-2 max-sm:shrink max-sm:h-auto max-sm:min-h-10 max-sm:py-2 max-sm:whitespace-normal"><x-icon name="external-link" size="sm" /> {{ __('Open the storefront in safe mode') }}</button>
                                @endif
                            </form>
                        @endif
                        <p class="text-xs opacity-60">{{ __('For every visitor at once: TORGASH_SAFE_MODE=true in .env.') }}</p>
                    </div>
                </section>

                {{-- TORGASH_CODE_UPLOAD: told here because .env is the only
                     place it can be changed, and a shop that never opens
                     .env should still learn the switch exists. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold flex items-center gap-2"><x-icon name="file-code" size="sm" /> {{ __('Uploading code') }}</h3>
                        @if ($codeUpload)
                            <p class="text-sm">{{ __('Modules and themes may be uploaded as a .zip from the admin.') }}</p>
                            <p class="text-xs opacity-60">{{ __('If only you install modules, switch it off: TORGASH_CODE_UPLOAD=false in .env. A stolen administrator password then can no longer put code on the server; new modules go into modules/ through the server itself.') }}</p>
                        @else
                            <div class="text-sm text-success flex items-center gap-2"><x-icon name="circle-check" size="sm" /> {{ __('Switched off: modules and themes arrive only through the server itself.') }}</div>
                            <p class="text-xs opacity-60">{{ __('TORGASH_CODE_UPLOAD=false in .env. Signed engine updates are not affected.') }}</p>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
    <form id="system-jobs-prune" method="POST" action="{{ route('admin.system.jobs.prune') }}" class="hidden">@csrf</form>

    {{-- A form in a window that came back with an error opens its window again. --}}
    @if ($errors->has('package') || $errors->has('address'))
        <script nonce="{{ csp_nonce() }}">document.getElementById('{{ $errors->has('package') ? 'modal-update-file' : 'modal-update-server' }}')?.showModal();</script>
    @endif
@endsection
