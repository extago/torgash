@extends('admin.layout')

@section('title', __('Orders in the 1C exchange'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @include('torgash.commerceml::admin._nav')

    <form method="POST" action="{{ route('admin.commerceml.orders.save') }}" class="flex flex-col gap-4">
        @csrf

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div>
                    <h3 class="font-semibold">{{ __('When an order goes to 1C') }}</h3>
                    <div class="text-xs opacity-60">
                        {{ __('There is no right answer for every shop: one writes goods off when the money arrives, another when a manager confirms the order, a third keeps 1C for the paperwork alone. Say which of the two things has to be true here — an order is sent again whenever anything on it changes, and never sent twice for nothing.') }}
                    </div>
                </div>

                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="hidden" name="sale_enabled" value="0">
                    <input type="checkbox" name="sale_enabled" value="1" class="checkbox checkbox-sm" @checked($enabled)>
                    <span class="label-text">{{ __('Hand orders over when 1C asks for them') }}</span>
                </label>

                <div class="grid gap-4 md:grid-cols-2">
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('In one of these statuses') }}</legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            @foreach ($statuses as $status)
                                <label class="label cursor-pointer justify-start gap-2 p-0">
                                    <input type="checkbox" name="sale_statuses[]" value="{{ $status->code }}"
                                           class="checkbox checkbox-sm" @checked(in_array($status->code, $selected, true))>
                                    <span class="label-text text-sm inline-flex items-center gap-1">
                                        <span class="inline-block w-2 h-2 rounded-full" style="background: {{ $status->color }}"></span>
                                        {{ $status->t('name') }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="flex flex-col gap-3">
                        <label class="label cursor-pointer justify-start gap-2 p-0">
                            <input type="hidden" name="sale_paid" value="0">
                            <input type="checkbox" name="sale_paid" value="1" class="checkbox checkbox-sm" @checked($paid)>
                            <span class="label-text">{{ __('Or paid for, whatever its status') }}</span>
                        </label>

                        <fieldset class="fieldset p-0 max-w-xs">
                            <legend class="fieldset-legend">{{ __('Both conditions or either') }}</legend>
                            <select name="sale_match" class="select select-bordered w-full">
                                <option value="any" @selected($match === 'any')>{{ __('Either is enough') }}</option>
                                <option value="all" @selected($match === 'all')>{{ __('Both must be true') }}</option>
                            </select>
                        </fieldset>

                        <fieldset class="fieldset p-0 max-w-xs">
                            <legend class="fieldset-legend">{{ __('Nothing placed before') }}</legend>
                            <input type="date" name="sale_from" value="{{ $from }}" class="input input-bordered w-full">
                            <div class="text-xs opacity-50">{{ __('Leave empty to include the whole order history — which is what a shop connecting 1C to two years of orders rarely wants on the first night.') }}</div>
                        </fieldset>
                    </div>
                </div>

                @if (empty($selected) && ! $paid)
                    <div class="alert alert-warning text-sm">
                        {{ __('Nothing qualifies, so nothing will be sent. That is deliberate: an unanswered question is not the same as "send everything".') }}
                    </div>
                @endif
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div>
                    <h3 class="font-semibold">{{ __('What 1C may change') }}</h3>
                    <div class="text-xs opacity-60">
                        {{ __('The status, and the fact that the money arrived. Not the contents and not the prices: 1C legitimately holds a document with different lines on it, and importing those would silently change the total of an order the customer has already agreed to. Anything more elaborate is a rule in Automations, where it is written down and logged.') }}
                    </div>
                </div>

                <fieldset class="fieldset p-0 max-w-sm">
                    <legend class="fieldset-legend">{{ __('Shipped in 1C means') }}</legend>
                    <select name="sale_status_shipped" class="select select-bordered w-full">
                        <option value="">{{ __('— do not move the status') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->code }}" @selected($shipped === $status->code)>{{ $status->t('name') }}</option>
                        @endforeach
                    </select>
                    <div class="text-xs opacity-50">
                        {{ __('Which attribute reports the shipment is a setting, beside the other 1C attribute names — every installation spells it differently.') }}
                    </div>
                </fieldset>
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Two things 1C insists on') }}</h3>

                <fieldset class="fieldset p-0 max-w-sm">
                    <legend class="fieldset-legend">{{ __('Made-up email domain') }}</legend>
                    <input name="sale_guest_domain" value="{{ $guestDomain }}" class="input input-bordered w-full" placeholder="orders.example.com">
                    <div class="text-xs opacity-50">
                        {{ __('A counterparty card in 1C wants an address, and an order bought in one click has none. With a domain here the export makes one up from the buyer’s own reference and this domain; the shop still records, truthfully, that it has no email for that person. Empty means send no address at all.') }}
                    </div>
                </fieldset>

                <fieldset class="fieldset p-0 max-w-sm">
                    <legend class="fieldset-legend">{{ __('Delivery service identifier') }}</legend>
                    <input name="sale_delivery_guid" value="{{ $deliveryGuid }}" class="input input-bordered w-full">
                    <div class="text-xs opacity-50">
                        {{ __('Delivery goes into the document as a service line, or the lines do not add up to the total. This is the fallback identifier; a delivery method can carry its own on its own page, which is what a shop with courier delivery in 1C and a parcel carrier outside it needs.') }}
                    </div>
                </fieldset>
            </div>
        </div>

        <div>
            <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
        </div>
    </form>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body">
            <h3 class="font-semibold">{{ __('What has passed') }}</h3>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>{{ __('Order') }}</th>
                            <th>{{ __('Offered') }}</th>
                            <th>{{ __('Taken by 1C') }}</th>
                            <th>{{ __('1C says') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recent as $row)
                            <tr>
                                <td class="whitespace-nowrap">
                                    @if ($row->order)
                                        <a href="{{ route('admin.orders.show', $row->order) }}" class="link link-primary">{{ $row->order->order_number }}</a>
                                    @else
                                        <span class="opacity-50">#{{ $row->order_id }}</span>
                                    @endif
                                </td>
                                <td class="text-xs opacity-70">{{ $row->offered_at?->format('Y-m-d H:i') }}</td>
                                <td class="text-xs {{ $row->confirmed_at ? 'opacity-70' : 'text-warning' }}">
                                    {{ $row->confirmed_at?->format('Y-m-d H:i') ?: __('not yet') }}
                                </td>
                                <td class="text-xs opacity-70">
                                    {{ $row->answer }}
                                    @if ($row->answered_at)
                                        <span class="opacity-50">· {{ $row->answered_at->format('Y-m-d H:i') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center opacity-60 py-6">
                                    {{ __('No order has been handed over yet.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
