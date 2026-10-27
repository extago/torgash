{{-- Requirements a cart must meet before this method is offered.

     Rows in one Alpine repeater, submitted with the method: they are small,
     ordered, and there is no state on them worth an inline save of their own —
     the opposite of a rate, which carries bands and its own zone. --}}
@php
    use Torgash\Shipping\Conditions\Conditions;

    $kindLabels = array_map(fn ($label) => __($label), Conditions::KIND_LABELS);

    $operators = [];
    foreach (Conditions::KINDS as $kind => $ops) {
        $operators[$kind] = array_map(fn ($label) => __($label), $ops);
    }

    // Pickers come from whoever owns the data — no dependency either way.
    $categories = (array) hook_filter('shipping.condition.categories', []);
    // Group names are translatable jsonb, like everything else an admin edits.
    $groups = \App\Models\CustomerGroup::orderBy('id')->get()
        ->mapWithKeys(fn ($group) => [$group->id => $group->t('name', admin_content_locale()) ?? '#'.$group->id])
        ->all();

    $rows = old('conditions', $method->exists
        ? $method->conditions->map(fn ($condition) => [
            'kind' => $condition->kind,
            'operator' => $condition->operator,
            'ids' => array_map('strval', $condition->ids()),
            'key' => $condition->text('key'),
            'match' => $condition->text('match'),
            'code' => $condition->text('code'),
            'amount' => $condition->amount() ? $condition->amount() / 100 : '',
        ])->all()
        : []);
@endphp

<div class="card bg-base-100 border border-base-300"
     x-data="{
         rows: {{ \Illuminate\Support\Js::from($rows) }},
         operators: {{ \Illuminate\Support\Js::from($operators) }},
         add() {
             this.rows.push({ kind: 'cart_total', operator: 'total_min', ids: [], key: '', match: '', code: '', amount: '' });
         },
         onKindChange(row) {
             row.operator = Object.keys(this.operators[row.kind])[0];
         },
     }">
    <div class="card-body gap-4">
        <p class="text-sm opacity-60">
            {{ __('Beyond the zone: extra requirements a cart must meet before this method is offered at all. ALL of them must hold — "one of these" goes inside a single row, like a list of categories.') }}
        </p>

        <template x-for="(row, index) in rows" :key="index">
            <div class="flex flex-wrap gap-2 items-end border-b border-base-200 pb-3">
                <fieldset class="fieldset p-0 w-44">
                    <legend class="fieldset-legend">{{ __('About') }}</legend>
                    <select class="select select-bordered w-full" form="shipping-method-form"
                            :name="`conditions[${index}][kind]`" x-model="row.kind" @change="onKindChange(row)">
                        @foreach ($kindLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </fieldset>

                <fieldset class="fieldset p-0 grow min-w-56">
                    <legend class="fieldset-legend">{{ __('Requirement') }}</legend>
                    <select class="select select-bordered w-full" form="shipping-method-form"
                            :name="`conditions[${index}][operator]`" x-model="row.operator">
                        <template x-for="(label, op) in operators[row.kind]" :key="op">
                            <option :value="op" x-text="label"></option>
                        </template>
                    </select>
                </fieldset>

                {{-- The value, whose shape is the kind's business. --}}
                <fieldset class="fieldset p-0 w-64" x-show="row.kind === 'category'" x-cloak>
                    <legend class="fieldset-legend">{{ __('Categories') }}</legend>
                    <select multiple size="4" class="select select-bordered w-full h-auto" form="shipping-method-form"
                            :name="`conditions[${index}][ids][]`" x-model="row.ids">
                        @forelse ($categories as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @empty
                            <option disabled>{{ __('No catalogue module installed.') }}</option>
                        @endforelse
                    </select>
                </fieldset>

                <fieldset class="fieldset p-0 w-64" x-show="row.kind === 'customer_group'" x-cloak>
                    <legend class="fieldset-legend">{{ __('Groups') }}</legend>
                    <select multiple size="3" class="select select-bordered w-full h-auto" form="shipping-method-form"
                            :name="`conditions[${index}][ids][]`" x-model="row.ids">
                        @foreach ($groups as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </fieldset>

                <fieldset class="fieldset p-0 w-40" x-show="row.kind === 'attribute'" x-cloak>
                    <legend class="fieldset-legend">{{ __('Characteristic') }}</legend>
                    <input class="input input-bordered w-full" form="shipping-method-form"
                           placeholder="{{ __('its name or code') }}"
                           :name="`conditions[${index}][key]`" x-model="row.key">
                </fieldset>

                <fieldset class="fieldset p-0 w-32" x-show="row.kind === 'attribute' && row.operator === 'attr_equals'" x-cloak>
                    <legend class="fieldset-legend">{{ __('Value') }}</legend>
                    <input class="input input-bordered w-full" form="shipping-method-form"
                           :name="`conditions[${index}][match]`" x-model="row.match">
                </fieldset>

                <fieldset class="fieldset p-0 w-40" x-show="row.kind === 'coupon' && row.operator === 'coupon_is'" x-cloak>
                    <legend class="fieldset-legend">{{ __('Code') }}</legend>
                    <input class="input input-bordered w-full" form="shipping-method-form"
                           :name="`conditions[${index}][code]`" x-model="row.code">
                </fieldset>

                <fieldset class="fieldset p-0 w-32" x-show="row.kind === 'cart_total'" x-cloak>
                    <legend class="fieldset-legend">{{ __('Amount') }}</legend>
                    <input type="number" step="0.01" min="0" class="input input-bordered w-full" form="shipping-method-form"
                           :name="`conditions[${index}][amount]`" x-model="row.amount">
                </fieldset>

                <button type="button" class="btn btn-ghost btn-sm text-error" @click="rows.splice(index, 1)">
                    {{ __('Remove') }}
                </button>
            </div>
        </template>

        <div class="flex flex-wrap gap-2 items-center">
            <button type="button" class="btn btn-sm" @click="add()">+ {{ __('Add a requirement') }}</button>
            <span class="text-xs opacity-50" x-show="rows.length === 0" x-cloak>
                {{ __('No requirements: offered wherever it has a rate.') }}
            </span>
        </div>
    </div>
</div>
