@extends('admin.layout')
@use('Torgash\Giftcards\Models\Card')

@section('title', __('Gift cards'))
@section('title_hint', __('Money customers paid in advance, spent in the same box as a coupon. A card is sold as a product, issued here by hand, or printed in a batch and activated at the till.'))

@section('content')
    @php
        $stateTabs = [
            '' => __('All'),
            'active' => Card::stateLabel('active'),
            'pending' => Card::stateLabel('pending'),
            'redeemed' => Card::stateLabel('redeemed'),
            'expired' => Card::stateLabel('expired'),
            'disabled' => Card::stateLabel('disabled'),
        ];
        $isFiltered = $filters['q'] !== '' || $filters['state'] !== '' || $filters['batch'] !== '';
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's action at
         its end (app.css, `admin-tabs`); LINKS carrying every other filter. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Gift cards') }}">
            @foreach ($stateTabs as $stateKey => $stateLabel)
                <a href="{{ request()->fullUrlWithQuery(['state' => $stateKey ?: null, 'page' => null]) }}"
                   @class(['admin-tab', 'is-active' => $filters['state'] === $stateKey])>
                    {{ \Illuminate\Support\Str::ucfirst($stateLabel) }} <span class="admin-tab-count">{{ $stateCounts[$stateKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.giftcards.create') }}" class="btn btn-primary">+ {{ __('Issue cards') }}</a>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300" x-data="{ copied: '' }">
        <div class="card-body gap-4">
            <p class="text-sm">
                {{ __('Still owed on working cards') }}: <span class="font-semibold tabular-nums">{{ admin_price($outstanding) }}</span>
            </p>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Code, last four, email, order…') }}">
                </label>
                @if ($batches->isNotEmpty())
                    <select name="batch" class="select select-sm w-auto" data-submit-on-change>
                        <option value="">{{ __('Every batch') }}</option>
                        @foreach ($batches as $batchName)
                            <option value="{{ $batchName }}" @selected($filters['batch'] === $batchName)>{{ $batchName }}</option>
                        @endforeach
                    </select>
                @endif
                <input type="hidden" name="state" value="{{ $filters['state'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.giftcards.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
                @if ($filters['batch'] !== '')
                    <a href="{{ route('admin.giftcards.export', ['batch' => $filters['batch']]) }}" class="btn btn-sm gap-1.5 ms-auto">
                        <x-icon name="download" size="sm" /> {{ __('Download the codes') }}
                    </a>
                @endif
            </form>

            @if ($cards->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="gift" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $isFiltered ? __('No gift cards match this search.') : __('No gift cards yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Card') }}</th>
                                <th class="text-end">{{ __('Balance') }}</th>
                                <th>{{ __('Whose') }}</th>
                                <th>{{ __('Where from') }}</th>
                                <th>{{ __('Valid until') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cards as $card)
                                @php $cardState = $card->state(); @endphp
                                <tr class="hover:bg-base-200/50">
                                    <td class="whitespace-nowrap">
                                        <a href="{{ route('admin.giftcards.show', $card) }}" @class(['font-mono font-semibold link link-hover', 'opacity-60' => $cardState !== 'active'])>{{ $card->masked() }}</a>
                                    </td>
                                    <td class="text-end tabular-nums whitespace-nowrap">
                                        {{ admin_price((int) $card->balance) }}
                                        <div class="text-xs opacity-50">{{ __('of :amount', ['amount' => admin_price((int) $card->initial_amount)]) }}</div>
                                    </td>
                                    <td class="text-sm">
                                        {{ $card->recipient_name ?: $card->recipient_email ?: '—' }}
                                        @if ($card->owner_user_id)
                                            <div class="text-xs opacity-60 flex items-center gap-1"><x-icon name="user-round" size="sm" /> {{ __('in an account') }}</div>
                                        @endif
                                    </td>
                                    <td class="text-sm whitespace-nowrap">
                                        @if ($card->source === 'sold')
                                            {{ __('sold') }}
                                        @elseif ($card->source === 'batch')
                                            {{ __('batch') }} <span class="opacity-60">{{ $card->batch }}</span>
                                        @else
                                            {{ __('issued by hand') }}
                                        @endif
                                    </td>
                                    <td class="text-sm whitespace-nowrap">{{ $card->expires_at?->format('d.m.Y') ?? __('no limit') }}</td>
                                    <td><span class="badge badge-sm {{ Card::stateBadge($cardState) }}">{{ Card::stateLabel($cardState) }}</span></td>
                                    <td class="text-end whitespace-nowrap">
                                        <a href="{{ $card->publicUrl() }}" target="_blank" class="btn btn-ghost btn-sm btn-square" title="{{ __('The card\'s page') }}" aria-label="{{ __('The card\'s page') }}">
                                            <x-icon name="external-link" />
                                        </a>
                                        <a href="{{ route('admin.giftcards.show', $card) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                            <x-icon name="pencil" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $cards->links() }}
            @endif
        </div>
    </div>
@endsection
