@extends('admin.layout')

@section('title', $error->title())

{{--
    One kind of failure: what it is, how often, and what was happening the last
    time it happened.

    The context is a SAMPLE of the most recent occurrence and says so — keeping
    every occurrence's would make this the biggest table in the database, and
    "what was going on the last time" is the question somebody debugging
    actually asks.
--}}

@php
    $context = (array) ($error->context ?? []);
@endphp

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.guard.errors.index') }}" class="btn btn-ghost btn-sm gap-1">
            <x-icon name="arrow-left" size="sm" />
            {{ __('All errors') }}
        </a>
        <span class="badge {{ $error->badge() }}">{{ $error->level }}</span>
        <span class="badge badge-ghost">× {{ $error->count }}</span>
        @if ($error->resolved_at)
            <span class="badge badge-success badge-outline">{{ __('Dealt with') }}</span>
        @endif
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            <h2 class="card-title text-base">{{ $error->class ?: $error->title() }}</h2>

            <p class="text-sm whitespace-pre-line break-words">{{ $error->message }}</p>

            @if ($error->shortFile())
                <div class="text-xs font-mono opacity-60">
                    {{ $error->shortFile() }}@if ($error->originLine()):{{ $error->originLine() }}@endif
                </div>
            @endif
            @if ($error->thrownAt())
                <div class="text-xs font-mono opacity-40">{{ __('Thrown at') }} {{ $error->thrownAt() }}</div>
            @endif
            @if ($error->codeState() === 'changed')
                <div class="alert alert-info text-sm py-2"><x-icon name="file-pen" size="sm" /> {{ __('This file was saved after the last time it happened — it may already be fixed.') }}</div>
            @elseif ($error->codeState() === 'gone')
                <div class="alert alert-info text-sm py-2"><x-icon name="file-x" size="sm" /> {{ __('This file no longer exists.') }}</div>
            @endif

            <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                <div>
                    <span class="opacity-60">{{ __('First seen') }}:</span>
                    {{ $error->first_seen_at?->format('Y-m-d H:i') }}
                </div>
                <div>
                    <span class="opacity-60">{{ __('Last seen') }}:</span>
                    {{ $error->last_seen_at?->format('Y-m-d H:i') }}
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($error->resolved_at)
                    <form method="POST" action="{{ route('admin.guard.errors.reopen', $error) }}">
                        @csrf
                        <button class="btn btn-sm gap-1.5">
                            <x-icon name="rotate-ccw" size="sm" />
                            {{ __('Back on the list') }}
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.guard.errors.resolve', $error) }}">
                        @csrf
                        <button class="btn btn-primary btn-sm gap-1.5">
                            <x-icon name="circle-check" size="sm" />
                            {{ __('I have dealt with this') }}
                        </button>
                    </form>
                @endif

                {{-- Beside the others, never nested: an inner form is dropped by
                     the parser while its inputs stay behind. --}}
                <form method="POST" action="{{ route('admin.guard.errors.destroy', $error) }}"
                      data-confirm="{{ __('Delete this record?') }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-ghost btn-sm text-error">{{ __('Delete') }}</button>
                </form>

                <span class="text-xs opacity-50">
                    {{ __('Marking it dealt with hides it until it happens again.') }}
                </span>
            </div>
        </div>
    </div>

    @if ($context !== [])
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <h2 class="card-title text-base">{{ __('The last time it happened') }}</h2>

                <div class="flex flex-col gap-1 text-sm">
                    @foreach (['url' => __('Page'), 'method' => __('Method'), 'ip' => __('IP'), 'user_id' => __('Under the account')] as $key => $label)
                        @if (! empty($context[$key]))
                            <div class="break-all">
                                <span class="opacity-60">{{ $label }}:</span> {{ $context[$key] }}
                            </div>
                        @endif
                    @endforeach
                </div>

                @if (! empty($context['extra']))
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-50 mb-1">{{ __('Context') }}</div>
                        <div class="flex flex-col gap-1 text-sm">
                            @foreach ($context['extra'] as $key => $value)
                                <div class="break-all">
                                    <span class="opacity-60 font-mono text-xs">{{ $key }}:</span> {{ $value }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (! empty($context['trace']))
                    <details>
                        <summary class="cursor-pointer text-sm opacity-70">{{ __('Where it came from') }}</summary>
                        <div class="overflow-x-auto mt-2">
                            <pre class="text-xs leading-relaxed">{{ implode("\n", $context['trace']) }}</pre>
                        </div>
                    </details>
                @endif
            </div>
        </div>
    @endif
@endsection
