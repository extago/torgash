{{-- The customer's points: the balance, what is waiting for orders on their
     way, and every movement. --}}
@extends('layouts.storefront')

@section('title', __('Bonus points').' · '.current_storefront()?->name)

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex-1 flex flex-col gap-4 min-w-0">
            <h1 class="text-xl font-bold">{{ __('Bonus points') }}</h1>

            <div class="grid sm:grid-cols-2 gap-4">
                <div class="card bg-base-100 shadow-sm border border-base-300/60">
                    <div class="card-body gap-1">
                        <div class="text-sm opacity-60">{{ __('You have') }}</div>
                        <div class="text-3xl font-bold tabular-nums">{{ number_format($balance, 0, '.', ' ') }}</div>
                        <div class="text-xs opacity-60">{{ __('Worth :amount — spend them in the basket.', ['amount' => format_price($balance * $pointValue)]) }}</div>
                    </div>
                </div>
                <div class="card bg-base-100 shadow-sm border border-base-300/60">
                    <div class="card-body gap-1">
                        <div class="text-sm opacity-60">{{ __('Waiting to be credited') }}</div>
                        <div class="text-3xl font-bold tabular-nums opacity-70">{{ number_format($waiting, 0, '.', ' ') }}</div>
                        <div class="text-xs opacity-60">{{ __('For orders on their way — credited when they are completed.') }}</div>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 shadow-sm border border-base-300/60">
                @if ($entries->isEmpty())
                    <div class="card-body text-center opacity-60 py-10">{{ __('No points yet — every order earns some.') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <tbody>
                                @foreach ($entries as $entry)
                                    <tr>
                                        <td class="opacity-60 whitespace-nowrap">{{ $entry->created_at?->format('d.m.Y') }}</td>
                                        <td>
                                            {{ $entry->typeLabel() }}@if ($entry->order_id && isset($orders[$entry->order_id])) · <span class="opacity-60">{{ $orders[$entry->order_id] }}</span>@endif
                                            @if ($entry->note)
                                                <div class="text-xs opacity-60">{{ $entry->note }}</div>
                                            @endif
                                            @if ($entry->expires_at && $entry->points > 0)
                                                <div class="text-xs opacity-50">{{ __('until :date', ['date' => $entry->expires_at->format('d.m.Y')]) }}</div>
                                            @endif
                                        </td>
                                        <td class="text-right tabular-nums whitespace-nowrap {{ $entry->points > 0 ? 'text-success' : '' }}">
                                            {{ $entry->points > 0 ? '+' : '−' }}{{ number_format(abs($entry->points), 0, '.', ' ') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $entries->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
