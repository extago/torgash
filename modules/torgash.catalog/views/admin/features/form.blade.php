@extends('admin.layout')

@section('title', $feature->exists ? __('Edit characteristic') : __('New characteristic'))
@section('own_heading', '1')

@section('content')
    @php
        $initialValues = $feature->exists
            ? $feature->values->map(fn ($v) => [
                'id' => $v->id,
                // Every language the value has, as an OBJECT: the row goes
                // back whole, so a language this screen is not showing is kept.
                'value' => (object) array_filter((array) $v->value, fn ($text) => is_string($text) && $text !== ''),
                'code' => $v->code ?? '',
                'position' => $v->sort,
                'swatch' => $v->swatch ?? '#000000',
                // Spellings merged into the value (PLAN §60), handed back
                // whole: the screen can only drop some of them.
                'aliases' => array_values((array) ($v->aliases ?? [])),
            ])->values()
            : collect();
        $spellingGroups ??= [];
        $valueProducts ??= [];
        $selectedCategories = array_map('intval', old('categories', $feature->exists ? $feature->categories->pluck('id')->all() : []));
        $locale = admin_content_locale();
        $purposes = [
            'filter' => [__('Plain characteristic'), __('A property of the product: filtered by, shown in the specifications.')],
            'variation' => [__('Variation axis'), __('Also switches the products of one variation group — colour, size, memory. Only a characteristic marked like this can.')],
            'brand' => [__('Brand'), __('Each value is a brand with a page of its own: logo, description and the brand\'s products.')],
        ];
        // The category tree as the picker wants it: id + an indented label.
        $categoryPickerOptions = collect($categories)->map(fn ($label, $id) => ['id' => (int) $id, 'label' => $label])->values();
    @endphp

    <form method="POST"
          action="{{ $feature->exists ? route('admin.catalog.features.update', $feature) : route('admin.catalog.features.store') }}"
          class="flex flex-col gap-4"
          x-data="{
              inputType: '{{ old('input_type', $feature->input_type ?? 'select') }}',
              purpose: '{{ old('purpose', $feature->purpose ?? 'filter') }}',
              isColor: {{ old('is_color', $feature->is_color ?? false) ? 'true' : 'false' }},
              locale: '{{ $locale }}',
              values: {{ \Illuminate\Support\Js::from($initialValues) }},
              addValue() { this.values.push({ id: null, value: {}, code: '', position: this.values.length, swatch: '#000000' }) },
              removeValue(i) { this.values.splice(i, 1) },
              aliasWord(alias) { const names = alias.names || {}; return names[this.locale] || Object.values(names).find(text => text) || alias.code || '' },
          }">
        @csrf
        @if ($feature->exists) @method('PUT') @endif

        {{-- The same sticky bar every edit form has: the way back, the name,
             and Save where the hand already is. --}}
        <x-admin.form-bar>
            <a href="{{ route('admin.catalog.features.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to characteristics') }}" aria-label="{{ __('Back to characteristics') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs uppercase tracking-wide opacity-50 leading-none">{{ $feature->exists ? __('Edit characteristic') : __('New characteristic') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $feature->exists ? $feature->t('name', $locale) : __('New characteristic') }}</div>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.catalog.features.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $feature->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid xl:grid-cols-[minmax(0,1fr)_22rem] gap-4 items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300"><div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Basics') }}</h3>
                    <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$feature->name ?? []" required placeholder="{{ __('Name') }}" />

                    <div class="grid md:grid-cols-2 gap-4">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }}</legend>
                            <input name="code" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('code', $feature->code) }}" class="input input-bordered w-full font-mono"
                                   placeholder="{{ __('auto from name, e.g. COLOR') }}">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Group') }}</legend>
                            <input name="group_name" list="feature-groups" value="{{ old('group_name', $feature->group?->t('name', $locale)) }}"
                                   class="input input-bordered w-full" placeholder="{{ __('Leave empty for ungrouped') }}">
                            <datalist id="feature-groups">
                                @foreach ($groups as $group)
                                    <option value="{{ $group->t('name', $locale) }}">
                                @endforeach
                            </datalist>
                        </fieldset>
                    </div>
                    <div class="text-xs opacity-60 -mt-2 flex flex-col gap-1">
                        <span>
                            {{ __('Stable id used by import/export to match this feature — and the word it is called by in a filtered address.') }}
                            @if ($feature->exists && $urlName)
                                <span class="font-mono">{{ __('For example').': /catalog?'.$urlName.'='.($urlValue ?: '…') }}</span>
                            @endif
                        </span>
                        <span>{{ __('A group: typing an existing group\'s name reuses it (case-insensitive); anything else creates a new group.') }}</span>
                    </div>
                </div></section>

                {{-- The values, as a table with its columns named: a row of four
                     unlabelled boxes said nothing about which one was the
                     order and which the code. --}}
                <template x-if="inputType === 'select'">
                    <section class="card bg-base-100 border border-base-300"><div class="card-body gap-3">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <h3 class="font-semibold">{{ __('Values') }} <span class="opacity-50 font-normal text-sm">{{ strtoupper($locale) }}</span></h3>
                            <label class="label cursor-pointer gap-2 p-0">
                                <input type="checkbox" name="is_color" value="1" x-model="isColor" class="toggle toggle-sm toggle-success">
                                <span class="label-text">{{ __('A colour — values get a swatch') }}</span>
                            </label>
                        </div>
                        <div x-show="purpose === 'brand'" class="text-xs opacity-60">
                            {{ __('Add each brand\'s name here first, save, then use "Page" on the value to add its logo, description and slug.') }}
                        </div>

                        {{-- Twins the shop already has — "тёмно‑синий" beside
                             "темно-синий" (Features\Spelling): each holds half
                             the products, and nothing else on the page says so. --}}
                        @if ($spellingGroups)
                            <div class="alert alert-warning text-sm flex flex-wrap items-center gap-2">
                                <span>{{ __('Written alike — each holds only its own products:') }}</span>
                                @foreach (array_slice($spellingGroups, 0, 3) as $group)
                                    <span class="inline-flex flex-wrap gap-1">
                                        @foreach ($group as $twin)
                                            <span class="badge badge-sm badge-ghost">{{ $twin->display($locale) }}</span>
                                        @endforeach
                                    </span>
                                @endforeach
                                @if (count($spellingGroups) > 3)
                                    <span>{{ __('and :count more', ['count' => count($spellingGroups) - 3]) }}</span>
                                @endif
                                <button type="button" class="btn btn-sm ms-auto" data-open-dialog="modal-merge-values">{{ __('Merge values') }}</button>
                            </div>
                        @endif

                        {{-- On a phone the rows keep their columns and scroll sideways. --}}
                        <div class="max-sm:overflow-x-auto">
                        <div class="flex flex-col gap-2 max-sm:min-w-[28rem]">
                            <div class="flex gap-2 items-center text-xs opacity-60 px-0.5" x-show="values.length">
                                <span class="w-16 shrink-0">{{ __('Sort order') }}</span>
                                <span class="w-16 shrink-0" x-show="isColor">{{ __('Swatch') }}</span>
                                <span class="grow">{{ __('Value') }}</span>
                                <span class="w-36 shrink-0">{{ __('Code') }}</span>
                                <span class="w-16 shrink-0"></span>
                            </div>
                            <template x-for="(row, i) in values" :key="i">
                                <div class="flex gap-2 items-start">
                                    <input type="number" x-model.number="row.position" aria-label="{{ __('Sort order') }}"
                                           class="input input-bordered w-16 shrink-0 tabular-nums">
                                    <span class="w-16 shrink-0" x-show="isColor">
                                        <input type="color" x-model="row.swatch" aria-label="{{ __('Swatch') }}"
                                               class="w-9 h-8 pointer-coarse:w-10 pointer-coarse:h-10 rounded border border-base-300 p-0.5 bg-base-100 block">
                                    </span>
                                    <div class="grow min-w-0 flex flex-col gap-1">
                                        <input type="text" x-model="row.value[locale]" aria-label="{{ __('Value') }}"
                                               :placeholder="row.value[locale] ? '' : (Object.values(row.value).find(text => text) || @js(__('Value')))"
                                               class="input input-bordered w-full placeholder:italic">
                                        {{-- What a merge folded into this value: the import and
                                             old filtered addresses still find it by these. --}}
                                        <template x-if="row.aliases && row.aliases.length">
                                            <div class="flex flex-wrap items-center gap-1 text-xs">
                                                <span class="opacity-60">{{ __('Also written:') }}</span>
                                                <template x-for="(alias, a) in row.aliases" :key="a">
                                                    <span class="badge badge-ghost badge-sm gap-0.5 pe-0.5">
                                                        <span x-text="aliasWord(alias)"></span>
                                                        <button type="button" @click="row.aliases.splice(a, 1)" class="btn btn-ghost btn-xs btn-circle min-h-0 h-4 w-4"
                                                                title="{{ __('Forget this spelling — the next import makes it a value of its own') }}" aria-label="{{ __('Forget this spelling — the next import makes it a value of its own') }}">
                                                            <x-icon name="x" size="sm" />
                                                        </button>
                                                    </span>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                    <input type="text" x-model="row.code" aria-label="{{ __('Code') }}"
                                           class="input input-bordered w-36 font-mono shrink-0" placeholder="{{ __('auto') }}">
                                    <span class="w-16 shrink-0 flex items-center justify-end gap-1">
                                        @if ($feature->exists)
                                            <a x-show="purpose === 'brand' && row.id" :href="'{{ url('admin/catalog/features/'.$feature->id.'/values') }}/' + row.id + '/edit'"
                                               class="btn btn-ghost btn-sm" title="{{ __('Edit brand page') }}">{{ __('Page') }}</a>
                                        @endif
                                        <button type="button" @click="removeValue(i)" class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                            <x-icon name="x" size="sm" />
                                        </button>
                                    </span>
                                </div>
                            </template>
                            <div x-show="! values.length" class="text-sm opacity-60">{{ __('No values yet.') }}</div>
                        </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="flex items-center gap-1">
                                <button type="button" @click="addValue()" class="btn btn-sm gap-1"><x-icon name="plus" size="sm" /> {{ __('Add value') }}</button>
                                @if ($feature->exists && $feature->values->count() > 1)
                                    <button type="button" class="btn btn-ghost btn-sm gap-1" data-open-dialog="modal-merge-values"><x-icon name="merge" size="sm" /> {{ __('Merge values') }}</button>
                                @endif
                            </div>
                            <span class="text-xs opacity-60">
                                {{ __('Only the :locale text is edited here — switch the content language at the top to edit another; a grey value is the one it falls back to.', ['locale' => strtoupper($locale)]) }}
                            </span>
                        </div>
                        <input type="hidden" name="values_json" :value="JSON.stringify(values)">
                    </div></section>
                </template>
            </div>

            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300"><div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Purpose') }}</h3>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend sr-only">{{ __('Purpose') }}</legend>
                        <select name="purpose" x-model="purpose" @change="if (purpose === 'brand') inputType = 'select'"
                                class="select select-bordered w-full">
                            @foreach ($purposes as $value => [$label, $help])
                                <option value="{{ $value }}" @selected(old('purpose', $feature->purpose ?? 'filter') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @foreach ($purposes as $value => [$label, $help])
                            <p class="text-xs opacity-60 mt-1" x-show="purpose === '{{ $value }}'" @if (old('purpose', $feature->purpose ?? 'filter') !== $value) x-cloak @endif>{{ $help }}</p>
                        @endforeach
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Input type') }}</legend>
                        <select name="input_type" x-model="inputType" :disabled="purpose === 'brand'" class="select select-bordered w-full">
                            <option value="select">{{ __('A value from a list') }}</option>
                            <option value="text">{{ __('Free text') }}</option>
                            <option value="number">{{ __('Number') }}</option>
                        </select>
                        {{-- A disabled select posts nothing; a brand is always a list. --}}
                        <template x-if="purpose === 'brand'"><input type="hidden" name="input_type" value="select"></template>
                    </fieldset>
                </div></section>

                @php
                    // A browser sends nothing at all for a group with no box
                    // ticked, which is indistinguishable from a first visit — so
                    // the form says it asked (the same marker the enquiries inbox
                    // uses for its default filter). Without it, "shown nowhere"
                    // would come back from a failed validation as "shown
                    // everywhere".
                    $shownIn = old('shown_in_set') ? (array) old('shown_in', []) : (array) ($feature->shown_in ?? \Torgash\Catalog\Features\Places::DEFAULT);
                @endphp
                <section class="card bg-base-100 border border-base-300"><div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Where it is shown') }}</h3>
                    <input type="hidden" name="shown_in_set" value="1">
                    <div class="flex flex-col gap-2.5">
                        @foreach ($places as $place)
                            <label class="flex gap-2.5 items-start cursor-pointer">
                                <input type="checkbox" name="shown_in[]" value="{{ $place['key'] }}" class="checkbox checkbox-sm mt-0.5"
                                       @checked(in_array($place['key'], $shownIn, true))>
                                <span class="text-sm leading-snug">
                                    {{ label_text($place['label'] ?? null, null, $place['key']) }}
                                    @if ($help = label_text($place['help'] ?? null))
                                        <span class="block text-xs opacity-60">{{ $help }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                        <label class="flex gap-2.5 items-start cursor-pointer">
                            <input type="checkbox" name="in_short_description" value="1" class="checkbox checkbox-sm mt-0.5"
                                   @checked(old('in_short_description', $feature->in_short_description ?? false))>
                            <span class="text-sm leading-snug">
                                {{ __('In the short description') }}
                                <span class="block text-xs opacity-60">{{ __('Stands in for the one-line description of a product that has none.') }}</span>
                            </span>
                        </label>
                    </div>
                    <p class="text-xs opacity-60">
                        {{ __('Unticking everything hides the characteristic from the shop without making it any less filterable: a published filter page keeps working, and a filter in force is still named above the products with a way to remove it.') }}
                    </p>
                </div></section>

                {{-- How the values are DRAWN — a list's values only. The control
                     underneath never changes (a link to the variation, a tick in
                     the filter form); see Feature::PRODUCT_STYLES. --}}
                <section x-show="inputType === 'select'" class="card bg-base-100 border border-base-300"><div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('How the values look') }}</h3>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Choosing a variation on the product page') }}</legend>
                        <select name="product_style" class="select w-full">
                            @foreach (['labels' => __('Labels in a row'), 'images' => __('Pictures of the variations'), 'swatches' => __('Colour swatches'), 'dropdown' => __('A drop-down list')] as $styleKey => $styleLabel)
                                <option value="{{ $styleKey }}" @selected(old('product_style', $feature->product_style ?? 'labels') === $styleKey)>{{ $styleLabel }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs opacity-60" x-show="purpose !== 'variation'">{{ __('Used when the characteristic switches variations.') }}</span>
                    </fieldset>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('In the filter panel') }}</legend>
                        <select name="filter_style" class="select w-full">
                            @foreach (['list' => __('A list of ticks'), 'chips' => __('Buttons in rows'), 'swatches' => __('Colour swatches')] as $styleKey => $styleLabel)
                                <option value="{{ $styleKey }}" @selected(old('filter_style', $feature->filter_style ?? 'list') === $styleKey)>{{ $styleLabel }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                    <p class="text-xs opacity-60" x-show="! isColor">{{ __('Colour swatches need "A colour — values get a swatch"; without it, labels and a list are drawn.') }}</p>
                </div></section>

                <section class="card bg-base-100 border border-base-300"><div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Categories') }}</h3>
                    {{-- Tree order and indented — Catalog\Support\CategoryTree. A
                         characteristic attached to a branch applies to
                         everything under it. --}}
                    <x-admin.picker
                        name="categories[]"
                        :options="$categoryPickerOptions"
                        :selected="$selectedCategories"
                        :placeholder="__('Search categories…')"
                        :empty="__('Every category')" />
                    <p class="text-xs opacity-60">{{ __('Attached to nothing, it applies everywhere. Attached to a category, it applies to that category and everything under it.') }}</p>
                </div></section>
            </div>
        </div>
    </form>

    {{-- Beside the form, never inside it: a form in a form loses its tag and
         leaks its inputs into the outer one. --}}
    @if ($feature->exists && $feature->values->count() > 1)
        @php
            // A group's main value is the one most products carry — the
            // spelling the shop has used most — and the older one on a tie.
            $mergeSuggestions = array_map(function (array $group) use ($valueProducts, $locale) {
                usort($group, fn ($a, $b) => [($valueProducts[$b->id] ?? 0), $a->id] <=> [($valueProducts[$a->id] ?? 0), $b->id]);

                return [
                    'into' => (string) $group[0]->id,
                    'values' => array_map(fn ($value) => (string) $value->id, array_slice($group, 1)),
                    'words' => array_map(fn ($value) => $value->display($locale), $group),
                ];
            }, $spellingGroups);
        @endphp
        <x-modal name="merge-values" :title="__('Merge values')" icon="merge" size="lg">
            <form method="POST" action="{{ route('admin.catalog.features.values.merge', $feature) }}" class="flex flex-col gap-3"
                  data-confirm="{{ __('Merge the ticked values into the main one? Their products, filter pages and labels move to it, and the ticked values are deleted.') }}"
                  x-data="{
                      into: '',
                      picked: [],
                      q: '',
                      counts: {{ \Illuminate\Support\Js::from((object) $valueProducts) }},
                      moving() { return this.picked.reduce((n, id) => n + (this.counts[id] || 0), 0) },
                      pick(into, values) { this.into = into; this.picked = values; this.q = '' },
                      shown(el) { const q = this.q.trim().toLowerCase().replace(/ё/g, 'е'); return ! q || el.dataset.words.includes(q) },
                  }"
                  x-init="$watch('into', id => picked = picked.filter(other => other !== id))">
                @csrf
                <p class="text-sm opacity-70">
                    {{ __('The ticked values become the main one: their products, filter pages, labels and markup rules move to it, and they are deleted. Their spellings stay with the main value, so an import finds it by them.') }}
                </p>

                @if ($mergeSuggestions)
                    <div class="flex flex-col gap-1.5">
                        <div class="text-xs font-semibold opacity-60">{{ __('Written alike') }}</div>
                        @foreach ($mergeSuggestions as $suggestion)
                            <div class="flex items-center justify-between gap-3 rounded-box bg-base-200/60 px-3 py-1.5">
                                <span class="flex flex-wrap gap-1 min-w-0">
                                    @foreach ($suggestion['words'] as $word)
                                        <span class="badge badge-sm badge-ghost">{{ $word }}</span>
                                    @endforeach
                                </span>
                                <button type="button" class="btn btn-ghost btn-sm shrink-0"
                                        x-on:click="pick(@js($suggestion['into']), @js($suggestion['values']))">{{ __('Pick') }}</button>
                            </div>
                        @endforeach
                    </div>
                @endif

                <input type="search" x-model="q" class="input input-bordered input-sm w-full" placeholder="{{ __('Find a value') }}" aria-label="{{ __('Find a value') }}">

                <div class="border border-base-300 rounded-box max-h-80 overflow-y-auto">
                    <table class="table table-sm">
                        <thead class="sticky top-0 bg-base-100 z-10">
                            <tr>
                                <th class="w-16 text-center">{{ __('Main value') }}</th>
                                <th class="w-16 text-center">{{ __('Merge in') }}</th>
                                <th>{{ __('Value') }}</th>
                                <th class="text-right">{{ __('Products') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($feature->values as $value)
                                <tr x-show="shown($el)" data-words="{{ \Torgash\Catalog\Features\Spelling::key(implode(' ', array_filter((array) $value->value, 'is_string')).' '.$value->code) }}">
                                    <td class="text-center">
                                        <input type="radio" name="into" value="{{ $value->id }}" x-model="into" class="radio radio-sm" aria-label="{{ __('Main value') }}">
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" name="values[]" value="{{ $value->id }}" x-model="picked" class="checkbox checkbox-sm"
                                               :disabled="into === '{{ $value->id }}'" aria-label="{{ __('Merge in') }}">
                                    </td>
                                    <td>
                                        {{ $value->display($locale) }}
                                        <span class="font-mono text-xs opacity-50">{{ $value->code }}</span>
                                    </td>
                                    <td class="text-right tabular-nums">{{ $valueProducts[$value->id] ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                    <span class="text-sm opacity-70 me-auto" x-show="into && picked.length" x-cloak
                          x-text="@js(__('Values to merge: :values. Products to move: :products.')).replace(':values', picked.length).replace(':products', moving())"></span>
                    <button type="button" class="btn btn-ghost" data-close-dialog>{{ __('Cancel') }}</button>
                    <button class="btn btn-primary gap-1.5" :disabled="! into || ! picked.length"><x-icon name="merge" size="sm" /> {{ __('Merge values') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
@endsection
