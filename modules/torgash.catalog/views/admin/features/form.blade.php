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
            ])->values()
            : collect();
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
          }">
        @csrf
        @if ($feature->exists) @method('PUT') @endif

        {{-- The same sticky bar every edit form has: the way back, the name,
             and Save where the hand already is. --}}
        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.catalog.features.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to characteristics') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs uppercase tracking-wide opacity-50 leading-none">{{ $feature->exists ? __('Edit characteristic') : __('New characteristic') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $feature->exists ? $feature->t('name', $locale) : __('New characteristic') }}</div>
            </div>
            <a href="{{ route('admin.catalog.features.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $feature->exists ? __('Save') : __('Create') }}</button>
        </div>

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
                            <input name="code" value="{{ old('code', $feature->code) }}" class="input input-bordered w-full font-mono"
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
                                <input type="checkbox" name="is_color" value="1" x-model="isColor" class="toggle toggle-sm toggle-primary">
                                <span class="label-text">{{ __('A colour — values get a swatch') }}</span>
                            </label>
                        </div>
                        <div x-show="purpose === 'brand'" class="text-xs opacity-60">
                            {{ __('Add each brand\'s name here first, save, then use "Page" on the value to add its logo, description and slug.') }}
                        </div>

                        <div class="flex flex-col gap-2">
                            <div class="flex gap-2 items-center text-xs opacity-60 px-0.5" x-show="values.length">
                                <span class="w-16 shrink-0">{{ __('Sort order') }}</span>
                                <span class="w-16 shrink-0" x-show="isColor">{{ __('Swatch') }}</span>
                                <span class="grow">{{ __('Value') }}</span>
                                <span class="w-36 shrink-0">{{ __('Code') }}</span>
                                <span class="w-16 shrink-0"></span>
                            </div>
                            <template x-for="(row, i) in values" :key="i">
                                <div class="flex gap-2 items-center">
                                    <input type="number" x-model.number="row.position" aria-label="{{ __('Sort order') }}"
                                           class="input input-bordered w-16 shrink-0 tabular-nums">
                                    <span class="w-16 shrink-0" x-show="isColor">
                                        <input type="color" x-model="row.swatch" aria-label="{{ __('Swatch') }}"
                                               class="w-9 h-8 rounded border border-base-300 p-0.5 bg-base-100 block">
                                    </span>
                                    <input type="text" x-model="row.value[locale]" aria-label="{{ __('Value') }}"
                                           :placeholder="row.value[locale] ? '' : (Object.values(row.value).find(text => text) || @js(__('Value')))"
                                           class="input input-bordered grow min-w-0 placeholder:italic">
                                    <input type="text" x-model="row.code" aria-label="{{ __('Code') }}"
                                           class="input input-bordered w-36 font-mono shrink-0" placeholder="{{ __('auto') }}">
                                    <span class="w-16 shrink-0 flex items-center justify-end gap-1">
                                        @if ($feature->exists)
                                            <a x-show="purpose === 'brand' && row.id" :href="'{{ url('admin/catalog/features/'.$feature->id.'/values') }}/' + row.id + '/edit'"
                                               class="btn btn-ghost btn-sm" title="{{ __('Edit brand page') }}">{{ __('Page') }}</a>
                                        @endif
                                        <button type="button" @click="removeValue(i)" class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Remove') }}">
                                            <x-icon name="x" size="sm" />
                                        </button>
                                    </span>
                                </div>
                            </template>
                            <div x-show="! values.length" class="text-sm opacity-60">{{ __('No values yet.') }}</div>
                        </div>
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <button type="button" @click="addValue()" class="btn btn-ghost btn-sm gap-1"><x-icon name="plus" size="sm" /> {{ __('Add value') }}</button>
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
@endsection
