{{--
    The bulk "Change…" dialog. It stands INSIDE the bulk form (a dialog is not
    a form of its own), so its fields ride the same POST as the ticked ids or
    the "all found" filters. Every field starts at "leave as it is": only what
    is filled in is applied.

    The example line under the prices is worked out here in the browser from a
    sample number only — the real arithmetic is BulkEdit's, per product, in
    that product's own currency.
--}}
@php
    $bulkNumberModes = [
        '' => __('Leave as it is'),
        'set' => __('Set to'),
        'add' => __('Change by amount'),
        'percent' => __('Change by percent'),
    ];
@endphp

<dialog id="products-bulk-edit" class="modal"
        x-data="{
            price: { mode: '', value: '' },
            list: { mode: '', value: '' },
            round: '0',
            example(base, change) {
                const v = parseFloat(change.value);
                if (!change.mode || change.mode === 'clear' || isNaN(v)) return '';
                let n = change.mode === 'set' ? v : (change.mode === 'percent' ? base * (1 + v / 100) : base + v);
                const step = parseInt(this.round);
                if (change.mode !== 'set' && step > 0) n = Math.round(n / step) * step;
                const fmt = (x) => x.toLocaleString(undefined, { maximumFractionDigits: 2 });
                return fmt(base) + ' → ' + fmt(Math.round(n * 100) / 100);
            },
        }">
    <div class="modal-box max-w-3xl p-0 flex flex-col max-h-[90vh]">
        <div class="flex items-center gap-3 px-6 py-4 border-b border-base-300">
            <h3 class="font-semibold text-lg grow">{{ __('Change products') }}</h3>
            <span class="badge badge-sm badge-ghost">
                <span x-show="!found"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                <span x-show="found">{{ __('All :n found', ['n' => $foundCount]) }}</span>
            </span>
            <button type="button" class="btn btn-ghost btn-sm btn-square" x-on:click="$el.closest('dialog').close()" aria-label="{{ __('Close') }}">
                <x-icon name="x" size="lg" />
            </button>
        </div>

        <div class="overflow-y-auto px-6 py-5 flex flex-col gap-6">
            <section class="flex flex-col gap-3">
                <h4 class="font-semibold">{{ __('Prices') }}</h4>
                <div class="grid sm:grid-cols-[10rem_1fr] gap-x-4 gap-y-3 items-center">
                    <span class="text-sm">{{ __('Price') }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="join">
                            <select name="price_mode" x-model="price.mode" class="select select-sm join-item w-48">
                                @foreach ($bulkNumberModes as $bulkMode => $bulkModeLabel)
                                    <option value="{{ $bulkMode }}">{{ $bulkModeLabel }}</option>
                                @endforeach
                            </select>
                            <input type="number" step="any" name="price_value" x-model="price.value" :disabled="!price.mode"
                                   class="input input-sm join-item w-32" :placeholder="price.mode === 'percent' ? '10' : '100'">
                            <span class="join-item btn btn-sm btn-disabled no-animation w-10" x-text="price.mode === 'percent' ? '%' : '¤'"></span>
                        </div>
                        <span class="text-xs opacity-70 tabular-nums" x-text="example(1000, price)"></span>
                    </div>

                    <span class="text-sm">{{ __('RRP (list price)') }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="join">
                            <select name="list_price_mode" x-model="list.mode" class="select select-sm join-item w-48">
                                @foreach ($bulkNumberModes as $bulkMode => $bulkModeLabel)
                                    <option value="{{ $bulkMode }}">{{ $bulkModeLabel }}</option>
                                @endforeach
                                <option value="clear">{{ __('Remove the RRP') }}</option>
                            </select>
                            <input type="number" step="any" name="list_price_value" x-model="list.value" :disabled="!list.mode || list.mode === 'clear'"
                                   class="input input-sm join-item w-32" :placeholder="list.mode === 'percent' ? '10' : '100'">
                            <span class="join-item btn btn-sm btn-disabled no-animation w-10" x-text="list.mode === 'percent' ? '%' : '¤'"></span>
                        </div>
                        <span class="text-xs opacity-70 tabular-nums" x-text="example(1200, list)"></span>
                    </div>

                    <span class="text-sm">{{ __('Round the result') }}</span>
                    <select name="round" x-model="round" class="select select-sm w-48">
                        <option value="0">{{ __('Do not round') }}</option>
                        <option value="1">{{ __('To whole units') }}</option>
                        <option value="10">{{ __('To tens') }}</option>
                        <option value="100">{{ __('To hundreds') }}</option>
                    </select>
                </div>
                <p class="text-xs opacity-60">
                    {{ __('To lower a price, use a minus: -50 or -10%. Amounts are in each product\'s own currency, as in the list. A product with no RRP keeps none when the RRP is changed by an amount or a percent.') }}
                </p>
            </section>

            <section class="flex flex-col gap-3" x-data="{ stockMode: '' }">
                <h4 class="font-semibold">{{ __('Stock and availability') }}</h4>
                <div class="grid sm:grid-cols-[10rem_1fr] gap-x-4 gap-y-3 items-center">
                    <span class="text-sm">{{ __('Stock') }}</span>
                    <div class="join">
                        <select name="stock_mode" x-model="stockMode" class="select select-sm join-item w-48">
                            <option value="">{{ __('Leave as it is') }}</option>
                            <option value="set">{{ __('Set to') }}</option>
                            <option value="add">{{ __('Change by amount') }}</option>
                        </select>
                        <input type="number" step="1" name="stock_value" :disabled="!stockMode" class="input input-sm join-item w-32" placeholder="10">
                    </div>

                    <span class="text-sm">{{ __('Availability') }}</span>
                    <select name="availability_status" class="select select-sm w-48">
                        <option value="">{{ __('Leave as it is') }}</option>
                        @foreach ($availabilityOptions as $bulkAvailabilityKey => $bulkAvailabilityLabel)
                            <option value="{{ $bulkAvailabilityKey }}">{{ $bulkAvailabilityLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs opacity-60">{{ __('Stock that warehouses count is not changed here — it moves with the warehouses\' own numbers.') }}</p>
            </section>

            <section class="flex flex-col gap-3">
                <h4 class="font-semibold">{{ __('Categories') }}</h4>
                <div class="grid sm:grid-cols-2 gap-4">
                    <x-admin.picker name="categories_add[]" :options="$categoryPicker" :selected="[]"
                                    :label="__('Add to')" :placeholder="__('Search categories…')" :empty="__('None')" />
                    <x-admin.picker name="categories_remove[]" :options="$categoryPicker" :selected="[]"
                                    :label="__('Remove from')" :placeholder="__('Search categories…')" :empty="__('None')" />
                </div>
            </section>

            @if ($labelPicker->isNotEmpty())
                <section class="flex flex-col gap-3">
                    <h4 class="font-semibold">{{ __('Labels') }}</h4>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <x-admin.picker name="labels_add[]" :options="$labelPicker" :selected="[]" badge
                                        :label="__('Add')" :placeholder="__('Search labels…')" :empty="__('None')" />
                        <x-admin.picker name="labels_remove[]" :options="$labelPicker" :selected="[]" badge
                                        :label="__('Remove')" :placeholder="__('Search labels…')" :empty="__('None')" />
                    </div>
                </section>
            @endif
        </div>

        <div class="flex items-center gap-2 px-6 py-4 border-t border-base-300">
            <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="$el.closest('dialog').close()">{{ __('Cancel') }}</button>
            <button name="action" value="edit" class="btn btn-primary btn-sm"
                    x-on:click="if (found && !confirm(@js(__('Change all :n products found?', ['n' => $foundCount])))) $event.preventDefault()">
                <span x-show="!found">{{ __('Apply to the selected') }}</span>
                <span x-show="found">{{ __('Apply to all :n found', ['n' => $foundCount]) }}</span>
            </button>
        </div>
    </div>
    <div class="modal-backdrop" x-on:click="$el.closest('dialog').close()"></div>
</dialog>
