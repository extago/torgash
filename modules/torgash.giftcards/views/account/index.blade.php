{{--
    The customer's own gift cards: the balance of each and its history, and a
    box to add one by its code. A card added here can be spent only from this
    account — "forwarded to a colleague and both spend it" stops here.
--}}
@extends('layouts.storefront')

@section('title', __('Gift cards').' · '.current_storefront()?->name)

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex-1 flex flex-col gap-4 min-w-0">
            <h1 class="text-xl font-bold">{{ __('Gift cards') }}</h1>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('account.giftcards.attach') }}" class="card bg-base-100 shadow-sm border border-base-300/60">
                @csrf
                <div class="card-body gap-2">
                    <label class="font-medium" for="giftcard-code">{{ __('Add a gift card') }}</label>
                    <div class="join w-full max-w-md">
                        <input id="giftcard-code" name="code" value="{{ old('code') }}" required maxlength="40" autocomplete="off"
                               class="input input-bordered join-item w-full font-mono uppercase" placeholder="XXXX-XXXX-XXXX-XXXX">
                        <button class="btn btn-primary join-item">{{ __('Add') }}</button>
                    </div>
                    @error('code')
                        <p class="text-error text-sm">{{ $message }}</p>
                    @enderror
                    <p class="text-xs opacity-60">{{ __('Its balance is then yours to spend at checkout with one click, and nobody else can use the code.') }}</p>
                </div>
            </form>

            @forelse ($cards as $card)
                @php $cardState = $card->state(); @endphp
                <div class="card bg-base-100 shadow-sm border border-base-300/60">
                    <div class="card-body gap-3">
                        <div class="flex flex-wrap justify-between items-center gap-3">
                            <div class="flex items-center gap-3">
                                <x-icon name="gift" size="lg" class="opacity-60" />
                                <div>
                                    <div class="font-semibold font-mono">{{ $card->masked() }}</div>
                                    <div class="text-xs opacity-60">
                                        {{ __('of :amount', ['amount' => format_price((int) $card->initial_amount, shop_currency())]) }}
                                        @if ($card->expires_at) · {{ __('valid until :date', ['date' => $card->expires_at->format('d.m.Y')]) }} @endif
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="badge {{ \Torgash\Giftcards\Models\Card::stateBadge($cardState) }}">{{ \Torgash\Giftcards\Models\Card::stateLabel($cardState) }}</span>
                                <span class="text-xl font-semibold tabular-nums">{{ format_price((int) $card->balance, shop_currency()) }}</span>
                                <a href="{{ $card->publicUrl() }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Open the card') }}"><x-icon name="external-link" size="sm" /></a>
                            </div>
                        </div>

                        @if ($card->entries->isNotEmpty())
                            <details>
                                <summary class="text-sm cursor-pointer opacity-70">{{ __('History') }}</summary>
                                <table class="table table-sm mt-2">
                                    <tbody>
                                        @foreach ($card->entries as $entry)
                                            <tr>
                                                <td class="opacity-60 whitespace-nowrap">{{ $entry->created_at?->format('d.m.Y') }}</td>
                                                <td>{{ $entry->typeLabel() }}@if ($entry->order_id) · <span class="opacity-60">#{{ $entry->order_id }}</span>@endif</td>
                                                <td class="text-right tabular-nums {{ $entry->amount < 0 ? '' : 'text-success' }}">
                                                    {{ $entry->amount < 0 ? '−' : '+' }}{{ format_price(abs($entry->amount), shop_currency()) }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </details>
                        @endif
                    </div>
                </div>
            @empty
                <div class="card bg-base-100 shadow-sm border border-base-300/60">
                    <div class="card-body text-center opacity-60 py-10">{{ __('No gift cards in your account yet.') }}</div>
                </div>
            @endforelse
        </div>
    </div>
@endsection
