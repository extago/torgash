@extends('admin.layout')

@section('title', __('System'))

{{--
    Backups (PLAN §45, §67): the journal of backups made by the plans, a
    button — and the way back, which is a console command on purpose: an
    update that broke the admin closes the door a "Restore" button would be
    behind.
--}}
@section('content')
    @php
        $mb = fn (?int $bytes) => $bytes === null ? '—' : number_format($bytes / 1048576, 1, ',', ' ').' MB';
        $statusBadge = [
            'queued' => ['badge-neutral', __('In the queue')],
            'running' => ['badge-info', __('Being made')],
            'done' => ['badge-success', __('Made')],
            'partial' => ['badge-warning', __('Not everywhere')],
            'failed' => ['badge-error', __('Failed')],
        ];
        $reasons = [
            'schedule' => __('by the schedule'),
            'manual' => __('by the button'),
            'console' => __('from the console'),
            'update' => __('before an update'),
            'found' => __('made earlier'),
        ];
        $newest = $runs->first(fn ($r) => in_array($r->status, ['done', 'partial'], true))?->name;
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

        {{-- While a backup is waiting or being made the list asks for itself
             every few seconds and swaps in; the answer that no longer says so
             has no trigger, so the asking stops on its own. --}}
        <section id="backups-list" class="card bg-base-100 border border-base-300"
                 @if ($running) hx-get="{{ route('admin.system.backups') }}" hx-trigger="every 3s"
                 hx-select="#backups-list" hx-select-oob="#backups-status" hx-swap="outerHTML" @endif>
            <div class="card-body gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm opacity-70 max-w-2xl">{{ __('Backups are made by the plans: what goes in, when, and the storages that keep them. Keep at least one somewhere other than this server.') }}</p>
                    <form method="POST" action="{{ route('admin.system.backups.create') }}" class="flex items-center gap-2">
                        @csrf
                        @if ($plans->count() > 1)
                            <select name="plan" class="select select-bordered select-sm w-48" aria-label="{{ __('Backup plan') }}">
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                @endforeach
                            </select>
                        @elseif ($plans->count() === 1)
                            <input type="hidden" name="plan" value="{{ $plans->first()->id }}">
                        @endif
                        <button class="btn btn-primary btn-sm gap-1.5" @disabled($running || ! $pgDump || $plans->isEmpty())>
                            @if ($running) <span class="loading loading-spinner loading-xs"></span> {{ __('Making a backup…') }}
                            @else <x-icon name="archive" size="sm" /> {{ __('Make a backup now') }} @endif
                        </button>
                    </form>
                </div>

                @if ($runs->isEmpty())
                    <div class="text-center py-10 opacity-60">{{ __('No backups yet.') }}</div>
                @else
                    <table class="table admin-cards">
                        <thead>
                            <tr>
                                <th>{{ __('Backup') }}</th>
                                <th>{{ __('Made') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Kept in') }}</th>
                                <th class="text-end">{{ __('Size') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($runs as $run)
                                @php
                                    [$badgeClass, $badgeText] = $statusBadge[$run->status] ?? ['badge-ghost', $run->status];
                                    $stored = $run->copies->where('status', 'stored');
                                    $busy = in_array($run->status, ['queued', 'running'], true);
                                @endphp
                                <tr>
                                    <td data-cell="title">
                                        <div class="font-mono text-sm break-all">{{ $run->name ?? '—' }}</div>
                                        <div class="text-xs opacity-60">
                                            {{ $run->plan?->name ?? '' }}@if ($run->plan) · @endif{{ $reasons[$run->reason] ?? $run->reason }}
                                            @if ($run->pinned) · <span class="inline-flex items-center gap-0.5"><x-icon name="pin" size="sm" /> {{ __('pinned') }}</span> @endif
                                            @if ($run->isFolder()) · {{ __('a folder, no archive') }} @endif
                                            @if ($run->encrypted) · <span class="inline-flex items-center gap-0.5" title="{{ __('Encrypted with the password of its plan') }}"><x-icon name="lock" size="sm" /> {{ __('encrypted') }}</span> @endif
                                        </div>
                                        {{-- Changed files only (PLAN §67.10): which full backup it leans
                                             on — and, in red, that it is gone. --}}
                                        @if ($run->isDifferential())
                                            @php $base = $run->base; @endphp
                                            <div @class(['text-xs mt-0.5', 'opacity-60' => $base && $run->baseKept(), 'text-error' => ! ($base && $run->baseKept())])>
                                                {{ __('files changed since :when, leaning on :base', ['when' => $run->files_since->timezone(config('app.timezone'))->translatedFormat('j M, H:i'), 'base' => $base?->name ?? '?']) }}@unless ($base && $run->baseKept()) — {{ __('the full backup it leans on is gone') }}@endunless
                                            </div>
                                        @elseif ($run->plan?->option('changed_only') && in_array('files', $run->contents ?? [], true) && $run->finished())
                                            <div class="text-xs mt-0.5 opacity-60">{{ __('all the files — there was no full backup to lean on') }}</div>
                                        @endif
                                    </td>
                                    <td data-cell="field" data-label="{{ __('Made') }}" class="text-sm whitespace-nowrap">
                                        {{ ($run->started_at ?? $run->created_at)?->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}
                                    </td>
                                    <td data-cell="field" data-label="{{ __('Status') }}">
                                        <span class="badge badge-sm {{ $badgeClass }}">{{ $badgeText }}</span>
                                        @if ($run->verify_status === 'passed')
                                            <div class="text-xs text-success mt-1 inline-flex items-center gap-1"><x-icon name="shield-check" size="sm" /> {{ __('restores — checked :when', ['when' => $run->verified_at?->timezone(config('app.timezone'))->translatedFormat('j M, H:i')]) }}</div>
                                        @elseif ($run->verify_status === 'failed')
                                            <div class="text-xs text-error mt-1 whitespace-pre-line max-w-md"><x-icon name="shield-x" size="sm" /> {{ __('did not restore when checked:') }} {{ \Illuminate\Support\Str::limit((string) $run->verify_error, 300) }}</div>
                                        @elseif ($run->verify_status === 'blocked')
                                            <div class="text-xs text-warning mt-1 whitespace-pre-line max-w-md"><x-icon name="shield-alert" size="sm" /> {{ __('could not be checked:') }} {{ \Illuminate\Support\Str::limit((string) $run->verify_error, 300) }}</div>
                                        @endif
                                        @if ($run->error)
                                            <div class="text-xs text-error mt-1 whitespace-pre-line max-w-md">{{ \Illuminate\Support\Str::limit($run->error, 400) }}</div>
                                        @endif
                                    </td>
                                    <td data-cell="field" data-label="{{ __('Kept in') }}" class="text-sm">
                                        @foreach ($run->copies as $copy)
                                            <div @class(['text-error' => $copy->status === 'failed'])>
                                                {{ $copy->destination?->name ?? __('a deleted storage') }}@if ($copy->status === 'failed') — {{ __('not stored') }}@endif
                                                {{-- Sent again from a copy that is kept (PLAN §67.11), not made anew. --}}
                                                @if ($copy->status === 'failed' && $copy->destination && ! $busy && $stored->isNotEmpty())
                                                    <form method="POST" action="{{ route('admin.system.backups.resend', $copy) }}" class="inline">
                                                        @csrf
                                                        <button class="link link-primary text-xs align-baseline" title="{{ __('Send to :storage again from the copy that is kept, without making the backup anew', ['storage' => $copy->destination->name]) }}">{{ __('send again') }}</button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endforeach
                                    </td>
                                    <td data-cell="field" data-label="{{ __('Size') }}" class="text-sm text-end tabular-nums whitespace-nowrap">{{ $mb($run->bytes) }}</td>
                                    <td data-cell="actions" class="text-end whitespace-nowrap w-px">
                                        <div class="inline-flex items-center gap-1">
                                            @foreach ($stored as $copy)
                                                @if ($copy->destination && ! $run->isFolder())
                                                    <a href="{{ route('admin.system.backups.download', $copy) }}" class="btn btn-ghost btn-sm btn-square"
                                                       title="{{ __('Download from :storage', ['storage' => $copy->destination->name]) }}" aria-label="{{ __('Download from :storage', ['storage' => $copy->destination->name]) }}"><x-icon name="download" size="sm" /></a>
                                                @endif
                                            @endforeach
                                            @if (! $busy && $stored->isNotEmpty())
                                                <form method="POST" action="{{ route('admin.system.backups.verify', $run) }}" class="inline">
                                                    @csrf
                                                    <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Check by restoring') }}" aria-label="{{ __('Check by restoring') }}"><x-icon name="shield-check" size="sm" /></button>
                                                </form>
                                            @endif
                                            @unless ($busy)
                                                <form method="POST" action="{{ route('admin.system.backups.pin', $run) }}" class="inline">
                                                    @csrf
                                                    <button @class(['btn btn-ghost btn-sm btn-square', 'text-primary' => $run->pinned])
                                                            title="{{ $run->pinned ? __('Unpin') : __('Pin: never deleted by keeping N') }}" aria-label="{{ $run->pinned ? __('Unpin') : __('Pin: never deleted by keeping N') }}"><x-icon name="pin" size="sm" /></button>
                                                </form>
                                                <span class="inline-block w-px h-5 bg-base-300 mx-1 align-middle" aria-hidden="true"></span>
                                                <form method="POST" action="{{ route('admin.system.backups.destroy', $run) }}" class="inline"
                                                      data-confirm="{{ $stored->count() > 1 ? __('Delete this backup from every storage that keeps it?') : __('Delete this backup?') }}">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                                </form>
                                            @endunless
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    {{ $runs->links() }}
                @endif

                <div class="rounded-box bg-base-200/60 p-4 text-sm flex flex-col gap-2">
                    <div class="font-semibold">{{ __('Restoring') }}</div>
                    <p class="opacity-80">{{ __('From the server\'s console, so it works even when the admin does not:') }}</p>
                    <code class="font-mono text-xs bg-base-100 rounded px-2 py-1 block overflow-x-auto">php artisan torgash:backup-restore {{ $newest ?? 'torgash-local-main-YYYY-MM-DD-HHMMSS.zip' }}</code>
                    <p class="opacity-80">{{ __('Without a name it lists the backups. With --database=other_db it restores into another database, to check a backup without touching the shop.') }}</p>
                    <p class="opacity-80">{{ __('The .env file — passwords and keys — is not in a backup on purpose. Keep a copy of it apart.') }}</p>
                </div>
            </div>
        </section>
    </div>
@endsection
