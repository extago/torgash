@extends('admin.layout')
@use('Torgash\Giftcards\Models\Card')

@section('title', __('Gift card').' '.$card->masked())
@section('own_heading', '1')

@section('content')
    @php $cardState = $card->state(); @endphp

    {{-- The actions are forms of their own BESIDE the card's form, reached by
         `form=` — a form inside a form is dropped by the parser and its inputs
         ride out on the wrong submit. --}}
    <form id="card-activate" method="POST" action="{{ route('admin.giftcards.activate', $card) }}" class="hidden">@csrf</form>
    <form id="card-send" method="POST" action="{{ route('admin.giftcards.send', $card) }}" class="hidden">@csrf</form>
    <form id="card-status" method="POST" action="{{ route('admin.giftcards.status', $card) }}" class="hidden">
        @csrf
        <input type="hidden" name="enabled" value="{{ $card->status === Card::DISABLED ? 1 : 0 }}">
    </form>

    <form id="card-form" method="POST" action="{{ route('admin.giftcards.update', $card) }}" class="flex flex-col gap-4">
        @csrf
        @method('PUT')

        <x-admin.form-bar>
            <a href="{{ route('admin.giftcards.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to gift cards') }}" aria-label="{{ __('Back to gift cards') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Gift card') }}</div>
                <div class="font-semibold truncate leading-tight font-mono flex items-center gap-2">
                    {{ $card->masked() }}
                    <span class="badge badge-sm {{ Card::stateBadge($cardState) }} font-sans">{{ Card::stateLabel($cardState) }}</span>
                </div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-64 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    <li><a href="{{ $card->publicUrl() }}" target="_blank"><x-icon name="external-link" /> {{ __('The card\'s page') }}</a></li>
                    @if ($card->recipient_email)
                        <li><button type="submit" form="card-send"><x-icon name="send" /> {{ $card->delivered_at ? __('Send the letter again') : __('Send the letter now') }}</button></li>
                    @endif
                    @if ($card->status === Card::PENDING)
                        <li><button type="submit" form="card-activate"><x-icon name="badge-check" /> {{ __('Activate — handed over') }}</button></li>
                    @endif
                    <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                    <li>
                        <button type="submit" form="card-status" @class(['text-error' => $card->status !== Card::DISABLED])>
                            <x-icon name="power" /> {{ $card->status === Card::DISABLED ? __('Switch on') : __('Switch off') }}
                        </button>
                    </li>
                </ul>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.giftcards.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <div class="flex flex-wrap gap-6">
                            <div>
                                <div class="text-xs opacity-60">{{ __('Balance') }}</div>
                                <div class="text-3xl font-bold tabular-nums">{{ admin_price((int) $card->balance) }}</div>
                                <div class="text-xs opacity-60">{{ __('of :amount', ['amount' => admin_price((int) $card->initial_amount)]) }}</div>
                            </div>
                            <div x-data="{ code: '' }" class="flex flex-col gap-1">
                                <div class="text-xs opacity-60">{{ __('Code') }}</div>
                                <div class="font-mono text-lg" x-show="code" x-text="code" x-cloak></div>
                                <button type="button" class="btn btn-sm btn-outline gap-1.5 w-fit" x-show="!code"
                                        @click="fetch(@js(route('admin.giftcards.code', $card)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(r => r.text()).then(t => code = t)">
                                    <x-icon name="eye" size="sm" /> {{ __('Show the code') }}
                                </button>
                            </div>
                            <div class="text-sm flex flex-col gap-0.5">
                                <div class="text-xs opacity-60">{{ __('Where from') }}</div>
                                @if ($card->source === 'sold' && $card->order_id)
                                    <div>{{ __('sold') }}:
                                        @php $soldOrder = $orders->get($card->order_id); @endphp
                                        @if ($soldOrder)
                                            <a href="{{ route('admin.orders.show', $soldOrder) }}" class="link">{{ $soldOrder->order_number }}</a>
                                        @else
                                            #{{ $card->order_id }}
                                        @endif
                                    </div>
                                @elseif ($card->source === 'batch')
                                    <div>{{ __('batch') }} <a href="{{ route('admin.giftcards.index', ['batch' => $card->batch]) }}" class="link">{{ $card->batch }}</a></div>
                                @else
                                    <div>{{ __('issued by hand') }}</div>
                                @endif
                                <div class="opacity-60">{{ $card->created_at?->format('d.m.Y H:i') }}</div>
                                @if ($card->owner)
                                    <div class="flex items-center gap-1"><x-icon name="user-round" size="sm" /> {{ $card->owner->name }} <span class="opacity-60">{{ $card->owner->email }}</span></div>
                                @endif
                            </div>
                        </div>

                        <fieldset class="fieldset p-0 max-w-xs"><legend class="fieldset-legend">{{ __('Valid until') }}</legend>
                            <input name="expires_at" type="date" class="input input-bordered w-full" value="{{ old('expires_at', $card->expires_at?->toDateString()) }}">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Empty — no limit. Moving it forward revives an expired card.') }}</p>
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('History') }}</h3>
                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <tbody>
                                    @foreach ($card->entries as $entry)
                                        <tr>
                                            <td class="opacity-60 whitespace-nowrap">{{ $entry->created_at?->format('d.m.Y H:i') }}</td>
                                            <td>
                                                {{ $entry->typeLabel() }}
                                                @if ($entry->order_id)
                                                    @php $entryOrder = $orders->get($entry->order_id); @endphp
                                                    · @if ($entryOrder)<a href="{{ route('admin.orders.show', $entryOrder) }}" class="link">{{ $entryOrder->order_number }}</a>@else #{{ $entry->order_id }} @endif
                                                @endif
                                                @if ($entry->note)
                                                    <div class="text-xs opacity-60">{{ $entry->note }}</div>
                                                @endif
                                            </td>
                                            <td class="text-xs opacity-60">{{ $entry->user_name }}</td>
                                            <td class="text-end tabular-nums whitespace-nowrap {{ $entry->amount < 0 ? '' : 'text-success' }}">
                                                {{ $entry->amount < 0 ? '−' : '+' }}{{ admin_price(abs($entry->amount)) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('For whom') }}</h3>
                        <div class="grid md:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Recipient\'s name') }}</legend>
                                <input name="recipient_name" maxlength="190" class="input input-bordered w-full" value="{{ old('recipient_name', $card->recipient_name) }}">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Recipient\'s email') }}</legend>
                                <input name="recipient_email" type="email" maxlength="190" class="input input-bordered w-full" value="{{ old('recipient_email', $card->recipient_email) }}">
                                <p class="label text-xs opacity-60 whitespace-normal">
                                    {{ $card->delivered_at ? __('Sent on :date.', ['date' => $card->delivered_at->format('d.m.Y H:i')]) : __('Not sent yet.') }}
                                </p>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('From') }}</legend>
                                <input name="sender_name" maxlength="190" class="input input-bordered w-full" value="{{ old('sender_name', $card->sender_name) }}">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Send on') }}</legend>
                                <input name="deliver_at" type="datetime-local" class="input input-bordered w-full" value="{{ old('deliver_at', $card->deliver_at?->format('Y-m-d\TH:i')) }}">
                            </fieldset>
                        </div>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Message') }}</legend>
                            <textarea name="message" rows="3" maxlength="500" class="textarea textarea-bordered w-full">{{ old('message', $card->message) }}</textarea>
                        </fieldset>
                    </div>
                </section>

                @include('torgash.giftcards::admin._rules', ['giftRules' => (array) ($card->rules ?? [])])
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Correct the balance') }}</h3>
                        <div class="join w-full">
                            <select name="direction" form="card-adjust" class="select select-bordered join-item">
                                <option value="add">+</option>
                                <option value="take">−</option>
                            </select>
                            <input name="amount" form="card-adjust" type="number" step="0.01" min="0.01" class="input input-bordered join-item w-full" placeholder="0.00">
                        </div>
                        <input name="reason" form="card-adjust" maxlength="500" class="input input-bordered w-full" placeholder="{{ __('Why — required, the customer sees it in the history') }}">
                        <button type="submit" form="card-adjust" class="btn btn-sm">{{ __('Correct') }}</button>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Note') }}</h3>
                        <textarea name="note" rows="4" maxlength="1000" class="textarea textarea-bordered w-full">{{ old('note', $card->note) }}</textarea>
                    </div>
                </section>
            </div>
        </div>
    </form>

    <form id="card-adjust" method="POST" action="{{ route('admin.giftcards.adjust', $card) }}" class="hidden">@csrf</form>
@endsection
