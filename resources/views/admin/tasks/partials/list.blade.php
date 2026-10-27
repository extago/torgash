{{--
    Swapped in whole by HTMX while anything is still running. The poll trigger
    is emitted ONLY while a task is really active (a stale one is not), so a
    quiet screen makes no requests at all — the refresh stops by itself when
    the last job finishes.
--}}
@php
    $tabs = [
        'all' => [__('All'), 'list', $counts['all'] ?? 0],
        'active' => [__('In progress'), 'loader', $counts['active'] ?? 0],
        'done' => [__('Finished'), 'check', $counts['done'] ?? 0],
        'failed' => [__('With an error'), 'circle-x', $counts['failed'] ?? 0],
        'jobs' => [__('Failed queue jobs'), 'inbox', $failedTotal],
    ];
    $statusBadge = fn ($task) => match (true) {
        $task->isStale() => ['badge-error', __('worker stopped')],
        $task->status === 'running' && $task->cancel_requested_at !== null => ['badge-warning', __('stopping')],
        $task->status === 'running' => ['badge-info', __('running')],
        $task->status === 'queued' => ['badge-neutral', __('queued')],
        $task->status === 'done' => ['badge-success', __('done')],
        $task->status === 'failed' => ['badge-error', __('failed')],
        $task->status === 'cancelled' => ['badge-ghost', __('cancelled')],
        default => ['badge-ghost', __($task->status)],
    };
@endphp
{{-- hx-get only together with its trigger: without one HTMX fires a div's
     request on CLICK, so every click inside the list reloaded it and an
     error just opened closed again. The poll also waits while a detail is
     open, so reading one is not interrupted. --}}
<div id="tasks-list" class="flex flex-col gap-4"
     @if ($polling)
         hx-get="{{ route('admin.tasks.rows', ['tab' => $tab]) }}"
         hx-swap="outerHTML"
         hx-trigger="every 3s [!document.querySelector('#tasks-list details[open]')]"
     @endif>

    @if ($workerSilent)
        <div class="alert alert-warning text-sm">
            <x-icon name="circle-alert" />
            <span>
                {{ __('Jobs are waiting and nobody is taking them — no queue worker seems to be running.') }}
                <code class="text-xs">php artisan queue:work</code>
            </span>
        </div>
    @endif

    <div role="tablist" class="tabs tabs-border">
        @foreach ($tabs as $tabKey => [$tabLabel, $tabIcon, $tabCount])
            <a role="tab" href="{{ route('admin.tasks', $tabKey === 'all' ? [] : ['tab' => $tabKey]) }}" @class(['tab gap-2', 'tab-active' => $tab === $tabKey])>
                <x-icon :name="$tabIcon" size="sm" /> {{ $tabLabel }}
                <span @class(['badge badge-sm', 'badge-error' => in_array($tabKey, ['failed', 'jobs'], true) && $tabCount, 'badge-ghost' => ! (in_array($tabKey, ['failed', 'jobs'], true) && $tabCount)])>{{ $tabCount }}</span>
            </a>
        @endforeach
    </div>

    @if ($tab !== 'jobs')
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="text-sm opacity-70 max-w-3xl">
                        {{ __('Slow work runs in the background: imports, thumbnails, rates, directories. Finished tasks are kept for 30 days.') }}
                        <span class="opacity-80">{{ __('Jobs waiting in the queue: :count', ['count' => $pending]) }}</span>
                    </p>
                    <form method="POST" action="{{ route('admin.tasks.clear') }}">
                        @csrf
                        <button class="btn btn-sm gap-1.5"><x-icon name="brush-cleaning" size="sm" /> {{ __('Clear finished') }}</button>
                    </form>
                </div>

                @if ($tasks->isEmpty())
                    <p class="opacity-60 text-sm py-6 text-center">{{ __('Nothing here.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Task') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th class="w-72">{{ __('Progress') }}</th>
                                    <th>{{ __('Started by') }}</th>
                                    <th>{{ __('When') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tasks as $task)
                                    @php [$badgeClass, $badgeLabel] = $statusBadge($task); @endphp
                                    <tr class="hover:bg-base-200/50">
                                        <td>
                                            <div class="font-medium">{{ __($task->name) }}</div>
                                            @if ($task->module)
                                                <div class="text-xs opacity-50 font-mono">{{ $task->module }}</div>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap">
                                            <span class="badge badge-sm {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                            @if ($task->duration() !== null)
                                                <div class="text-xs opacity-50 mt-0.5" title="{{ __('How long it ran') }}">{{ $task->duration() }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($task->percent() !== null)
                                                <div class="flex items-center gap-2">
                                                    <progress @class(['progress w-40', 'progress-primary' => ! $task->isStale() && $task->status !== 'failed', 'progress-error' => $task->isStale() || $task->status === 'failed'])
                                                              value="{{ $task->percent() }}" max="100"></progress>
                                                    <span class="text-xs opacity-60 tabular-nums">{{ $task->percent() }}%</span>
                                                </div>
                                            @endif
                                            @if ($task->total)
                                                <div class="text-xs opacity-50 tabular-nums">{{ number_format($task->progress, 0, '.', ' ') }} / {{ number_format($task->total, 0, '.', ' ') }}</div>
                                            @endif
                                            @if ($task->isStale())
                                                <div class="text-xs text-error">{{ __('No news from it since :when — the worker running it has stopped.', ['when' => $task->updated_at->diffForHumans()]) }}</div>
                                            @elseif ($task->message)
                                                <div class="text-xs opacity-70">{{ $task->messageText() }}</div>
                                            @endif
                                            @if ($task->error)
                                                <div class="text-xs text-error break-all">{{ Str::limit($task->error, 300) }}</div>
                                            @endif
                                            @if ($taskNotes = $task->notes())
                                                <details class="text-xs mt-1">
                                                    <summary class="cursor-pointer opacity-70">{{ trans_choice(':count note|:count notes', $task->noteCount(), ['count' => $task->noteCount()]) }}</summary>
                                                    <ul class="list-disc ms-4 mt-1 flex flex-col gap-0.5 opacity-80">
                                                        @foreach ($taskNotes as $taskNote)
                                                            <li class="break-words">{{ $taskNote }}</li>
                                                        @endforeach
                                                        @if ($task->noteCount() > count($taskNotes))
                                                            <li class="opacity-60">{{ __('… and :count more', ['count' => $task->noteCount() - count($taskNotes)]) }}</li>
                                                        @endif
                                                    </ul>
                                                </details>
                                            @endif
                                        </td>
                                        <td class="text-sm">{{ $task->user?->name ?? __('automatically') }}</td>
                                        <td class="text-xs opacity-60 whitespace-nowrap" title="{{ $task->created_at?->format('Y-m-d H:i:s') }}">{{ $task->created_at?->diffForHumans() }}</td>
                                        <td class="text-end whitespace-nowrap">
                                            @if ($task->isActive() && ! ($task->status === 'running' && $task->cancel_requested_at && ! $task->isStale()))
                                                <form method="POST" action="{{ route('admin.tasks.cancel', $task) }}" class="inline"
                                                      data-confirm="{{ $task->status === 'running' && ! $task->isStale() ? __('Stop this task? It stops at its next step; what it has done so far stays done.') : __('Cancel this task?') }}">
                                                    @csrf
                                                    <button class="btn btn-ghost btn-sm btn-square text-error"
                                                            title="{{ $task->status === 'running' && ! $task->isStale() ? __('Stop') : __('Cancel the task') }}"
                                                            aria-label="{{ __('Cancel the task') }}"><x-icon name="circle-stop" /></button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300" id="failed-jobs">
            <div class="card-body gap-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="text-sm opacity-70 max-w-3xl">
                        {{ __('Queued work that gave up, including notification emails that could not be sent. Retrying puts the job back in the queue exactly as it was.') }}
                    </p>
                    @if ($failed->isNotEmpty())
                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('admin.tasks.retry_all') }}">
                                @csrf
                                <button class="btn btn-sm gap-1.5"><x-icon name="rotate-cw" size="sm" /> {{ __('Retry all') }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.tasks.forget_all') }}"
                                  data-confirm="{{ __('Delete every failed job? They cannot be retried afterwards.') }}">
                                @csrf
                                <button class="btn btn-sm btn-ghost text-error gap-1.5"><x-icon name="trash-2" size="sm" /> {{ __('Delete all') }}</button>
                            </form>
                        </div>
                    @endif
                </div>

                @if ($failed->isEmpty())
                    <p class="opacity-60 text-sm py-6 text-center">{{ __('No failed jobs.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Job') }}</th>
                                    <th>{{ __('Error') }}</th>
                                    <th>{{ __('When') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($failed as $job)
                                    <tr class="hover:bg-base-200/50 align-top">
                                        <td class="min-w-48">
                                            <div class="font-medium">{{ $job->title }}</div>
                                            <div class="text-xs opacity-50 font-mono break-all">{{ $job->class }}</div>
                                        </td>
                                        <td class="text-xs">
                                            <details>
                                                <summary class="cursor-pointer break-all">{{ Str::limit($job->summary, 160) }}</summary>
                                                <pre class="mt-2 max-h-64 overflow-auto text-xs opacity-70 whitespace-pre-wrap break-all">{{ Str::limit($job->exception, 6000) }}</pre>
                                            </details>
                                        </td>
                                        <td class="text-xs opacity-60 whitespace-nowrap" title="{{ $job->failed_at->format('Y-m-d H:i:s') }}">{{ $job->failed_at->diffForHumans() }}</td>
                                        <td class="text-end whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1">
                                                <form method="POST" action="{{ route('admin.tasks.retry', $job->uuid) }}" class="inline">
                                                    @csrf
                                                    <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Retry') }}" aria-label="{{ __('Retry') }}"><x-icon name="rotate-cw" /></button>
                                                </form>
                                                <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                                <form method="POST" action="{{ route('admin.tasks.forget', $job->uuid) }}" class="inline">
                                                    @csrf
                                                    <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($failedTotal > $failed->count())
                        <p class="text-xs opacity-60">{{ __('Showing the newest :shown of :total.', ['shown' => $failed->count(), 'total' => $failedTotal]) }}</p>
                    @endif
                @endif
            </div>
        </div>
    @endif
</div>
