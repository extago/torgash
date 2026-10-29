@extends('admin.layout')

@section('title', __('Run log'))
@section('title_hint', __('Every attempt, with what was sent and what came back. This is the only place that answers "did it arrive?".'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    {{-- The results as one row of tabs over the card, the way back at its end
         (app.css, `admin-tabs`); LINKS keeping the rule chosen. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Run log') }}">
            @foreach (['' => __('All'), 'sent' => __('sent'), 'failed' => __('failed'), 'skipped' => __('skipped')] as $statusKey => $statusLabel)
                <a href="{{ route('admin.automations.runs', array_filter(['status' => $statusKey, 'rule' => request('rule')], fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => (string) request('status', '') === (string) $statusKey])>
                    {{ \Illuminate\Support\Str::ucfirst($statusLabel) }}
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.automations.index') }}" class="btn btn-ghost gap-1.5">
                <x-icon name="arrow-left" size="sm" /> {{ __('Back to automations') }}
            </a>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if (request('status'))
                    <input type="hidden" name="status" value="{{ request('status') }}">
                @endif
                <select name="rule" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Rule') }}">
                    <option value="">{{ __('Any rule') }}</option>
                    @foreach ($rules as $rule)
                        <option value="{{ $rule->id }}" @selected(request('rule') == $rule->id)>{{ $rule->name }}</option>
                    @endforeach
                </select>
                @if (request('rule') || request('status'))
                    <a href="{{ route('admin.automations.runs') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>{{ __('When') }}</th>
                            <th>{{ __('Rule') }}</th>
                            <th>{{ __('What') }}</th>
                            <th>{{ __('Result') }}</th>
                            <th>{{ __('Details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($runs as $run)
                            <tr>
                                <td class="text-xs opacity-60 whitespace-nowrap">{{ $run->created_at?->format('Y-m-d H:i:s') }}</td>
                                <td class="text-xs">{{ $run->rule_name }}</td>
                                @php $ref = $refs[$run->subject][(int) ($run->subject_id ?? $run->order_id)] ?? null; @endphp
                                <td class="text-xs whitespace-nowrap">
                                    @if ($ref && $ref['url'])
                                        <a href="{{ $ref['url'] }}" class="link link-hover">{{ $ref['label'] }}</a>
                                    @else
                                        <span class="font-mono opacity-60">{{ $ref['label'] ?? ($run->subject_id ?? $run->order_id) }}</span>
                                    @endif
                                </td>
                                <td><span class="badge badge-sm {{ $run->badge() }}">{{ __($run->status) }}</span></td>
                                <td class="text-xs">
                                    {{ $run->summary }}
                                    @if ($run->detail)
                                        <details>
                                            <summary class="cursor-pointer opacity-60">{{ __('request and response') }}</summary>
                                            <div class="mt-1 max-w-2xl overflow-x-auto">
                                                <div class="font-mono opacity-70">
                                                    {{ $run->detail['method'] ?? '' }} {{ $run->detail['url'] ?? '' }}
                                                </div>
                                                @if (! empty($run->detail['request']))
                                                    <pre class="bg-base-200 p-2 rounded mt-1 whitespace-pre-wrap">{{ $run->detail['request'] }}</pre>
                                                @endif
                                                @if (! empty($run->detail['response']))
                                                    <pre class="bg-base-200 p-2 rounded mt-1 whitespace-pre-wrap">{{ $run->detail['response'] }}</pre>
                                                @endif
                                            </div>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center opacity-50 py-6">{{ __('Nothing has run yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $runs->links() }}
        </div>
    </div>
@endsection
