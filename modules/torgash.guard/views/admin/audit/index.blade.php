@extends('admin.layout')

@section('title', __('Audit'))

{{--
    Who changed what.

    The diff lives IN the row, folded away: the questions people bring here are
    questions about a list ("what happened to this order", "what did this person
    do on Tuesday"), and a detail page per entry would mean opening thirty of
    them to answer one. One line per entry, grouped by day, the fields that
    moved named on the line itself — the fold is for their values.
--}}

@section('content')
    @unless ($enabled)
        <div class="alert alert-warning">
            <x-icon name="triangle-alert" size="sm" />
            <span>{{ __('Recording is switched off in the .env (GUARD_AUDIT=false) — what is below is history, and nothing is being added to it.') }}</span>
        </div>
    @endunless

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error text-sm">{{ session('error') }}</div>
    @endif

    @use('Torgash\Guard\Models\AuditEntry')
    @php
        $subjectFilter = $filters['type'] !== '' && $filters['id'] !== '';
        $dayLabel = function ($date) {
            return match (true) {
                $date->isToday() => __('Today'),
                $date->isYesterday() => __('Yesterday'),
                default => $date->translatedFormat('j F Y'),
            };
        };
    @endphp

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-64 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('What or who…') }}">
                </label>

                <select name="user" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Who did it') }}">
                    <option value="">{{ __('Anybody') }}</option>
                    @foreach ($actors as $actor)
                        <option value="{{ $actor->user_id }}" @selected($filters['user'] === (string) $actor->user_id)>{{ $actor->user_name }}</option>
                    @endforeach
                </select>

                <select name="type" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('What') }}">
                    <option value="">{{ __('Anything') }}</option>
                    @foreach ($types->mapWithKeys(fn ($type) => [$type => AuditEntry::typeLabel($type)])->sort() as $type => $typeLabel)
                        <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ $typeLabel }}</option>
                    @endforeach
                </select>

                <select name="action" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Action') }}">
                    <option value="">{{ __('Any action') }}</option>
                    @foreach (['created', 'updated', 'deleted', 'restored', 'installed', 'uninstalled', 'enabled', 'disabled', 'signed_in'] as $action)
                        <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ AuditEntry::labelFor($action) }}</option>
                    @endforeach
                </select>

                <div class="flex items-center gap-1">
                    <input type="date" name="from" value="{{ $filters['from'] }}" class="input input-sm w-36" aria-label="{{ __('From') }}" data-submit-on-change>
                    <span class="opacity-40">–</span>
                    <input type="date" name="to" value="{{ $filters['to'] }}" class="input input-sm w-36" aria-label="{{ __('To') }}" data-submit-on-change>
                </div>

                {{-- Carried through the form so "everything about this row"
                     survives a search inside it. --}}
                <input type="hidden" name="id" value="{{ $filters['id'] }}">

                @if (request()->query())
                    <a href="{{ route('admin.guard.audit.index') }}" class="btn btn-sm btn-ghost">{{ __('Reset') }}</a>
                @endif
            </form>

            {{-- Read only, on purpose: whoever the trail records must not be
                 able to shorten it from here (config/audit.php). --}}
            @php
                $auditDays = (int) config('guard.audit.retention_days', 180);
                $auditCap = (int) config('guard.audit.max_per_request', 200);
            @endphp
            <p class="text-xs opacity-60 flex flex-wrap items-center gap-1.5">
                <x-icon name="lock" size="sm" />
                {{ $auditDays > 0 ? __('Kept for :days days', ['days' => $auditDays]) : __('Kept for ever') }} ·
                {{ $auditCap > 0 ? __('at most :n entries per action', ['n' => $auditCap]) : __('no limit per action') }} ·
                {{ __('set in the .env (GUARD_AUDIT_*), not here — so nobody can shorten the trail from the admin.') }}
            </p>

            @if ($filters['task'] !== '')
                <div class="alert alert-info text-sm py-2">
                    <x-icon name="list-checks" size="sm" />
                    <span>{{ __('Everything background task #:id wrote.', ['id' => $filters['task']]) }}</span>
                    @can('guard.revert')
                        <form method="POST" action="{{ route('admin.guard.audit.revert_task', ['task' => (int) $filters['task']]) }}" class="ml-auto"
                              data-confirm="{{ __('Put back everything this task changed? Fields changed again since then are left alone; what could not be put back is counted in the result.') }}">
                            @csrf
                            <button class="btn btn-sm gap-1"><x-icon name="undo-2" size="sm" /> {{ __('Put back everything this task did') }}</button>
                        </form>
                    @endcan
                    <a href="{{ route('admin.guard.audit.index') }}" class="link">{{ __('Back to all') }}</a>
                </div>
            @endif

            @if ($subjectFilter)
                <div class="alert alert-info text-sm py-2">
                    <x-icon name="history" size="sm" />
                    <span>{{ __('Everything about :what #:id.', ['what' => AuditEntry::typeLabel($filters['type']), 'id' => $filters['id']]) }}</span>
                    <a href="{{ route('admin.guard.audit.index') }}" class="link ml-auto">{{ __('Back to all') }}</a>
                </div>
            @endif

            <div class="flex flex-col">
                @php $previousDay = null; @endphp
                @forelse ($groups as $run)
                    @php
                        // A run is one act folded from neighbouring rows (see
                        // AuditController::runs); its newest entry heads it.
                        $entry = $run[0];
                        $runFields = collect($run)->flatMap(fn ($e) => $e->fields())->unique()->values()->all();
                        $day = $entry->created_at?->toDateString();
                    @endphp
                    @if ($day !== $previousDay)
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-50 pt-4 pb-1 first:pt-0">{{ $entry->created_at ? $dayLabel($entry->created_at) : '—' }}</div>
                        @php $previousDay = $day; @endphp
                    @endif

                    <details class="group border-t border-base-300">
                        <summary class="list-none cursor-pointer grid grid-cols-[3rem_minmax(0,1fr)] md:grid-cols-[3rem_9rem_7rem_minmax(0,1fr)_minmax(0,16rem)_1rem] items-center gap-x-3 gap-y-1 py-2 px-1 hover:bg-base-200/60 rounded">
                            <span class="text-xs font-mono opacity-60">{{ $entry->created_at?->format('H:i') }}</span>
                            <span class="text-sm truncate md:order-none order-last col-span-2 md:col-span-1 opacity-70 md:opacity-100">{{ $entry->who() }}</span>
                            <span class="hidden md:block"><span class="badge badge-sm {{ $entry->badge() }}">{{ $entry->actionLabel() }}</span></span>
                            <span class="min-w-0 truncate">
                                <span class="md:hidden badge badge-sm {{ $entry->badge() }} mr-1">{{ $entry->actionLabel() }}</span>
                                <span class="opacity-60">{{ $entry->entityLabel() }}</span>
                                @if ($entry->displayTitle())
                                    <span class="font-medium">{{ $entry->displayTitle() }}</span>
                                @endif
                                @if (count($run) > 1)
                                    <span class="badge badge-ghost badge-sm">× {{ count($run) }}</span>
                                @endif
                                @if ($entry->entity_id)
                                    <span class="text-xs opacity-40 font-mono">#{{ $entry->entity_id }}</span>
                                @endif
                            </span>
                            <span class="hidden md:block text-xs font-mono opacity-50 truncate" title="{{ implode(', ', $runFields) }}">
                                {{ implode(', ', array_slice($runFields, 0, 5)) }}@if (count($runFields) > 5) +{{ count($runFields) - 5 }}@endif
                            </span>
                            <x-icon name="chevron-down" size="sm" class="hidden md:block opacity-40 transition-transform group-open:rotate-180" />
                        </summary>

                        <div class="pl-2 md:pl-[3.75rem] pb-3 flex flex-col gap-2">
                            @foreach ($run as $step)
                            @if (count($run) > 1)
                                <div class="text-xs opacity-50 font-mono">{{ $step->created_at?->format('H:i:s') }}</div>
                            @endif
                            @if ($step->changes)
                                <div class="overflow-x-auto">
                                    {{-- w-auto: the columns hug what is in them, so a one-word change does
                                         not put the old value and the new one at opposite ends of the screen. --}}
                                    <table class="table table-xs w-auto">
                                        <tbody>
                                            @foreach ($step->changes as $field => $sides)
                                                <tr>
                                                    <td class="font-mono text-xs align-top w-40">{{ $field }}</td>
                                                    <td class="align-top opacity-60 line-through break-all max-w-md">{{ AuditEntry::printed($sides, 'from') }}</td>
                                                    <td class="align-top break-all max-w-md">{{ AuditEntry::printed($sides, 'to') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                            {{-- Undo writes to the thing changed, never to the
                                 trail (Torgash\Guard\Audit\Revert): fields
                                 changed again since, or kept only in part,
                                 stay as they are and the answer says so. --}}
                            @if (isset($revertible[$step->id]))
                                <form method="POST" action="{{ route('admin.guard.audit.revert', $step) }}"
                                      data-confirm="{{ __('Put these fields back as they were before this change? Fields changed again since then are left alone.') }}">
                                    @csrf
                                    <button class="btn btn-sm gap-1"><x-icon name="undo-2" size="sm" /> {{ __('Put back as it was') }}</button>
                                </form>
                            @endif
                            @endforeach

                            <div class="text-xs opacity-50 flex flex-wrap gap-x-4 gap-y-1">
                                <span>{{ $entry->created_at?->format('Y-m-d H:i:s') }}</span>
                                @if ($entry->task_id)
                                    <span class="inline-flex items-center gap-1"><x-icon name="list-checks" size="sm" /> {{ __('Background task') }}: {{ __($entry->task_name) }}</span>
                                    @if ($filters['task'] === '')
                                        <a class="link" href="{{ route('admin.guard.audit.index', ['task' => $entry->task_id]) }}">{{ __('Everything this task did') }}</a>
                                    @endif
                                @endif
                                @if ($entry->route)<span class="font-mono">{{ $entry->method }} {{ $entry->route }}</span>@endif
                                @if ($entry->ip)<span>{{ $entry->ip }}</span>@endif
                                @if ($entry->entity_type && $entry->entity_id && ! $subjectFilter)
                                    <a class="link" href="{{ route('admin.guard.audit.index', ['type' => $entry->entity_type, 'id' => $entry->entity_id]) }}">
                                        {{ __('Everything about this one') }}
                                    </a>
                                @endif
                                @if ($entry->user_id && $filters['user'] === '')
                                    <a class="link" href="{{ route('admin.guard.audit.index', ['user' => $entry->user_id]) }}">{{ __('Everything this person did') }}</a>
                                @endif
                            </div>
                        </div>
                    </details>
                @empty
                    <p class="text-sm opacity-60 py-6 text-center">
                        {{ request()->query() ? __('Nothing matches these filters.') : __('Nothing recorded yet.') }}
                    </p>
                @endforelse
            </div>

            {{ $entries->links() }}
        </div>
    </div>
@endsection
