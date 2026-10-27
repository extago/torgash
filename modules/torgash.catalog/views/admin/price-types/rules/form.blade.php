@extends('admin.layout')

@section('title', $rule->exists ? __('Edit markup rule') : __('New markup rule'))
@section('own_heading', '1')

@section('content')
    @php
        $locale = admin_content_locale();
        $typeName = $priceType->t('name', $locale) ?: $priceType->code;
        $selectedCategoryIds = $rule->exists ? $rule->categories->pluck('id')->all() : [];
        $selectedFeatureValueIds = $rule->exists ? $rule->featureValues->pluck('id')->all() : [];
        $major = fn ($minor, $default = '') => $minor !== null ? $minor / 100 : $default;
    @endphp

    {{-- The example at the bottom is PriceTypeMarkupRule::apply() done in the browser, on
         the same minor units, so what it shows is what the server will do. --}}
    <form method="POST"
          action="{{ $rule->exists ? route('admin.catalog.price-types.rules.update', [$priceType, $rule]) : route('admin.catalog.price-types.rules.store', $priceType) }}"
          class="flex flex-col gap-4"
          x-data="{
              mode: '{{ old('rounding_mode', $rule->rounding_mode ?? 'nearest') }}',
              markupType: '{{ old('markup_type', $rule->markup_type ?? 'percent') }}',
              markup: {{ \Illuminate\Support\Js::from((string) old('markup_value', $rule->exists ? $rule->markup_value / 100 : '')) }},
              roundTo: {{ \Illuminate\Support\Js::from((string) old('round_to', $rule->exists ? $rule->round_to / 100 : '100')) }},
              direction: '{{ old('round_direction', $rule->round_direction ?? 'nearest') }}',
              offset: {{ \Illuminate\Support\Js::from((string) old('psychological_offset', $major($rule->psychological_offset, '0.01'))) }},
              sampleCost: '1000',
              example() {
                  const cost = Math.round(parseFloat(this.sampleCost) * 100);
                  const m = Math.round(parseFloat(this.markup) * 100);
                  const n = Math.max(1, Math.round(parseFloat(this.roundTo) * 100));
                  if (! Number.isFinite(cost) || ! Number.isFinite(m)) return '—';
                  const marked = this.markupType === 'fixed' ? cost + m : Math.round(cost * (10000 + m) / 10000);
                  let price;
                  if (this.mode === 'psychological') {
                      price = Math.max(0, Math.floor(marked / n) * n - Math.round((parseFloat(this.offset) || 0) * 100));
                  } else {
                      price = this.direction === 'up' ? Math.ceil(marked / n) * n
                          : this.direction === 'down' ? Math.floor(marked / n) * n
                          : Math.round(marked / n) * n;
                  }
                  return (price / 100).toLocaleString(undefined, { minimumFractionDigits: price % 100 ? 2 : 0, maximumFractionDigits: 2 });
              },
          }">
        @csrf
        @if ($rule->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.catalog.price-types.edit', $priceType) }}#rules" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to the price type') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Markup rule') }} · {{ $typeName }}</div>
                <div class="font-semibold truncate leading-tight">{{ $rule->exists ? ($rule->t('name', $locale) ?: __('Rule')) : __('New markup rule') }}</div>
            </div>

            @if ($rule->exists && (($prevRule ?? null) || ($nextRule ?? null)))
                <div class="join">
                    @if ($prevRule ?? null)
                        <a href="{{ route('admin.catalog.price-types.rules.edit', [$priceType, $prevRule]) }}" class="btn btn-sm join-item" title="{{ __('Previous rule') }}">
                            <x-icon name="chevron-left" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-left" /></span>
                    @endif
                    @if ($nextRule ?? null)
                        <a href="{{ route('admin.catalog.price-types.rules.edit', [$priceType, $nextRule]) }}" class="btn btn-sm join-item" title="{{ __('Next rule') }}">
                            <x-icon name="chevron-right" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-right" /></span>
                    @endif
                </div>
            @endif

            @if ($rule->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-56 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                        <li>
                            <button type="submit" form="rule-delete" class="text-error" data-confirm="{{ __('Delete this rule?') }}">
                                <x-icon name="trash-2" /> {{ __('Delete this rule') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.catalog.price-types.edit', $priceType) }}#rules" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $rule->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Which products') }}</h3>
                        <p class="text-xs opacity-60 -mt-2">{{ __('A product must meet every condition filled in. All empty — every product.') }}</p>
                        <x-admin.picker name="category_ids[]" :label="__('Categories')" :options="$categoryOptions" :selected="$selectedCategoryIds" :empty="__('Any category')" />
                        <p class="text-xs opacity-60 -mt-2">{{ __('subcategories included') }}</p>
                        <x-admin.picker name="feature_value_ids[]" :label="__('Characteristic values')" :options="$featureValueOptions" :selected="$selectedFeatureValueIds" :empty="__('Any value')" />
                        <div class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Cost price from') }}</legend>
                                <input type="number" step="0.01" min="0" name="cost_price_min" value="{{ old('cost_price_min', $major($rule->cost_price_min)) }}"
                                       class="input input-bordered w-full" placeholder="0.00">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Cost price up to') }}</legend>
                                <input type="number" step="0.01" min="0" name="cost_price_max" value="{{ old('cost_price_max', $major($rule->cost_price_max)) }}"
                                       class="input input-bordered w-full" placeholder="0.00">
                            </fieldset>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Price') }}</h3>
                        <div class="flex flex-wrap items-end gap-3">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Markup over the cost price') }} *</legend>
                                <div class="join">
                                    <label class="btn join-item" :class="markupType === 'percent' && 'btn-active'">
                                        <input type="radio" name="markup_type" value="percent" x-model="markupType" class="hidden"> %
                                    </label>
                                    <label class="btn join-item" :class="markupType === 'fixed' && 'btn-active'">
                                        <input type="radio" name="markup_type" value="fixed" x-model="markupType" class="hidden"> {{ __('amount') }}
                                    </label>
                                    <input type="number" step="0.01" required name="markup_value" x-model="markup"
                                           class="input input-bordered join-item w-36" :placeholder="markupType === 'percent' ? '35' : '500'">
                                </div>
                            </fieldset>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Rounding') }}</h3>
                        <div class="join">
                            <label class="btn btn-sm join-item" :class="mode === 'nearest' && 'btn-active'">
                                <input type="radio" name="rounding_mode" value="nearest" x-model="mode" class="hidden"> {{ __('To a step') }}
                            </label>
                            <label class="btn btn-sm join-item" :class="mode === 'psychological' && 'btn-active'">
                                <input type="radio" name="rounding_mode" value="psychological" x-model="mode" class="hidden"> {{ __('A ...99 ending') }}
                            </label>
                        </div>

                        {{-- ONE "round to" box for both modes: two inputs with one name are
                             both submitted, and the hidden one used to win. --}}
                        <div class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend" x-text="mode === 'nearest' ? @js(__('Round to')) : @js(__('Round down to'))"></legend>
                                <input type="number" step="0.01" min="0.01" name="round_to" x-model="roundTo" class="input input-bordered w-full" placeholder="100">
                            </fieldset>
                            <fieldset class="fieldset p-0" x-show="mode === 'nearest'"><legend class="fieldset-legend">{{ __('Direction') }}</legend>
                                <select name="round_direction" x-model="direction" class="select select-bordered w-full" :disabled="mode !== 'nearest'">
                                    <option value="nearest">{{ __('To the nearest') }}</option>
                                    <option value="up">{{ __('Up') }}</option>
                                    <option value="down">{{ __('Down') }}</option>
                                </select>
                            </fieldset>
                            <fieldset class="fieldset p-0" x-show="mode === 'psychological'" x-cloak><legend class="fieldset-legend">{{ __('Then subtract') }}</legend>
                                <input type="number" step="0.01" min="0" name="psychological_offset" x-model="offset" class="input input-bordered w-full"
                                       placeholder="0.01" :disabled="mode !== 'psychological'">
                            </fieldset>
                        </div>

                        <div class="rounded-box bg-base-200 p-3 flex flex-wrap items-center gap-2 text-sm">
                            <span class="opacity-70">{{ __('Example: cost') }}</span>
                            <input type="number" step="0.01" min="0" x-model="sampleCost" class="input input-bordered input-sm w-28" aria-label="{{ __('Example cost') }}">
                            <x-icon name="arrow-right" size="sm" class="opacity-50" />
                            <span class="font-semibold tabular-nums" x-text="example()"></span>
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-primary" @checked(old('enabled', $rule->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Priority') }}</legend>
                            <input type="number" name="sort" value="{{ old('sort', $rule->sort ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Lower is tried first; the first rule a product meets decides.') }}</p>
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Name') }}</h3>
                        <input name="name" value="{{ old('name', $rule->t('name', $locale)) }}"
                               class="input input-bordered w-full" placeholder="{{ __('e.g. Displays, 20–50% margin') }}">
                        <p class="text-xs opacity-60">{{ __('For you, in the list of rules. Not shown to customers.') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($rule->exists)
        <form id="rule-delete" method="POST" action="{{ route('admin.catalog.price-types.rules.destroy', [$priceType, $rule]) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
