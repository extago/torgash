@extends('admin.layout')

@section('title', __('Verification codes'))

{{-- Every code the shop sent (App\Http\Controllers\Admin\VerificationCodesController). --}}
@section('content')
    @php
        $statusLabels = [
            'sent' => [__('Sent'), 'badge-info'],
            'verified' => [__('Confirmed'), 'badge-success'],
            'invalid_address' => [__('Wrong address'), 'badge-error'],
            'unreachable' => [__('Could not reach'), 'badge-warning'],
            'temporary' => [__('Failed to send'), 'badge-error'],
            'pending' => [__('Not sent'), 'badge-ghost'],
        ];
        $tabs = ['' => __('All')] + array_map(fn ($s) => $s[0], $statusLabels);
    @endphp

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <p class="text-sm opacity-70 max-w-3xl">
                {{ __('Every code the shop sent to prove an address — to sign in, to restore access — by which channel, and how it ended. This is where "the code never came" is answered. The digits themselves are never kept. Rows older than a month are removed.') }}
            </p>

            @if ($today->isNotEmpty())
                <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-3">
                    @foreach ($today as $day)
                        <div class="rounded-box bg-base-200/60 px-4 py-3">
                            <div class="text-xs opacity-60">{{ __('Today') }} · {{ isset($channels[$day->channel]) ? __($channels[$day->channel]->label()) : $day->channel }}</div>
                            <div class="text-xl font-bold tabular-nums">{{ (int) $day->sends }}</div>
                            <div class="text-xs opacity-60">
                                {{ __(':verified confirmed, :failed not delivered', ['verified' => (int) $day->verified, 'failed' => (int) $day->failed]) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-80 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $search }}" class="grow" placeholder="{{ __('Search by address…') }}">
                </label>
                <input type="hidden" name="status" value="{{ $status }}">
                @if ($search !== '' || $status)
                    <a href="{{ route('admin.verification-codes') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="join flex-wrap -mt-1">
                @foreach ($tabs as $tabKey => $tabLabel)
                    <a href="{{ request()->fullUrlWithQuery(['status' => $tabKey ?: null, 'page' => null]) }}"
                       @class(['btn btn-sm join-item', 'btn-active' => (string) $status === (string) $tabKey])>
                        {{ $tabLabel }} <span class="opacity-50">{{ $tabKey === '' ? $counts->sum() : ($counts[$tabKey] ?? 0) }}</span>
                    </a>
                @endforeach
            </div>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>{{ __('When') }}</th>
                            <th>{{ __('Address') }}</th>
                            <th>{{ __('What for') }}</th>
                            <th>{{ __('Channel') }}</th>
                            <th class="text-right">{{ __('Times sent') }}</th>
                            <th class="text-right">{{ __('Wrong tries') }}</th>
                            <th>{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($codes as $row)
                            @php [$statusLabel, $statusClass] = $statusLabels[$row->status] ?? [$row->status, 'badge-ghost']; @endphp
                            <tr>
                                <td class="whitespace-nowrap text-sm">{{ $row->created_at->isoFormat('D MMM, HH:mm') }}</td>
                                <td>
                                    <div class="font-mono text-sm break-all">{{ $row->address }}</div>
                                    @if ($row->user)
                                        <div class="text-xs opacity-60">{{ $row->user->name }}</div>
                                    @endif
                                </td>
                                <td class="text-sm">{{ __($purposes[$row->purpose] ?? $row->purpose) }}</td>
                                <td class="text-sm">{{ isset($channels[$row->channel]) ? __($channels[$row->channel]->label()) : $row->channel }}</td>
                                <td class="text-right tabular-nums">{{ $row->sends }}</td>
                                <td class="text-right tabular-nums">{{ $row->attempts }}</td>
                                <td>
                                    @php $answer = \App\Http\Controllers\Admin\VerificationCodesController::answer($row->error); @endphp
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="badge badge-sm {{ $statusClass }} whitespace-nowrap">{{ $statusLabel }}</span>
                                        @if ($row->error)
                                            <x-modal :title="__('Provider\'s answer')" icon="file-json" size="lg"
                                                     :trigger="__('Response')" trigger-icon="chevron-right" trigger-class="btn btn-ghost btn-sm gap-0.5 opacity-70">
                                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm mb-3">
                                                    <span><span class="opacity-60">{{ __('Channel') }}:</span> {{ isset($channels[$row->channel]) ? __($channels[$row->channel]->label()) : $row->channel }}</span>
                                                    <span><span class="opacity-60">{{ __('Address') }}:</span> <span class="font-mono">{{ $row->address }}</span></span>
                                                    @if ($answer['http'])
                                                        <span><span class="opacity-60">HTTP:</span> <span class="font-mono">{{ $answer['http'] }}</span></span>
                                                    @endif
                                                </div>
                                                <pre class="rounded-box bg-base-200 p-3 text-xs overflow-auto max-h-96 whitespace-pre-wrap break-all">{{ $answer['body'] }}</pre>
                                            </x-modal>
                                        @endif
                                    </div>
                                    @if ($answer['short'] && ! in_array($row->status, ['sent', 'verified'], true))
                                        <div class="text-xs opacity-70 mt-1 font-mono break-all">{{ $answer['short'] }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center opacity-60 py-8">{{ __('No codes yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $codes->links() }}
        </div>
    </div>
@endsection
