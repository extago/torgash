@extends('admin.layout')

@section('title', $rule->exists ? __('Edit rule') : __('New rule'))
@section('own_heading', '1')

@section('content')
    @php $chosen = array_map('strval', (array) old('category_ids', $rule->category_ids ?? [])); @endphp

    <form method="POST"
          action="{{ $rule->exists ? route('admin.catalog.filter-rules.update', $rule) : route('admin.catalog.filter-rules.store') }}"
          id="filter-rule-form" class="flex flex-col gap-4">
        @csrf
        @if ($rule->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.catalog.filter-rules.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to rules') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Filter page rule') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $rule->exists ? $rule->name : __('New rule') }}</div>
            </div>
            @if ($rule->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li><button type="submit" form="filter-rule-generate"><x-icon name="wand-sparkles" /> {{ __('Generate pages') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="filter-rule-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.catalog.filter-rules.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $rule->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
        <div class="flex flex-col gap-4 min-w-0">
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('What it makes') }}</h3>

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }}</legend>
                    <input name="name" value="{{ old('name', $rule->name) }}" class="input input-bordered w-full" required
                           placeholder="{{ __('A page per make') }}">
                    <p class="text-xs opacity-60">{{ __('Your own name for the rule. Never shown to a visitor.') }}</p>
                </fieldset>

                {{-- One axis per rule: values of a characteristic, or towns.
                     Both at once would publish every town times every make, so
                     the choice is a radio and the server keeps only the side
                     that was chosen (see FilterRulesController::data()). --}}
                @php $axis = old('axis', $rule->exists ? $rule->axis() : 'feature'); @endphp

                <div x-data="{ axis: @js($axis) }">
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('A page per') }}</legend>
                        <div class="flex flex-wrap gap-4">
                            <label class="label cursor-pointer justify-start gap-2">
                                <input type="radio" name="axis" value="feature" class="radio radio-sm" x-model="axis">
                                <span class="label-text">{{ __('value of a characteristic') }}</span>
                            </label>
                            @if ($placesAvailable)
                                <label class="label cursor-pointer justify-start gap-2">
                                    <input type="radio" name="axis" value="place" class="radio radio-sm" x-model="axis">
                                    <span class="label-text">{{ __('town') }}</span>
                                </label>
                            @endif
                            <label class="label cursor-pointer justify-start gap-2">
                                <input type="radio" name="axis" value="price" class="radio radio-sm" x-model="axis">
                                <span class="label-text">{{ __('price bracket') }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset p-0" x-show="axis === 'feature'">
                        <legend class="fieldset-legend">{{ __('Characteristic') }}</legend>
                        <select name="feature_id" class="select select-bordered w-full">
                            @foreach ($features as $feature)
                                <option value="{{ $feature->id }}" @selected(old('feature_id', $rule->feature_id) == $feature->id)>
                                    {{ $feature->t('name', admin_content_locale()) }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs opacity-60">{{ __('One page per value of it. A page combining two characteristics is written by hand — a rule that multiplied them would publish the square of the catalogue.') }}</p>
                    </fieldset>

                    @if ($placesAvailable)
                        @include('torgash.locations::partials.place-picker-script')

                        <fieldset class="fieldset p-0" x-show="axis === 'place'">
                            <legend class="fieldset-legend">{{ __('Towns') }}</legend>

                            {{-- The outer scope holds the chosen list; the picker
                                 inside it finds one town at a time and calls
                                 addChosen() through the suggestions partial's own
                                 $onPick seam. Alpine's scope chain is what lets an
                                 inner component call a method of the outer one. --}}
                            <div x-data="{
                                     chosen: @js($places),
                                     addChosen(item) {
                                         if (! this.chosen.some((c) => c.id == item.id)) {
                                             this.chosen.push({ id: item.id, label: item.label });
                                         }
                                     },
                                     drop(id) { this.chosen = this.chosen.filter((c) => c.id != id); },
                                 }">
                                <div x-data="placePicker({ endpoint: '{{ route('places.suggest') }}' })" class="relative">
                                    <input type="text" class="input input-bordered w-full" autocomplete="off"
                                           :value="label"
                                           @input="search($event.target.value)" @focus="open = true"
                                           placeholder="{{ __('Start typing a town') }}">

                                    @include('torgash.locations::partials.place-suggestions', ['onPick' => "; addChosen(item); label = ''"])
                                </div>

                                <div class="flex flex-wrap gap-1.5 mt-2">
                                    <template x-for="town in chosen" :key="town.id">
                                        <span class="badge badge-outline gap-1.5 py-3">
                                            <span x-text="town.label"></span>
                                            <input type="hidden" name="place_ids[]" :value="town.id">
                                            <button type="button" class="opacity-50 hover:opacity-100" @click="drop(town.id)">&times;</button>
                                        </span>
                                    </template>
                                </div>

                                <p class="text-xs opacity-60 mt-2">
                                    {{ __('One page per town, in each category below: the same products as the category, under words of their own. Choose the towns the shop actually sells into — "every town" would be a hundred thousand pages.') }}
                                </p>
                            </div>
                        </fieldset>
                    @endif

                    <fieldset class="fieldset p-0" x-show="axis === 'price'">
                        <legend class="fieldset-legend">{{ __('Price boundaries') }}</legend>
                        <textarea name="price_bounds_text" rows="4" class="textarea textarea-bordered w-full font-mono text-sm"
                                  placeholder="{{ "-30000\n30000-60000\n60000-" }}">{{ old('price_bounds_text', $rule->exists ? $rule->priceBoundsText() : '') }}</textarea>
                        <p class="text-xs opacity-60">
                            {{ __('One boundary per line, in the shop\'s own currency, written as "min-max" — either side may be empty. One page per line, in this order. Never computed from the catalogue: a boundary the shop did not write does not move when prices do.') }}
                        </p>
                    </fieldset>
                </div>

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Categories') }}</legend>
                    <div class="border border-base-300 rounded-box p-3 flex flex-col gap-1 max-h-64 overflow-y-auto">
                        @foreach ($categories as $catId => $catLabel)
                            <label class="label cursor-pointer justify-start gap-2 py-0.5">
                                <input type="checkbox" name="category_ids[]" value="{{ $catId }}" class="checkbox checkbox-sm"
                                       @checked(in_array((string) $catId, $chosen, true))>
                                <span class="label-text">{{ $catLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="text-xs opacity-60">{{ __('None ticked means every category.') }}</p>
                </fieldset>

                <label class="label cursor-pointer justify-start gap-2">
                    <input type="checkbox" name="with_children" value="1" class="toggle toggle-sm toggle-primary" @checked(old('with_children', $rule->with_children ?? true))>
                    <span class="label-text">{{ __('…and everything under them') }}</span>
                </label>
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('How the pages are named') }}</h3>

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Address') }}</legend>
                    <input name="slug_template" value="{{ old('slug_template', $rule->slug_template ?? '') }}" class="input input-bordered w-full font-mono text-sm"
                           placeholder="{{ match ($axis) { 'place' => '{location:1}', 'price' => '{price_min}-{price_max}', default => '{filter}' } }}">
                    <p class="text-xs opacity-60">{{ __('Tokens: {filter} — the value, {location:1} — the town, {price_min}/{price_max} — the bracket\'s own boundary, {category} — the category. The result is slugified, from the base language, so an address never moves. An address is never changed afterwards.') }}</p>
                </fieldset>

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Page name') }} ({{ strtoupper(admin_content_locale()) }})</legend>
                    <input name="page_name" value="{{ old('page_name', $rule->page_name[admin_content_locale()] ?? '') }}" class="input input-bordered w-full"
                           placeholder="{{ $axis === 'price' ? '{category} [от {price_min}][ до {price_max}]' : '{filter} {category|lower}' }}">
                    <p class="text-xs opacity-60">{{ __('What the page is called in the category list and in the breadcrumb. Left empty, it names itself after the values. Title, description and H1 come from the SEO templates.') }}</p>
                </fieldset>
            </div>
        </div>

        </div>

        <div class="flex flex-col gap-4">
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Publication') }}</h3>

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Least products for a page') }}</legend>
                    <input name="min_products" type="number" min="0" value="{{ old('min_products', $rule->min_products ?? 1) }}" class="input input-bordered w-full">
                    <p class="text-xs opacity-60">{{ __('A combination with fewer than this gets no page. One that falls below it later leaves the sitemap but keeps answering — a published address is never withdrawn.') }}</p>
                </fieldset>

                <div class="flex flex-col gap-1">
                    <label class="label cursor-pointer justify-start items-start gap-2 whitespace-normal">
                        <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $rule->enabled ?? true))>
                        <span class="label-text">{{ __('Enabled') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start items-start gap-2 whitespace-normal">
                        <input type="checkbox" name="in_sitemap" value="1" class="toggle toggle-sm toggle-primary" @checked(old('in_sitemap', $rule->in_sitemap ?? true))>
                        <span class="label-text">{{ __('Its pages go in the sitemap') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start items-start gap-2 whitespace-normal">
                        <input type="checkbox" name="in_list" value="1" class="toggle toggle-sm toggle-primary" @checked(old('in_list', $rule->in_list ?? true))>
                        <span class="label-text">{{ __('Its pages are linked from the category') }}</span>
                    </label>
                </div>

                <div class="text-xs opacity-60">{{ __('Read when a page is created and never afterwards: a page taken out of the strip by hand must not come back on the next run.') }}</div>

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                    <input name="position" type="number" value="{{ old('position', $rule->position ?? 0) }}" class="input input-bordered w-full" placeholder="0">
                </fieldset>
            </div>
        </div>

        @if ($rule->exists)
            {{-- The rule's work so far, and the button that does more of it. --}}
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3 text-sm">
                    <h3 class="font-semibold">{{ __('Pages it made') }}</h3>
                    @php $madeCount = $rule->pages()->count(); @endphp
                    <div class="flex justify-between gap-2 items-center">
                        <span class="opacity-60">{{ __('Pages') }}</span>
                        @if ($madeCount > 0)
                            <a href="{{ route('admin.catalog.filter-pages.index', ['rule' => $rule->id]) }}" class="link link-hover font-semibold tabular-nums">{{ $madeCount }}</a>
                        @else
                            <span class="opacity-50">{{ __('none yet') }}</span>
                        @endif
                    </div>
                    <button type="submit" form="filter-rule-generate" class="btn btn-outline btn-sm gap-1.5">
                        <x-icon name="wand-sparkles" size="sm" /> {{ __('Generate pages') }}
                    </button>
                    <p class="text-xs opacity-60">{{ __('Runs in the background; watch it under System → Background tasks.') }} {{ __('Save first — a run uses the rule as it is stored.') }}</p>
                </div>
            </div>
        @endif
        </div>
        </div>
    </form>

    {{-- Beside the form, never inside it: a nested <form> loses its tag and
         leaks its inputs into the outer submit. --}}
    @if ($rule->exists)
        <form id="filter-rule-generate" method="POST" action="{{ route('admin.catalog.filter-rules.generate-one', $rule) }}" class="hidden">@csrf</form>
        <form id="filter-rule-delete" method="POST" action="{{ route('admin.catalog.filter-rules.destroy', $rule) }}" class="hidden"
              data-confirm="{{ __('Delete this rule? The pages it made stay published.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
