{{-- The CDEK card on the order screen (`checkout.order.side_panels`), and the
     answer to every action in it — the card swaps itself in place. --}}
@php
    $cdekDetails = (array) ($order->shipping_details ?? []);
    $cdekRub = app(\App\Core\Support\Currencies::class)->find('RUB');
    $cdekRoubles = fn (float $amount) => $cdekRub ? $cdekRub->format($amount) : number_format($amount, 2, '.', ' ').' RUB';
    $cdekOld = $waybillOld ?? [];
    $cdekPast = $history->filter(fn ($row) => ! $waybill || $row->id !== $waybill->id);
@endphp
<section id="cdek-waybill-panel" class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3">
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold flex items-center gap-1.5"><x-icon name="truck" size="sm" class="opacity-60" />{{ __('CDEK waybill') }}</h3>
            <span class="text-xs opacity-60">{{ __('tariff :code', ['code' => $cdekDetails['tariff_code'] ?? '—']) }}</span>
        </div>

        <div class="text-sm opacity-70">
            @if (($cdekDetails['delivery'] ?? 'point') === 'point')
                {{ $cdekDetails['point_address'] ?? '' }}
                <span class="badge badge-ghost badge-sm">{{ $cdekDetails['point_code'] ?? '—' }}</span>
            @else
                {{ __('By courier') }}: {{ $cdekDetails['address'] ?? '—' }}
            @endif
        </div>

        @if ($waybillError)
            <div class="alert alert-error text-sm py-2">{{ $waybillError }}</div>
        @endif

        @if ($waybill)
            <div class="flex flex-col gap-1 text-sm">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="opacity-60">{{ __('CDEK number') }}</span>
                    @if ($waybill->cdek_number)
                        <a href="{{ $waybill->trackingUrl() }}" target="_blank" rel="noopener" class="link font-mono font-semibold">{{ $waybill->cdek_number }}</a>
                    @else
                        {{-- CDEK registers an order a little while after it
                             accepts it; until then there is no number to show
                             or print. --}}
                        <span class="opacity-70">{{ __('being registered…') }}</span>
                    @endif
                </div>
                <div class="flex items-baseline justify-between gap-2">
                    <span class="opacity-60">{{ __('Status') }}</span>
                    <span class="text-end">
                        {{ $waybill->status_name ?? '—' }}
                        @if ($waybill->status_at)
                            <span class="block text-xs opacity-50">{{ $waybill->status_at->diffForHumans() }}</span>
                        @endif
                    </span>
                </div>
                <div class="flex items-baseline justify-between gap-2">
                    <span class="opacity-60">{{ __('Collect on delivery') }}</span>
                    <span>{{ $waybill->cod_kopecks > 0 ? $cdekRoubles($waybill->cod_kopecks / 100) : __('no, prepaid') }}</span>
                </div>
                <div class="text-xs opacity-50">{{ __('Our number') }}: {{ $waybill->number }}</div>
            </div>

            <div class="flex flex-wrap gap-2">
                {{-- POST, in a new tab: asking makes CDEK draw the form. --}}
                @foreach (['waybill' => __('Print the waybill'), 'label' => __('Print the label')] as $cdekKind => $cdekLabel)
                    <form method="POST" action="{{ route('admin.cdek.waybills.print', [$order, $waybill, $cdekKind]) }}" target="_blank">
                        @csrf
                        <button class="btn btn-sm {{ $cdekKind === 'waybill' ? 'btn-primary' : '' }}" @disabled(! $waybill->isPrintable())>
                            <x-icon :name="$cdekKind === 'waybill' ? 'printer' : 'barcode'" size="sm" /> {{ $cdekLabel }}
                        </button>
                    </form>
                @endforeach
                <button type="button" class="btn btn-ghost btn-sm"
                        hx-post="{{ route('admin.cdek.waybills.refresh', [$order, $waybill]) }}"
                        hx-target="#cdek-waybill-panel" hx-swap="outerHTML">
                    <x-icon name="refresh-cw" size="sm" /> {{ __('Refresh') }}
                </button>
                @if ($waybill->isCancellable())
                    <button type="button" class="btn btn-ghost btn-sm text-error"
                            hx-delete="{{ route('admin.cdek.waybills.cancel', [$order, $waybill]) }}"
                            hx-target="#cdek-waybill-panel" hx-swap="outerHTML"
                            hx-confirm="{{ __('Cancel this waybill at CDEK? A new one can be made afterwards.') }}">
                        {{ __('Cancel the waybill') }}
                    </button>
                @endif
            </div>

            @if ($waybill->statuses)
                <details class="text-xs">
                    <summary class="cursor-pointer opacity-60">{{ __('Status history') }}</summary>
                    <ul class="mt-1 flex flex-col gap-0.5">
                        @foreach ($waybill->statuses as $cdekStatus)
                            <li class="flex justify-between gap-2">
                                <span>{{ $cdekStatus['name'] ?? $cdekStatus['code'] ?? '' }}@if (! empty($cdekStatus['city'])) <span class="opacity-50">· {{ $cdekStatus['city'] }}</span>@endif</span>
                                <span class="opacity-50 whitespace-nowrap">{{ isset($cdekStatus['date_time']) ? \Illuminate\Support\Carbon::parse($cdekStatus['date_time'])->timezone(config('app.timezone'))->format('d.m H:i') : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @else
            @php $cdekRefused = $history->firstWhere('state', \Torgash\Cdek\Models\Waybill::INVALID); @endphp
            @if ($cdekRefused && $cdekRefused->errors && ! $waybillError)
                {{-- The last attempt CDEK accepted and then refused: why, so
                     the next one can be put right. --}}
                <div class="alert alert-warning text-sm py-2 flex-col items-start gap-1">
                    <span class="font-medium">{{ __('CDEK refused the last waybill:') }}</span>
                    @foreach ($cdekRefused->errors as $cdekRefusal)
                        <span>{{ $cdekRefusal }}</span>
                    @endforeach
                </div>
            @endif

            <form class="flex flex-col gap-2"
                  hx-post="{{ route('admin.cdek.waybills.store', $order) }}"
                  hx-target="#cdek-waybill-panel" hx-swap="outerHTML">
                <div class="grid grid-cols-4 gap-2">
                    <label class="flex flex-col gap-1 col-span-4">
                        <span class="text-xs opacity-60">{{ __('Weight, g') }}</span>
                        <input type="number" name="weight_g" min="1" required class="input input-bordered input-sm w-full"
                               value="{{ $cdekOld['weight_g'] ?? $draft['weight_g'] }}">
                    </label>
                    @foreach (['length_cm' => __('Length, cm'), 'width_cm' => __('Width, cm'), 'height_cm' => __('Height, cm')] as $cdekField => $cdekLabel)
                        <label class="flex flex-col gap-1 {{ $loop->first ? 'col-span-2' : '' }}">
                            <span class="text-xs opacity-60">{{ $cdekLabel }}</span>
                            <input type="number" name="{{ $cdekField }}" min="1" required class="input input-bordered input-sm w-full"
                                   value="{{ $cdekOld[$cdekField] ?? $draft[$cdekField] }}">
                        </label>
                    @endforeach
                </div>

                <label class="label cursor-pointer justify-start gap-2 p-0 text-sm">
                    <input type="hidden" name="cod" value="0">
                    <input type="checkbox" name="cod" value="1" class="checkbox checkbox-primary"
                           @checked((bool) ($cdekOld['cod'] ?? $draft['cod'])) @disabled(($draft['due'] ?? 0) <= 0)>
                    <span>
                        {{ __('Collect payment on delivery') }}
                        <span class="block text-xs opacity-60">
                            @if ($draft['due'] === null)
                                {{ __('The shop has no rouble rate, so nothing can be collected.') }}
                            @elseif ($draft['due'] > 0)
                                {{ __('Still owed: :sum', ['sum' => $cdekRoubles($draft['due'])]) }}
                            @else
                                {{ __('The order is paid.') }}
                            @endif
                        </span>
                        @if (setting('torgash.cdek', 'test_mode', false))
                            {{-- Measured: CDEK's public test account takes an
                                 order with cash on delivery and never numbers
                                 it, so it cannot be printed. --}}
                            <span class="block text-xs text-warning">{{ __('The test account cannot collect on delivery: such a waybill is never numbered. Untick it to try printing.') }}</span>
                        @endif
                    </span>
                </label>

                <input name="comment" maxlength="255" class="input input-bordered input-sm w-full"
                       placeholder="{{ __('Comment for CDEK (optional)') }}" value="{{ $cdekOld['comment'] ?? '' }}">

                <button class="btn btn-primary btn-sm">
                    <x-icon name="file-plus" size="sm" /> {{ __('Create the waybill') }}
                </button>
            </form>
        @endif

        @if ($cdekPast->isNotEmpty())
            <div class="text-xs opacity-60 border-t border-base-300 pt-2">
                {{ __('Earlier') }}:
                {{ $cdekPast->map(fn ($row) => ($row->cdek_number ?: $row->number).' — '.match ($row->state) {
                    \Torgash\Cdek\Models\Waybill::CANCELLED => __('cancelled'),
                    \Torgash\Cdek\Models\Waybill::INVALID => __('refused'),
                    default => $row->status_name ?? $row->state,
                })->implode('; ') }}
            </div>
        @endif
    </div>
</section>
