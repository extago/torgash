@extends('admin.layout')

@section('title', $group->exists ? __('Option group') : __('New option group'))
@section('own_heading', '1')

@section('content')
    @php
        use Torgash\Options\Models\OptionGroup;

        $locale = admin_content_locale();
        // The answers as the repeater holds them: one row per choice, plus the
        // money exactly as it was typed (the books amount is derived, so it is
        // never what an admin edits).
        $rows = $group->choices->map(fn ($choice) => [
            'id' => $choice->id,
            'kind' => $choice->kind,
            'name' => $choice->t('name', $locale),
            'product' => $choice->product?->sku,
            'product_label' => $choice->product ? ($choice->product->t('name', $locale) ?: $choice->product->sku) : null,
            'source' => $choice->source,
            'price_rule' => $choice->price_rule,
            'amount' => $choice->amount_original !== null
                ? number_format($choice->amount_original / 100, 2, '.', '')
                : number_format($choice->amount / 100, 2, '.', ''),
            'currency' => $choice->currency,
            'percent' => $choice->percent,
            'weight' => $choice->weight,
            'max_qty' => $choice->max_qty,
            'is_default' => (bool) $choice->is_default,
            'enabled' => (bool) $choice->enabled,
        ])->values();
    @endphp
    @php
        $backUrl = $group->product ? route('admin.catalog.products.edit', $group->product) : route('admin.options.groups.index');
    @endphp

    <form method="POST" class="flex flex-col gap-4"
          action="{{ $group->exists ? route('admin.options.groups.update', $group) : route('admin.options.groups.store') }}"
          x-data="optionGroup(@js($rows), @js($ruleFields))">
        @csrf
        @if ($group->exists) @method('PUT') @endif
        <input type="hidden" name="product_id" value="{{ old('product_id', $group->product_id) }}">

        <x-admin.form-bar>
            <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back') }}" aria-label="{{ __('Back') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">
                    {{ $group->exists ? __('Option group') : __('New option group') }}
                    @if ($group->product) · {{ $group->product->t('name', $locale) ?: $group->product->sku }} @endif
                </div>
                <div class="font-semibold truncate leading-tight">{{ $group->exists ? $group->admin_name : __('New option group') }}</div>
            </div>

            @if ($group->exists)
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
                        @if ($group->product)
                            <li>
                                <a href="{{ route('catalog.product', $group->product->slug) }}" target="_blank" rel="noopener">
                                    <x-icon name="external-link" /> {{ __('Preview') }}
                                </a>
                            </li>
                        @endif
                        <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                        <li>
                            <button type="submit" form="delete-group" class="text-error"
                                    data-confirm="{{ __('Delete this option and its choices?') }}">
                                <x-icon name="trash-2" /> {{ __('Delete this option') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $group->exists ? __('Save') : __('Create') }}</button>
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
            {{-- Left: what it is called, what it offers, where and when it is asked. --}}
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>

                        <x-admin.translatable-field name="name" :label="__('Option')" :value="$group->name ?? []" required
                            placeholder="{{ __('e.g. Processor') }}" />

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name for the admin') }} *</legend>
                            <input name="admin_name" value="{{ old('admin_name', $group->admin_name) }}" class="input input-bordered w-full"
                                   placeholder="{{ __('e.g. CPU for Dell R420') }}" required>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('What this option is called in your own lists, where six “Colour”s are otherwise indistinguishable.') }}</p>
                        </fieldset>

                        <x-admin.translatable-field name="hint" :label="__('Hint under the option')" :value="$group->hint ?? []" textarea :rows="2" />
                    </div>
                </section>

                <div class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold grow">{{ __('Choices') }} <span class="badge badge-ghost badge-sm ms-1" x-show="rows.length" x-text="rows.length"></span></h3>
                            <button type="button" class="btn btn-sm gap-1.5" @click="add()"><x-icon name="plus" size="sm" /> {{ __('Add a choice') }}</button>
                        </div>

                        {{-- One card per choice, fields at the form's own height. The
                             order on screen IS the order saved (saveChoices() numbers
                             them as they arrive), so the arrows are the ordering. --}}
                        <template x-for="(row, index) in rows" :key="index">
                            <div class="rounded-box border border-base-300 bg-base-200/40 p-4 flex flex-col gap-3" :class="! row.enabled && 'opacity-60'">
                                <input type="hidden" :name="`choices[${index}][id]`" :value="row.id">

                                <div class="flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-full bg-base-300 text-xs font-semibold flex items-center justify-center shrink-0" x-text="index + 1"></span>
                                    <select :name="`choices[${index}][kind]`" x-model="row.kind" class="select select-bordered w-auto" aria-label="{{ __('This choice is') }}">
                                        <option value="plain">{{ __('A service or an extra') }}</option>
                                        <option value="product">{{ __('A product') }}</option>
                                        <option value="source">{{ __('A set of products to pick from') }}</option>
                                    </select>
                                    <div class="grow"></div>
                                    <label class="label cursor-pointer gap-2 me-2">
                                        <input type="hidden" :name="`choices[${index}][enabled]`" value="0">
                                        <input type="checkbox" :name="`choices[${index}][enabled]`" value="1" x-model="row.enabled" class="toggle toggle-success toggle-sm">
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

                                <fieldset class="fieldset p-0" x-show="row.kind === 'plain'">
                                    <legend class="fieldset-legend">{{ __('Name') }} <span class="opacity-40 font-normal">({{ strtoupper($locale) }})</span></legend>
                                    <input :name="`choices[${index}][name]`" x-model="row.name" class="input input-bordered w-full"
                                           placeholder="{{ __('e.g. Extended warranty, 2 years') }}">
                                </fieldset>

                                <fieldset class="fieldset p-0" x-show="row.kind === 'product'" x-cloak>
                                    <legend class="fieldset-legend">{{ __('Product (SKU or id)') }}</legend>
                                    <input :name="`choices[${index}][product]`" x-model="row.product" class="input input-bordered w-full font-mono"
                                           placeholder="CPU-2407">
                                    <p class="label text-xs opacity-60" x-show="row.product_label" x-text="row.product_label"></p>
                                </fieldset>

                                <fieldset class="fieldset p-0" x-show="row.kind === 'source'" x-cloak>
                                    <legend class="fieldset-legend">{{ __('The set') }}</legend>
                                    <select :name="`choices[${index}][source]`" x-model="row.source" class="select select-bordered w-full">
                                        <option value="">{{ __('Choose…') }}</option>
                                        @foreach ($sourceOptions as $option)
                                            <option value="{{ $option['value'] }}">{{ ($option['group'] ?? '') !== '' ? $option['group'].' · ' : '' }}{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                </fieldset>

                                <div class="grid gap-3 grid-cols-2 md:grid-cols-[minmax(0,2fr)_minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
                                    <fieldset class="fieldset p-0 col-span-2 md:col-span-1">
                                        <legend class="fieldset-legend">{{ __('Price') }}</legend>
                                        <select :name="`choices[${index}][price_rule]`" x-model="row.price_rule" class="select select-bordered w-full">
                                            @foreach ($ruleOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0" x-show="uses(row, 'amount')" x-cloak>
                                        <legend class="fieldset-legend">{{ __('Amount') }}</legend>
                                        <div class="join w-full">
                                            <input type="number" step="0.01" :name="`choices[${index}][amount]`" x-model="row.amount"
                                                   class="input input-bordered join-item w-full min-w-0">
                                            <select :name="`choices[${index}][currency]`" x-model="row.currency" class="select select-bordered join-item w-24 shrink-0" aria-label="{{ __('Currency') }}">
                                                <option value="">{{ shop_currency() }}</option>
                                                @foreach ($currencies as $code => $currency)
                                                    @continue ($code === shop_currency())
                                                    <option value="{{ $code }}">{{ $code }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </fieldset>

                                    <fieldset class="fieldset p-0" x-show="uses(row, 'percent')" x-cloak>
                                        <legend class="fieldset-legend" x-text="row.price_rule === 'multiplier' ? @js(__('Factor')) : @js(__('Percent'))"></legend>
                                        <label class="input input-bordered w-full">
                                            <input type="number" step="0.001" :name="`choices[${index}][percent]`" x-model="row.percent" class="grow min-w-0">
                                            <span class="opacity-50 text-sm" x-text="row.price_rule === 'multiplier' ? '×' : '%'"></span>
                                        </label>
                                    </fieldset>

                                    <fieldset class="fieldset p-0" x-show="row.kind === 'plain'">
                                        <legend class="fieldset-legend">{{ __('Weight') }}</legend>
                                        <label class="input input-bordered w-full">
                                            <input type="number" :name="`choices[${index}][weight]`" x-model="row.weight" class="grow min-w-0">
                                            <span class="opacity-50 text-sm">{{ __('g') }}</span>
                                        </label>
                                    </fieldset>

                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('At most, pcs') }}</legend>
                                        <input type="number" min="1" :name="`choices[${index}][max_qty]`" x-model="row.max_qty"
                                               class="input input-bordered w-full" placeholder="—">
                                    </fieldset>
                                </div>
                            </div>
                        </template>

                        <p class="text-xs opacity-50" x-show="rows.length === 0">
                            {{ __('An option with no choices is not offered at all.') }}
                        </p>
                        <p class="text-xs opacity-50" x-show="type === 'text' || type === 'textarea'" x-cloak>
                            {{ __('An option the customer types is charged by its FIRST choice — name it “Engraving” and give it a price. With no choices at all the words are free, and still go to the order.') }}
                        </p>
                        <p class="text-xs opacity-50">
                            {{ __('A percentage is measured against the product’s own price before any option, so the order of the options never changes the money. An amount typed in another currency is kept as typed and follows that rate, exactly as a product’s price does.') }}
                        </p>
                    </div>
                </div>

                {{-- Where a SHARED question is asked. A question belonging to a product
                     goes where that product goes, so the editor is hidden for it
                     rather than saved and ignored. --}}
                <div class="card bg-base-100 border border-base-300" @if ($group->product_id) style="display:none" @endif>
                    <div class="card-body gap-3">
                        <x-admin.display-rules
                            :rules="$group->rules ?? []"
                            :match="$group->rules_match ?? 'all'"
                            :heading="__('Which products have this option')"
                            :hint="__('No conditions means every product in the shop has it. A condition whose module is switched off hides the option rather than being ignored. A category always means everything under it.')" />
                    </div>
                </div>


                {{-- WHEN it is asked, which is a different question from WHERE (the
                     card above) and is settled against the half-filled form rather
                     than against the product. Nothing to hang it off means nothing to
                     choose, so the card is not drawn at all. --}}
                @if ($dependencyOptions !== [])
                    <div class="card bg-base-100 border border-base-300"
                         x-data="{ on: {{ old('depends_on_group_id', $group->depends_on_group_id) ? 'true' : 'false' }} }">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('When it is asked') }}</h3>

                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Only after this option is answered') }}</legend>
                                <select name="depends_on_group_id" class="select select-bordered w-full max-w-md"
                                        x-on:change="on = $event.target.value !== ''">
                                    <option value="">{{ __('Always asked') }}</option>
                                    @foreach ($dependencyOptions as $option)
                                        <option value="{{ $option['id'] }}"
                                                @selected((int) old('depends_on_group_id', $group->depends_on_group_id) === $option['id'])>
                                            {{ $option['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="text-xs opacity-50">
                                    {{ __('“Memory — only with a processor.” Until it is answered this option is not on the page at all, it cannot be required of anybody, and an answer given to it before is dropped rather than charged for.') }}
                                </p>
                            </fieldset>

                            <fieldset class="fieldset p-0" x-show="on" x-cloak>
                                <legend class="fieldset-legend">{{ __('…and only these answers count') }}</legend>
                                <div class="flex flex-col gap-1.5">
                                    @foreach ($dependencyOptions as $option)
                                        @continue((int) old('depends_on_group_id', $group->depends_on_group_id) !== $option['id'])
                                        @foreach ($option['choices'] as $choiceId => $choiceLabel)
                                            <label class="label cursor-pointer justify-start gap-2 p-0">
                                                <input type="checkbox" name="depends_on_choices[]" value="{{ $choiceId }}"
                                                       class="checkbox checkbox-sm"
                                                       @checked(in_array($choiceId, array_map('intval', (array) old('depends_on_choices', $group->depends_on_choices ?? [])), true))>
                                                <span class="label-text">{{ $choiceLabel }}</span>
                                            </label>
                                        @endforeach
                                    @endforeach
                                </div>
                                <p class="text-xs opacity-50">
                                    {{ __('Nothing ticked means any answer will do, which is the usual case. Tick some and the option appears only for those — a Windows licence only with an Intel board. Save to see the answers of a question you have just picked.') }}
                                </p>
                            </fieldset>
                        </div>
                    </div>
                @endif

            </div>

            {{-- Right: whether it is on and how the question is put. --}}
            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="hidden" name="enabled" value="0">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('enabled', $group->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-2">
                            <input type="hidden" name="required" value="0">
                            <input type="checkbox" name="required" value="1" class="checkbox checkbox-sm"
                                   @checked(old('required', $group->required ?? false))>
                            <span class="label-text">{{ __('Choosing is required') }}</span>
                        </label>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                            <input type="number" name="sort" value="{{ old('sort', $group->sort ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Among a product\'s options, lower is asked first.') }}</p>
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('How it is asked') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Shown as') }}</legend>
                            <select name="type" class="select select-bordered w-full" x-model="type">
                                @foreach (OptionGroup::typeLabels() as $value => $label)
                                    <option value="{{ $value }}" @selected(old('type', $group->type) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </fieldset>

                        <label class="label cursor-pointer justify-start gap-2 items-start">
                            <input type="hidden" name="per_unit" value="0">
                            <input type="checkbox" name="per_unit" value="1" class="checkbox checkbox-sm mt-0.5"
                                   @checked(old('per_unit', $group->per_unit ?? true))>
                            <span class="label-text whitespace-normal">{{ __('Counted per item') }}</span>
                        </label>
                        <p class="text-xs opacity-60 -mt-2">
                            {{ __('On: two servers means twice the processors. Off: charged once for the line however many are bought — an installation, a delivery to the floor.') }}
                        </p>

                        <div class="grid grid-cols-2 gap-3" x-show="type !== 'select' && type !== 'radio'" x-cloak>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('At least') }}</legend>
                                <input type="number" name="min_select" min="0" value="{{ old('min_select', $group->min_select) }}" class="input input-bordered w-full">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('At most') }}</legend>
                                <input type="number" name="max_select" min="1" value="{{ old('max_select', $group->max_select) }}" class="input input-bordered w-full">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Quantity from') }}</legend>
                                <input type="number" name="min_qty" min="1" value="{{ old('min_qty', $group->min_qty ?? 1) }}" class="input input-bordered w-full">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Quantity up to') }}</legend>
                                <input type="number" name="max_qty" min="1" value="{{ old('max_qty', $group->max_qty) }}" class="input input-bordered w-full">
                            </fieldset>
                        </div>

                        <fieldset class="fieldset p-0" x-show="type === 'text' || type === 'textarea'" x-cloak>
                            <legend class="fieldset-legend">{{ __('Longest text allowed') }}</legend>
                            <input type="number" name="max_length" min="1" max="2000"
                                   value="{{ old('max_length', $group->max_length) }}" class="input input-bordered w-full max-w-xs">
                            <p class="text-xs opacity-50">
                                {{ __('An engraving plate is twenty characters wide, and refusing at the till is worse than refusing in the form. Empty means no limit.') }}
                            </p>
                        </fieldset>

                        <div class="grid gap-3" x-show="type === 'file'" x-cloak>
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Formats taken') }}</legend>
                                <input name="file_types" value="{{ old('file_types', $group->file_types) }}"
                                       class="input input-bordered w-full" placeholder="{{ \Torgash\Options\Files::DEFAULT_TYPES }}">
                                <p class="text-xs opacity-50">
                                    {{ __('Extensions separated by commas. Empty uses the shop-wide setting. Checked against what the file really is, not what it is called; executable and script formats are never taken.') }}
                                </p>
                            </fieldset>
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Largest file, KB') }}</legend>
                                <input type="number" name="max_kb" min="1" value="{{ old('max_kb', $group->max_kb) }}"
                                       class="input input-bordered w-full">
                                <p class="text-xs opacity-50">{{ __('Empty uses the shop-wide setting.') }}</p>
                            </fieldset>
                        </div>

                        <fieldset class="fieldset p-0" x-show="type === 'quantities' || type === 'rows'" x-cloak>
                            <legend class="fieldset-legend">{{ __('Quick quantity buttons') }}</legend>
                            <input name="qty_steps" value="{{ old('qty_steps', implode(' ', (array) ($group->qty_steps ?? []))) }}"
                                   class="input input-bordered w-full max-w-xs" placeholder="1 2 4">
                            <p class="text-xs opacity-50">{{ __('Numbers separated by spaces — the “1x 2x” buttons beside a choice.') }}</p>
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($group->exists)
        {{-- Beside the form, never inside it: a nested form is dropped by the
             parser while its _method rides along with the outer submit. --}}
        <form id="delete-group" method="POST" class="hidden" action="{{ route('admin.options.groups.destroy', $group) }}">
            @csrf @method('DELETE')
        </form>
    @endif

    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('optionGroup', (rows, ruleFields) => ({
                    rows: rows,
                    type: @js(old('type', $group->type ?? 'select')),
                    ruleFields: ruleFields,

                    // Which inputs a rule actually uses — answered by the
                    // registry, so a rule a module adds shows its own fields
                    // without this file changing.
                    uses(row, field) {
                        return (this.ruleFields[row.price_rule] || []).includes(field);
                    },

                    move(index, by) {
                        const to = index + by;
                        if (to < 0 || to >= this.rows.length) return;
                        const [row] = this.rows.splice(index, 1);
                        this.rows.splice(to, 0, row);
                    },

                    add() {
                        this.rows.push({
                            id: null, kind: 'plain', name: '', product: '', source: '',
                            price_rule: 'fixed', amount: '', currency: '', percent: '',
                            weight: '', max_qty: '', is_default: false, enabled: true,
                        });
                    },
                }));
            });
        </script>
    @endpush
@endsection
