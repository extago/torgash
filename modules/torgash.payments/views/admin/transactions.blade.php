@extends('admin.layout')

@section('title', __('Transaction log'))

@section('content')
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Transaction log') }}</h1>
            <div class="text-xs opacity-60 mt-1">
                {{ __('Every message a gateway sent, verified or not, applied or not — including the ones ignored as duplicates or as arriving out of order. This is the evidence in a dispute.') }}
            </div>
        </div>
        <div class="flex gap-2">
            <form method="POST" action="{{ route('admin.payments.reconcile') }}">
                @csrf
                <button class="btn btn-sm">{{ __('Ask the gateways now') }}</button>
            </form>
            {{-- Back to where payments are set up, which is the payment methods
                 screen — this page has no sidebar entry of its own. --}}
            <a href="{{ route('admin.checkout.payment-methods.index') }}" class="btn btn-ghost btn-sm">
                {{ __('Payment methods') }}
            </a>
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>{{ __('When') }}</th>
                            <th>{{ __('Gateway') }}</th>
                            <th>{{ __('Order') }}</th>
                            <th>{{ __('State') }}</th>
                            <th>{{ __('Amount') }}</th>
                            <th>{{ __('What happened') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transactions as $transaction)
                            <tr>
                                <td class="text-xs opacity-60 whitespace-nowrap">{{ $transaction->created_at?->format('Y-m-d H:i:s') }}</td>
                                <td class="text-xs">{{ $transaction->gateway }}</td>
                                <td class="text-xs font-mono">{{ $transaction->order_id }}</td>
                                <td><span class="badge badge-sm {{ $transaction->badge() }}">{{ $transaction->state }}</span></td>
                                <td class="text-xs">{{ $transaction->amount !== null ? admin_price($transaction->amount) : '' }}</td>
                                <td class="text-xs">
                                    {{ $transaction->summary }}
                                    @if ($transaction->raw)
                                        <details>
                                            <summary class="cursor-pointer opacity-60">{{ __('raw') }}</summary>
                                            <pre class="bg-base-200 p-2 rounded mt-1 max-w-2xl whitespace-pre-wrap">{{ json_encode($transaction->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center opacity-50 py-6">{{ __('Nothing yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $transactions->links() }}
        </div>
    </div>
@endsection
