{{--
    Which products an export profile takes (Torgash\Catalog\Exim\ProductSelection),
    drawn inside exim's profile form for the `product` entity
    (`exim.profile.form_parts`). `$selectionFilters` is old input or the
    profile's stored filters, `$selectionChoices` what the shop offers —
    named distinctively: the form's @include hands every variable down.
--}}
@php
    $sel = $selectionFilters;
    $choices = $selectionChoices;
    $featureRows = array_values(array_map(fn ($row) => [
        'feature' => (string) ($row['feature'] ?? ''),
        'op' => (string) ($row['op'] ?? 'eq'),
        'value' => (string) ($row['value'] ?? ''),
    ], (array) ($sel['features'] ?? [])));
    $noValue = ['empty', 'not_empty'];
@endphp

<section class="card bg-base-100 border border-base-300" x-show="entity === 'product'" x-cloak
         x-data="{ features: @js($featureRows), noValue: @js($noValue) }">
    <fieldset class="card-body gap-4" :disabled="entity !== 'product'">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-semibold">{{ __('Which products') }}</h3>
            @isset($selectionCount)
                <span class="text-sm opacity-70">{{ trans_choice(':count product matches now|:count products match now', $selectionCount, ['count' => number_format($selectionCount, 0, '', ' ')]) }}</span>
            @endisset
        </div>
        <p class="text-sm opacity-70">{{ __('Every field left empty takes everything. Together the fields narrow: a product must pass all of them.') }}</p>

        <div class="grid md:grid-cols-2 gap-4">
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Statuses') }}</legend>
                <div class="flex flex-wrap gap-x-5 gap-y-1">
                    @foreach ($choices['statuses'] as $status)
                        <label class="label cursor-pointer gap-2 p-0">
                            <input type="checkbox" name="statuses[]" value="{{ $status }}" class="checkbox checkbox-sm"
                                   @checked(in_array($status, (array) ($sel['statuses'] ?? ['active']), true))>
                            <span class="label-text">{{ __(ucfirst($status)) }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="label whitespace-normal">{{ __('None ticked means active only — what the catalogue lists.') }}</p>
            </fieldset>

            <div class="flex flex-col gap-2">
                <label class="label cursor-pointer justify-start gap-3 p-0">
                    <input type="checkbox" name="in_stock_only" value="1" class="toggle toggle-sm toggle-success" @checked($sel['in_stock_only'] ?? false)>
                    <span class="label-text">{{ __('Only what can be ordered now') }}</span>
                </label>
                <label class="label cursor-pointer justify-start gap-3 p-0">
                    <input type="checkbox" name="with_description" value="1" class="toggle toggle-sm toggle-success" @checked($sel['with_description'] ?? false)>
                    <span class="label-text">{{ __('Only with a description') }}</span>
                </label>
                <label class="label cursor-pointer justify-start gap-3 p-0">
                    <input type="checkbox" name="with_image" value="1" class="toggle toggle-sm toggle-success" @checked($sel['with_image'] ?? false)>
                    <span class="label-text">{{ __('Only with a photo') }}</span>
                </label>
            </div>
        </div>

        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Availability') }}</legend>
            <div class="flex flex-wrap gap-x-5 gap-y-1">
                @foreach ($choices['availability'] as $key => $name)
                    <label class="label cursor-pointer gap-2 p-0">
                        <input type="checkbox" name="availability[]" value="{{ $key }}" class="checkbox checkbox-sm"
                               @checked(in_array($key, (array) ($sel['availability'] ?? []), true))>
                        <span class="label-text">{{ $name }}</span>
                    </label>
                @endforeach
            </div>
            <p class="label whitespace-normal">{{ __('Ticked: the product\'s availability status is one of them. None ticked — any status.') }}</p>
        </fieldset>

        <div class="grid sm:grid-cols-2 md:grid-cols-4 gap-4">
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Price from') }}, {{ shop_currency() }}</legend>
                <input name="price_min" value="{{ $sel['price_min'] ?? '' }}" inputmode="decimal" class="input input-bordered w-full font-mono" placeholder="0">
            </fieldset>
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Price to') }}, {{ shop_currency() }}</legend>
                <input name="price_max" value="{{ $sel['price_max'] ?? '' }}" inputmode="decimal" class="input input-bordered w-full font-mono" placeholder="∞">
            </fieldset>
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Stock from') }}</legend>
                <input name="stock_min" value="{{ $sel['stock_min'] ?? '' }}" inputmode="numeric" class="input input-bordered w-full font-mono" placeholder="0">
            </fieldset>
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Stock to') }}</legend>
                <input name="stock_max" value="{{ $sel['stock_max'] ?? '' }}" inputmode="numeric" class="input input-bordered w-full font-mono" placeholder="∞">
            </fieldset>
        </div>

        <div class="grid md:grid-cols-2 gap-4">
            {{-- A list of ticks, not a <select multiple>: there a plain click
                 REPLACES the selection and only Ctrl-click takes one away,
                 which nobody discovers (the owner could not untick). --}}
            @foreach (['categories' => __('In categories'), 'categories_exclude' => __('Not in categories')] as $pickName => $pickLabel)
                <fieldset class="fieldset p-0" x-data="{ q: '' }"><legend class="fieldset-legend">{{ $pickLabel }}</legend>
                    <label class="input input-sm w-full gap-2">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" class="grow" x-model="q" placeholder="{{ __('Find a category…') }}" aria-label="{{ $pickLabel }}: {{ __('Find a category…') }}">
                    </label>
                    <div class="max-h-56 overflow-y-auto border border-base-300 rounded-box p-2 mt-1 flex flex-col gap-1">
                        @foreach ($choices['categories'] as $id => $name)
                            <label class="label cursor-pointer justify-start gap-2 p-0" x-show="q === '' || @js(mb_strtolower(ltrim($name, '— '))).includes(q.toLowerCase())">
                                <input type="checkbox" name="{{ $pickName }}[]" value="{{ $id }}" class="checkbox checkbox-sm"
                                       @checked(in_array($id, (array) ($sel[$pickName] ?? []), false))>
                                <span class="label-text font-mono text-sm">{{ $name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <label class="label cursor-pointer justify-start gap-2 p-0 mt-1">
                        <input type="checkbox" name="{{ $pickName }}_subtree" value="1" class="checkbox checkbox-sm" @checked($sel[$pickName.'_subtree'] ?? false)>
                        <span class="label-text">{{ __('With their subcategories') }}</span>
                    </label>
                    @if ($pickName === 'categories')
                        <p class="label whitespace-normal">{{ __('Nothing ticked — every category.') }}</p>
                    @endif
                </fieldset>
            @endforeach
        </div>

        @if ($choices['labels'] !== [])
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Wearing a label') }}</legend>
                <div class="flex flex-wrap gap-x-5 gap-y-1">
                    @foreach ($choices['labels'] as $id => $name)
                        <label class="label cursor-pointer gap-2 p-0">
                            <input type="checkbox" name="labels[]" value="{{ $id }}" class="checkbox checkbox-sm" @checked(in_array($id, (array) ($sel['labels'] ?? []), false))>
                            <span class="label-text">{{ $name }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="label whitespace-normal">{{ __('Ticked: the product wears at least one of them. None ticked — labels do not matter.') }}</p>
            </fieldset>
        @endif

        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Characteristics') }}</legend>
            <template x-for="(row, index) in features" :key="index">
                <div class="grid sm:grid-cols-[minmax(0,1fr)_14rem_minmax(0,1fr)_auto] gap-2 mb-2 items-center">
                    <select class="select select-bordered select-sm w-full" :name="'features[' + index + '][feature]'" x-model="row.feature">
                        <option value="">{{ __('Characteristic…') }}</option>
                        @foreach ($choices['features'] as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <select class="select select-bordered select-sm w-full" :name="'features[' + index + '][op]'" x-model="row.op">
                        @foreach ($choices['ops'] as $op => $label)
                            <option value="{{ $op }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                    <input class="input input-bordered input-sm w-full" :name="'features[' + index + '][value]'" x-model="row.value"
                           x-show="! noValue.includes(row.op)" :disabled="noValue.includes(row.op)" placeholder="{{ __('Value') }}">
                    <span x-show="noValue.includes(row.op)"></span>
                    <button type="button" class="btn btn-ghost btn-sm btn-square text-error" x-on:click="features.splice(index, 1)" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                        <x-icon name="x" size="sm" />
                    </button>
                </div>
            </template>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="btn btn-outline btn-sm" x-on:click="features.push({ feature: '', op: 'eq', value: '' })">+ {{ __('Add a condition') }}</button>
                <label class="flex items-center gap-2 text-sm" x-show="features.length > 1">
                    <span>{{ __('A product must pass') }}</span>
                    <select name="features_operator" class="select select-bordered select-sm">
                        <option value="and" @selected(($sel['features_operator'] ?? 'and') === 'and')>{{ __('all of them') }}</option>
                        <option value="or" @selected(($sel['features_operator'] ?? 'and') === 'or')>{{ __('any of them') }}</option>
                    </select>
                </label>
            </div>
            <p class="label whitespace-normal">{{ __('A value is compared as text in every language, numbers as numbers ("16 GB" counts as 16). "Is not", "does not contain" and "is empty" also pass a product that has no such characteristic.') }}</p>
        </fieldset>

        <div class="grid md:grid-cols-2 gap-4">
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Also take these SKUs') }}</legend>
                <textarea name="skus_include" rows="3" class="textarea textarea-bordered w-full font-mono text-sm" placeholder="SKU-1, SKU-2">{{ implode(', ', (array) ($sel['skus_include'] ?? [])) }}</textarea>
                <label class="flex items-center gap-2 text-sm mt-1">
                    <select name="include_operator" class="select select-bordered select-sm">
                        <option value="or" @selected(($sel['include_operator'] ?? 'or') === 'or')>{{ __('as well as the categories and characteristics above') }}</option>
                        <option value="and" @selected(($sel['include_operator'] ?? 'or') === 'and')>{{ __('only those of them that also pass the above') }}</option>
                    </select>
                </label>
            </fieldset>
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Never take these SKUs') }}</legend>
                <textarea name="skus_exclude" rows="3" class="textarea textarea-bordered w-full font-mono text-sm" placeholder="SKU-3">{{ implode(', ', (array) ($sel['skus_exclude'] ?? [])) }}</textarea>
            </fieldset>
        </div>

        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Stop words') }}</legend>
            <textarea name="stop_words" rows="2" class="textarea textarea-bordered w-full text-sm" placeholder="{{ __('refurbished, display sample') }}">{{ implode(', ', (array) ($sel['stop_words'] ?? [])) }}</textarea>
            <div class="flex flex-wrap gap-x-5 gap-y-1 mt-1">
                @foreach ($choices['stop_fields'] as $field)
                    <label class="label cursor-pointer gap-2 p-0">
                        <input type="checkbox" name="stop_fields[]" value="{{ $field }}" class="checkbox checkbox-sm"
                               @checked(in_array($field, (array) ($sel['stop_fields'] ?? ['name']), true))>
                        <span class="label-text">{{ __(match ($field) { 'name' => 'Name', 'short_description' => 'Short description', 'description' => 'Description', default => 'SEO title and description' }) }}</span>
                    </label>
                @endforeach
            </div>
            <p class="label whitespace-normal">{{ __('A product mentioning any of the words in a ticked field, in any language, is left out. Commas or new lines between the words.') }}</p>
        </fieldset>
    </fieldset>
</section>
