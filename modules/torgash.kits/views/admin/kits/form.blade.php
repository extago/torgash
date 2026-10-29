@extends('admin.layout')

@section('title', $kit->exists ? __('Kit') : __('New kit'))
@section('own_heading', '1')

@section('content')
    @php
        use Torgash\Kits\Models\Kit;
        use Torgash\Kits\Models\KitSlot;

        $locale = admin_content_locale();
        $rows = $kit->slots->map(fn ($slot) => [
            'id' => $slot->id,
            'kind' => $slot->kind,
            'product' => $slot->product?->sku,
            'product_label' => $slot->product ? ($slot->product->t('name', $locale) ?: $slot->product->sku) : null,
            'source' => $slot->source,
            'match_features' => array_map('strval', $slot->matchFeatureIds()),
            'fq' => '',
            'qty' => $slot->qty,
            'discount_type' => $slot->discount_type,
            'discount_percent' => $slot->discount_percent,
            'discount_amount' => $slot->discount_amount_original !== null
                ? number_format($slot->discount_amount_original / 100, 2, '.', '')
                : number_format($slot->discount_amount / 100, 2, '.', ''),
            'discount_currency' => $slot->discount_currency,
            'required' => (bool) $slot->required,
            'swappable' => (bool) $slot->swappable,
            'enabled' => (bool) $slot->enabled,
        ])->values();
        // The characteristics a slot can be matched by, as an ordered LIST for
        // the chip picker — a map keyed by id would be re-sorted by the browser.
        $featureList = $features->map(fn ($f) => ['id' => (string) $f->id, 'label' => (string) $f->t('name', $locale)])->values();
        $backUrl = $kit->mainProduct ? route('admin.catalog.products.edit', $kit->mainProduct) : route('admin.kits.index');
    @endphp

    <form method="POST" class="flex flex-col gap-4"
          action="{{ $kit->exists ? route('admin.kits.update', $kit) : route('admin.kits.store') }}"
          x-data="kitForm(@js($rows), @js($featureList))">
        @csrf
        @if ($kit->exists) @method('PUT') @endif
        <input type="hidden" name="main_product_id" value="{{ old('main_product_id', $kit->main_product_id) }}">

        <x-admin.form-bar>
            <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back') }}" aria-label="{{ __('Back') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">
                    {{ $kit->exists ? __('Kit') : __('New kit') }}
                    @if ($kit->mainProduct) · {{ $kit->mainProduct->t('name', $locale) ?: $kit->mainProduct->sku }} @endif
                </div>
                <div class="font-semibold truncate leading-tight">{{ $kit->exists ? $kit->admin_name : __('New kit') }}</div>
            </div>

            @if ($kit->exists)
                {{-- Delete submits the standalone form below through the HTML
                     "form" attribute: a nested <form> would leak its
                     _method=DELETE into this one's submit. --}}
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        @if ($kit->mainProduct)
                            <li>
                                <a href="{{ route('catalog.product', $kit->mainProduct->slug) }}" target="_blank" rel="noopener">
                                    <x-icon name="external-link" /> {{ __('Preview') }}
                                </a>
                            </li>
                        @endif
                        <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                        <li>
                            <button type="submit" form="delete-kit" class="text-error" data-confirm="{{ __('Delete this kit?') }}">
                                <x-icon name="trash-2" /> {{ __('Delete this kit') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $kit->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            {{-- Left: what the card says, what goes in it, where it is offered. --}}
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>
                        <x-admin.translatable-field name="name" :label="__('Heading on the card')" :value="$kit->name ?? []" required
                            placeholder="{{ __('e.g. Everything you need, in one set') }}" />
                        <x-admin.translatable-field name="description" :label="__('Text under the heading')" :value="$kit->description ?? []" textarea :rows="2" />
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name for the admin') }} *</legend>
                            <input name="admin_name" value="{{ old('admin_name', $kit->admin_name) }}" class="input input-bordered w-full" required>
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold grow">{{ __('What goes with it') }} <span class="badge badge-ghost badge-sm ms-1" x-show="rows.length" x-text="rows.length"></span></h3>
                            <button type="button" class="btn btn-sm gap-1.5" @click="add()"><x-icon name="plus" size="sm" /> {{ __('Add an item') }}</button>
                        </div>

                        {{-- One card per item, fields at the form's own height. The
                             order on screen IS the order saved (saveSlots() numbers
                             them as they arrive), so the arrows are the ordering. --}}
                        <template x-for="(row, index) in rows" :key="index">
                            <div class="rounded-box border border-base-300 bg-base-200/40 p-4 flex flex-col gap-3" :class="! row.enabled && 'opacity-60'">
                                <input type="hidden" :name="`slots[${index}][id]`" :value="row.id">

                                <div class="flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-full bg-base-300 text-xs font-semibold flex items-center justify-center shrink-0" x-text="index + 1"></span>
                                    <select :name="`slots[${index}][kind]`" x-model="row.kind" class="select select-bordered w-auto" aria-label="{{ __('This item is') }}">
                                        <option value="product">{{ __('One product') }}</option>
                                        <option value="source">{{ __('One of a set of products') }}</option>
                                    </select>
                                    <div class="grow"></div>
                                    <label class="label cursor-pointer gap-2 me-2">
                                        <input type="hidden" :name="`slots[${index}][enabled]`" value="0">
                                        <input type="checkbox" :name="`slots[${index}][enabled]`" value="1" x-model="row.enabled" class="toggle toggle-success toggle-sm">
                                        <span class="label-text text-sm">{{ __('Enabled') }}</span>
                                    </label>
                                    <div class="join">
                                        <button type="button" class="btn btn-ghost btn-sm btn-square join-item" :disabled="index === 0"
                                                @click="move(index, -1)" title="{{ __('Move up') }}" aria-label="{{ __('Move up') }}">
                                            <x-icon name="chevron-up" />
                                        </button>
                                        <button type="button" class="btn btn-ghost btn-sm btn-square join-item" :disabled="index === rows.length - 1"
                                                @click="move(index, 1)" title="{{ __('Move down') }}" aria-label="{{ __('Move down') }}">
                                            <x-icon name="chevron-down" />
                                        </button>
                                    </div>
                                    <span class="w-px h-5 shrink-0 bg-base-300 mx-1" aria-hidden="true"></span>
                                    <button type="button" class="btn btn-ghost btn-sm btn-square text-error" @click="rows.splice(index, 1)"
                                            title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                        <x-icon name="trash-2" />
                                    </button>
                                </div>

                                <fieldset class="fieldset p-0" x-show="row.kind === 'product'">
                                    <legend class="fieldset-legend">{{ __('Product (SKU or id)') }}</legend>
                                    <input :name="`slots[${index}][product]`" x-model="row.product" class="input input-bordered w-full font-mono">
                                    <p class="label text-xs opacity-60" x-show="row.product_label" x-text="row.product_label"></p>
                                </fieldset>

                                <div class="grid gap-3 md:grid-cols-2" x-show="row.kind === 'source'" x-cloak>
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('The set') }}</legend>
                                        <select :name="`slots[${index}][source]`" x-model="row.source" class="select select-bordered w-full">
                                            <option value="">{{ __('Choose…') }}</option>
                                            @foreach ($sourceOptions as $option)
                                                <option value="{{ $option['value'] }}">{{ ($option['group'] ?? '') !== '' ? $option['group'].' · ' : '' }}{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>

                                    {{-- Several, and ALL of them have to match: an outfit is
                                         the same colour AND the same fabric. Chips and a
                                         search, as every other picker in the admin. --}}
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('Fits by') }}</legend>
                                        <div class="flex flex-wrap gap-1.5 mb-1.5" x-show="row.match_features.length">
                                            <template x-for="fid in row.match_features" :key="fid">
                                                <span class="badge badge-lg gap-1 bg-base-100 border-base-300 font-normal">
                                                    <span x-text="featureLabel(fid)"></span>
                                                    <input type="hidden" :name="`slots[${index}][match_features][]`" :value="fid">
                                                    <button type="button" class="opacity-50 hover:opacity-100" @click="row.match_features = row.match_features.filter(v => v !== fid)" aria-label="{{ __('Remove') }}">
                                                        <x-icon name="x" size="sm" />
                                                    </button>
                                                </span>
                                            </template>
                                        </div>
                                        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                            <label class="input input-bordered w-full gap-2">
                                                <x-icon name="search" size="sm" class="opacity-50" />
                                                <input type="text" class="grow" x-model="row.fq" @focus="open = true" @input="open = true" @keydown.escape="open = false"
                                                       @keydown.enter.prevent="offeredFeatures(row)[0] && pickFeature(row, offeredFeatures(row)[0].id)"
                                                       placeholder="{{ __('Find and add…') }}">
                                            </label>
                                            <ul x-show="open && offeredFeatures(row).length" x-cloak
                                                class="absolute z-30 mt-1 w-full max-h-64 overflow-y-auto menu menu-sm bg-base-100 rounded-box border border-base-300 shadow-lg p-1 flex-nowrap">
                                                <template x-for="f in offeredFeatures(row)" :key="f.id">
                                                    <li><button type="button" @click="pickFeature(row, f.id)" x-text="f.label"></button></li>
                                                </template>
                                            </ul>
                                        </div>
                                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('Nothing chosen: anything in the set fits. Several: all of them must match the main product.') }}</p>
                                    </fieldset>
                                </div>

                                <div class="grid gap-3 grid-cols-2 md:grid-cols-[minmax(0,0.7fr)_minmax(0,1.6fr)_minmax(0,1.4fr)]">
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('Quantity') }}</legend>
                                        <input type="number" min="1" :name="`slots[${index}][qty]`" x-model="row.qty" class="input input-bordered w-full">
                                    </fieldset>

                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('In the kit') }}</legend>
                                        <select :name="`slots[${index}][discount_type]`" x-model="row.discount_type" class="select select-bordered w-full">
                                            @foreach (KitSlot::discountLabels() as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 col-span-2 md:col-span-1" x-show="row.discount_type === 'percent'" x-cloak>
                                        <legend class="fieldset-legend">{{ __('Percent') }}</legend>
                                        <label class="input input-bordered w-full">
                                            <input type="number" step="0.01" :name="`slots[${index}][discount_percent]`" x-model="row.discount_percent" class="grow min-w-0">
                                            <span class="opacity-50 text-sm">%</span>
                                        </label>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 col-span-2 md:col-span-1" x-show="row.discount_type === 'fixed' || row.discount_type === 'to_price'" x-cloak>
                                        <legend class="fieldset-legend">{{ __('Amount') }}</legend>
                                        <div class="join w-full">
                                            <input type="number" step="0.01" :name="`slots[${index}][discount_amount]`" x-model="row.discount_amount"
                                                   class="input input-bordered join-item w-full min-w-0">
                                            <select :name="`slots[${index}][discount_currency]`" x-model="row.discount_currency" class="select select-bordered join-item w-24 shrink-0" aria-label="{{ __('Currency') }}">
                                                <option value="">{{ shop_currency() }}</option>
                                                @foreach ($currencies as $code => $currency)
                                                    @continue ($code === shop_currency())
                                                    <option value="{{ $code }}">{{ $code }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </fieldset>
                                </div>

                                <div class="flex flex-wrap gap-x-6 gap-y-2">
                                    <label class="label cursor-pointer gap-2">
                                        <input type="hidden" :name="`slots[${index}][required]`" value="0">
                                        <input type="checkbox" :name="`slots[${index}][required]`" value="1" x-model="row.required" class="checkbox checkbox-sm">
                                        <span class="label-text">{{ __('Cannot be unticked') }}</span>
                                    </label>
                                    <label class="label cursor-pointer gap-2" x-show="row.kind === 'source'" x-cloak>
                                        <input type="hidden" :name="`slots[${index}][swappable]`" value="0">
                                        <input type="checkbox" :name="`slots[${index}][swappable]`" value="1" x-model="row.swappable" class="checkbox checkbox-sm">
                                        <span class="label-text">{{ __('The customer may choose another') }}</span>
                                    </label>
                                </div>
                            </div>
                        </template>

                        <p class="text-xs opacity-60" x-show="rows.length === 0">{{ __('A kit with nothing in it is not shown at all.') }}</p>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300" @if ($kit->main_product_id) style="display:none" @endif>
                    <div class="card-body gap-3">
                        <x-admin.display-rules
                            :rules="$kit->rules ?? []"
                            :match="$kit->rules_match ?? 'all'"
                            :heading="__('Which products this kit is offered on')"
                            :hint="__('No conditions means every product in the shop leads this kit. A category always means everything under it; a condition whose module is switched off hides the kit rather than being ignored.')" />
                    </div>
                </section>
            </div>

            {{-- Right: whether it is on and when, what kind of set it is, how it is drawn. --}}
            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="hidden" name="enabled" value="0">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('enabled', $kit->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Valid from') }}</legend>
                            <input type="datetime-local" name="starts_at" class="input input-bordered w-full"
                                   value="{{ old('starts_at', $kit->starts_at?->format('Y-m-d\TH:i')) }}">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Until') }}</legend>
                            <input type="datetime-local" name="ends_at" class="input input-bordered w-full"
                                   value="{{ old('ends_at', $kit->ends_at?->format('Y-m-d\TH:i')) }}">
                            <p class="label text-xs opacity-60">{{ __('Empty — always.') }}</p>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                            <input type="number" name="sort" value="{{ old('sort', $kit->sort ?? 0) }}" class="input input-bordered w-full">
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('What the set is') }}</h3>
                        <select name="type" class="select select-bordered w-full">
                            @foreach (Kit::typeLabels() as $value => $label)
                                <option value="{{ $value }}" @selected(old('type', $kit->type ?? 'extended') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs opacity-60">
                            {{ __('Several separate items go into the basket as separate lines the customer can change one by one, and carry no discount — it is a convenient way to buy four things at once. One item means the set is bought as the main product with the rest inside it: the quantity moves as one, removing it removes everything, and only this kind may give a discount.') }}
                        </p>
                        {{-- The rule the shop must be told about, or they will wonder
                             why a promotion "stopped working" (PLAN §33.8). --}}
                        <div class="alert alert-info text-xs items-start">
                            <x-icon name="info" size="sm" class="shrink-0 mt-0.5" />
                            <span>{{ __('One line carries one discount. Goods bought inside a kit are not discounted again by cart promotions or a coupon, so set the kit price at least as good as whatever promotion those goods fall under.') }}</span>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('How it is shown') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Shown') }}</legend>
                            <select name="placement" class="select select-bordered w-full">
                                @foreach (Kit::placementLabels() as $value => $label)
                                    <option value="{{ $value }}" @selected(old('placement', $kit->placement) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('How the goods stand') }}</legend>
                            <select name="layout" class="select select-bordered w-full">
                                @foreach (Kit::layoutLabels() as $value => $label)
                                    <option value="{{ $value }}" @selected(old('layout', $kit->layout) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('On a narrow screen a kit is always one column, whichever is chosen.') }}</p>
                        </fieldset>

                        {{-- The shop's own question, not the customer's: "a required
                             item" says they may not untick it, this says do not show
                             the card at all when one of the goods cannot be put in
                             it. --}}
                        <label class="label cursor-pointer justify-start gap-2 items-start border-t border-base-300 pt-3">
                            <input type="hidden" name="whole_set_only" value="0">
                            <input type="checkbox" name="whole_set_only" value="1" class="checkbox checkbox-sm mt-0.5"
                                   @checked(old('whole_set_only', $kit->whole_set_only ?? false))>
                            <span class="label-text whitespace-normal">{{ __('Show only the whole set') }}</span>
                        </label>
                        <p class="text-xs opacity-60 -mt-2">
                            {{ __('An item with nothing to put in it — no glass fits this model, the product it named has been disabled — is normally left out and the rest of the set is shown anyway. Tick this and the set is not shown at all until every item can be filled.') }}
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($kit->exists)
        {{-- Beside the form, never inside it: a nested form is dropped by the
             parser while its _method rides along with the outer submit. --}}
        <form id="delete-kit" method="POST" class="hidden" action="{{ route('admin.kits.destroy', $kit) }}">
            @csrf @method('DELETE')
        </form>
    @endif

    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('kitForm', (rows, features) => ({
                    rows: rows,
                    features: features,

                    featureLabel(id) {
                        const f = this.features.find(f => f.id === String(id));
                        return f ? f.label : id;
                    },
                    // What the search offers: not chosen yet, and matching what was typed.
                    offeredFeatures(row) {
                        const q = (row.fq || '').trim().toLowerCase();
                        return this.features.filter(f => ! row.match_features.includes(f.id) && (q === '' || f.label.toLowerCase().includes(q))).slice(0, 50);
                    },
                    pickFeature(row, id) {
                        if (! row.match_features.includes(id)) row.match_features.push(id);
                        row.fq = '';
                    },

                    move(index, by) {
                        const to = index + by;
                        if (to < 0 || to >= this.rows.length) return;
                        const [row] = this.rows.splice(index, 1);
                        this.rows.splice(to, 0, row);
                    },

                    add() {
                        this.rows.push({
                            id: null, kind: 'product', product: '', source: '', match_features: [], fq: '',
                            qty: 1, discount_type: 'percent', discount_percent: '', discount_amount: '',
                            discount_currency: '', required: false, swappable: true, enabled: true,
                        });
                    },
                }));
            });
        </script>
    @endpush
@endsection
