@extends('admin.layout')

@section('title', __('System'))

{{--
    Backups (PLAN §45): the list, a button, the schedule — and the way back,
    which is a console command on purpose: an update that broke the admin
    closes the door a "Restore" button would be behind.
--}}
@section('content')
    @php
        $mb = fn (int $bytes) => number_format($bytes / 1048576, 1, ',', ' ').' MB';
    @endphp

    <div class="flex flex-col gap-4">
        @include('admin.system._tabs')

        {{-- Emptied by the list's own polling once the backup it announces is
             done (hx-select-oob below): "it is being made" must not outlive it. --}}
        <div id="backups-status">
            @if (session('status'))
                <div class="alert alert-success text-sm">{{ session('status') }}</div>
            @endif
        </div>
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif
        @unless ($pgDump)
            <div class="alert alert-error text-sm">{{ __('pg_dump cannot be run on this server, so the database cannot be backed up. Install the PostgreSQL client tools, or name their folder in .env as TORGASH_PG_BIN.') }}</div>
        @endunless

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
            {{-- While a backup is being made the list asks for itself every few
                 seconds and swaps in; the answer that no longer says "running"
                 has no trigger, so the asking stops on its own. Only this card:
                 the settings beside it may be half edited. --}}
            <section id="backups-list" class="card bg-base-100 border border-base-300"
                     @if ($running) hx-get="{{ route('admin.system.backups') }}" hx-trigger="every 3s"
                     hx-select="#backups-list" hx-select-oob="#backups-status" hx-swap="outerHTML" @endif>
                <div class="card-body gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm opacity-70 max-w-xl">{{ __('One file with the database, the uploaded files and, if chosen, the modules and themes. Download one now and then and keep it somewhere other than this server.') }}</p>
                        <form method="POST" action="{{ route('admin.system.backups.create') }}">
                            @csrf
                            <button class="btn btn-primary btn-sm gap-1.5" @disabled($running || ! $pgDump)>
                                @if ($running) <span class="loading loading-spinner loading-xs"></span> {{ __('Making a backup…') }}
                                @else <x-icon name="archive" size="sm" /> {{ __('Make a backup now') }} @endif
                            </button>
                        </form>
                    </div>

                    @if ($backups->isEmpty())
                        <div class="text-center py-10 opacity-60">{{ __('No backups yet.') }}</div>
                    @else
                        <table class="table">
                            <tbody>
                                @foreach ($backups as $backup)
                                    <tr>
                                        <td class="font-mono text-sm">{{ $backup['name'] }}</td>
                                        <td class="text-sm whitespace-nowrap">{{ \Illuminate\Support\Carbon::createFromTimestamp($backup['at'])->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</td>
                                        <td class="text-sm text-end tabular-nums">{{ $mb($backup['bytes']) }}</td>
                                        <td class="text-end whitespace-nowrap w-px">
                                            <a href="{{ route('admin.system.backups.download', $backup['name']) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Download') }}" aria-label="{{ __('Download') }}"><x-icon name="download" size="sm" /></a>
                                            <span class="inline-block w-px h-5 bg-base-300 mx-1 align-middle" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.system.backups.destroy', $backup['name']) }}" class="inline"
                                                  data-confirm="{{ __('Delete this backup?') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    <div class="rounded-box bg-base-200/60 p-4 text-sm flex flex-col gap-2">
                        <div class="font-semibold">{{ __('Restoring') }}</div>
                        <p class="opacity-80">{{ __('From the server\'s console, so it works even when the admin does not:') }}</p>
                        <code class="font-mono text-xs bg-base-100 rounded px-2 py-1 block overflow-x-auto">php artisan torgash:backup-restore {{ $backups->first()['name'] ?? 'torgash-YYYY-MM-DD-HHMMSS.zip' }}</code>
                        <p class="opacity-80">{{ __('Without a name it lists the backups. With --database=other_db it restores into another database, to check a backup without touching the shop.') }}</p>
                        <p class="opacity-80">{{ __('The .env file — passwords and keys — is not in a backup on purpose. Keep a copy of it apart.') }}</p>
                    </div>
                </div>
            </section>

            <form method="POST" action="{{ route('admin.system.backups.settings') }}" class="card bg-base-100 border border-base-300">
                @csrf
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Settings') }}</h3>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Make one') }}</legend>
                        <select name="schedule" class="select select-bordered w-full">
                            <option value="off" @selected($settings['schedule'] === 'off')>{{ __('Only by the button') }}</option>
                            <option value="daily" @selected($settings['schedule'] === 'daily')>{{ __('Every night') }}</option>
                            <option value="weekly" @selected($settings['schedule'] === 'weekly')>{{ __('Every week') }}</option>
                        </select>
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('By the schedule — it needs the scheduler and a queue worker.') }}</p>
                    </fieldset>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Keep the newest') }}</legend>
                        <input type="number" name="keep" min="0" value="{{ $settings['keep'] }}" class="input input-bordered w-32">
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('Older ones are deleted. 0 keeps every one.') }}</p>
                    </fieldset>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Where') }}</legend>
                        <select name="disk" class="select select-bordered w-full">
                            <option value="local" @selected($settings['disk'] === 'local')>{{ __('A folder on this server') }}</option>
                            <option value="private" @selected($settings['disk'] === 'private')>{{ $onS3 ? __('The private bucket') : __('The private files folder') }}</option>
                        </select>
                        <p class="label text-xs opacity-60 whitespace-normal">{{ $onS3 ? __('The bucket keeps a copy away from the server it protects.') : __('Both are on this server while files are a local folder — download the backups now and then.') }}</p>
                    </fieldset>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" name="files" value="1" class="toggle toggle-sm" @checked($settings['files'])>
                        <span class="label-text whitespace-normal">{{ $onS3 ? __('Uploaded files (they are in the bucket — not copied)') : __('Uploaded files') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" name="code" value="1" class="toggle toggle-sm" @checked($settings['code'])>
                        <span class="label-text whitespace-normal">{{ __('Modules and themes') }}</span>
                    </label>
                    <p class="text-xs opacity-60">{{ $pgDump ?: '' }}</p>
                    <button class="btn btn-sm">{{ __('Save') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
