@extends('admin.layout')

@section('title', __('System'))

{{-- Backup plans (PLAN §67.2): each says what goes in, when, and where it is
     kept. "The database twice a day, the files once a week" is two plans. --}}
@section('content')
    @php
        $partNames = ['database' => __('Database'), 'files' => __('Uploaded files'), 'code' => __('Modules and themes')];
    @endphp

    <div class="flex flex-col gap-4">
        @include('admin.system._tabs')

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm opacity-70 max-w-2xl">{{ __('A plan says what goes into a backup, when it is made, its name, and the storages that keep it — each with its own number to keep. The schedule needs the scheduler and a queue worker.') }}</p>
                    <a href="{{ route('admin.system.backup-plans.create') }}" class="btn btn-primary btn-sm">+ {{ __('New plan') }}</a>
                </div>

                @if ($plans->isEmpty())
                    <div class="text-center py-10 opacity-60">{{ __('No plans: backups are made only before updates.') }}</div>
                @else
                    <table class="table admin-cards">
                        <thead>
                            <tr>
                                <th>{{ __('Plan') }}</th>
                                <th>{{ __('When') }}</th>
                                <th>{{ __('Kept in') }}</th>
                                <th>{{ __('Last backup') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plans as $plan)
                                @php
                                    $moments = array_filter(array_map(fn ($e) => \App\Core\Backups\PlanSchedule::describe((array) $e), (array) $plan->schedule));
                                    $last = $plan->lastRun;
                                @endphp
                                <tr>
                                    <td data-cell="title">
                                        <a href="{{ route('admin.system.backup-plans.edit', $plan) }}" class="link link-hover font-medium">{{ $plan->name }}</a>
                                        @unless ($plan->enabled)
                                            <span class="badge badge-sm badge-neutral ml-1">{{ __('Off') }}</span>
                                        @endunless
                                        <div class="text-xs opacity-60">{{ collect($plan->contents)->map(fn ($p) => $partNames[$p] ?? $p)->implode(', ') }}</div>
                                    </td>
                                    <td data-cell="field" data-label="{{ __('When') }}" class="text-sm">
                                        @forelse ($moments as $moment)
                                            <div>{{ $moment }}</div>
                                        @empty
                                            <span class="opacity-60">{{ __('Only by the button') }}</span>
                                        @endforelse
                                    </td>
                                    <td data-cell="field" data-label="{{ __('Kept in') }}" class="text-sm">
                                        @foreach ($plan->destinations as $destination)
                                            <div>{{ $destination->name }} <span class="opacity-60">— {{ \App\Core\Backups\Retention::describe((array) json_decode((string) $destination->pivot->keep, true)) }}</span></div>
                                        @endforeach
                                    </td>
                                    <td data-cell="field" data-label="{{ __('Last backup') }}" class="text-sm whitespace-nowrap">
                                        @if ($last)
                                            <span @class(['text-error' => $last->status === 'failed', 'text-warning' => $last->status === 'partial'])>
                                                {{ ($last->started_at ?? $last->created_at)?->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}
                                                @if ($last->status === 'failed') · {{ __('Failed') }} @elseif ($last->status === 'partial') · {{ __('Not everywhere') }} @endif
                                            </span>
                                        @else
                                            <span class="opacity-60">{{ __('never') }}</span>
                                        @endif
                                    </td>
                                    <td data-cell="actions" class="text-end whitespace-nowrap w-px">
                                        <div class="inline-flex items-center gap-1">
                                            <form method="POST" action="{{ route('admin.system.backup-plans.run', $plan) }}" class="inline">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Make a backup now') }}" aria-label="{{ __('Make a backup now') }}"><x-icon name="play" size="sm" /></button>
                                            </form>
                                            <a href="{{ route('admin.system.backup-plans.edit', $plan) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" size="sm" /></a>
                                            <span class="inline-block w-px h-5 bg-base-300 mx-1 align-middle" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.system.backup-plans.destroy', $plan) }}" class="inline"
                                                  data-confirm="{{ __('Delete the plan ":plan"? The backups it made stay.', ['plan' => $plan->name]) }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>
    </div>
@endsection
