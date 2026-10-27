@extends('admin.layout')

@section('title', $label->exists ? __('Edit label') : __('New label'))
@section('own_heading', '1')

@section('content')
    @php
        $locale = admin_content_locale();
        $initialRules = collect($label->conditions['rules'] ?? [])->map(fn ($r) => [
            'field' => $r['field'],
            'operator' => $r['operator'] ?? 'gte',
            'value' => $r['field'] === 'category' ? '' : ($r['value'] ?? ''),
            'feature_id' => $r['feature_id'] ?? '',
            'category_ids' => $r['field'] === 'category' ? ($r['value'] ?? []) : [],
        ])->values();
        $featureValuesMap = $features->mapWithKeys(fn ($f) => [
            $f->id => $f->values->map(fn ($v) => ['id' => $v->id, 'label' => $v->display($locale)])->values(),
        ]);
        $positions = [
            'top-left' => __('Top left'),
            'top-right' => __('Top right'),
            'bottom-left' => __('Bottom left'),
            'bottom-right' => __('Bottom right'),
        ];
        // The product page's places off the photo (LabelsController::DETAIL_ROWS).
        $detailPositions = $positions + [
            'above-description' => __('Over the short description'),
            'under-buy' => __('Under the buy button'),
            'hidden' => __('Not shown'),
        ];
        $listPositions = $positions + ['hidden' => __('Not shown')];
        // The shop's colours (PLAN §52): the admin's own stylesheet knows only
        // the theme's defaults, so a badge "in the accent" is drawn here with
        // the accent the shop chose — on the swatches and the previews only.
        $labelPalette = app(\App\Core\Themes\ShopPalette::class);
        $labelPaletteVars = $labelPalette->styleVars();
        $ruleFields = [
            'price' => __('Price'),
            'stock' => __('Stock'),
            'age_days' => __('Days since added'),
            'category' => __('Category'),
            'feature' => __('Characteristic'),
            'discount' => __('Discount, %'),
            'rrp' => __('Priced below the RRP'),
            'views' => __('Views'),
            'sales' => __('Units sold, all time'),
        ];
    @endphp

    <form method="POST" enctype="multipart/form-data"
          action="{{ $label->exists ? route('admin.catalog.labels.update', $label) : route('admin.catalog.labels.store') }}"
          class="flex flex-col gap-4"
          x-data="{
              style: '{{ old('style', $label->style ?? 'text') }}',
              color: '{{ old('color', $label->color ?? 'primary') }}',
              previewName: {{ \Illuminate\Support\Js::from(old('name', $label->name[$locale] ?? '')) }},
              match: '{{ old('match', $label->conditions['match'] ?? 'all') }}',
              rules: {{ \Illuminate\Support\Js::from($initialRules) }},
              featureValues: {{ \Illuminate\Support\Js::from($featureValuesMap) }},
              addRule() { this.rules.push({ field: 'price', operator: 'gte', value: '', feature_id: '', category_ids: [] }) },
              removeRule(i) { this.rules.splice(i, 1) },
              sizeList: '{{ old('size_list', $label->size_list ?? 'sm') }}',
              sizeDetail: '{{ old('size_detail', $label->size_detail ?? 'sm') }}',
              shape: '{{ old('image_shape', $label->image_shape ?? 'as_is') }}',
              lightBg: '{{ old('colors.light.bg', $label->custom_colors['light']['bg'] ?? '#111827') }}',
              lightText: '{{ old('colors.light.text', $label->custom_colors['light']['text'] ?? '#ffffff') }}',
              darkOn: {{ old('colors_dark', isset($label->custom_colors['dark']) ? 1 : 0) ? 'true' : 'false' }},
              darkBg: '{{ old('colors.dark.bg', $label->custom_colors['dark']['bg'] ?? '#f3f4f6') }}',
              darkText: '{{ old('colors.dark.text', $label->custom_colors['dark']['text'] ?? '#111827') }}',
              badgeClass(size) { return 'badge badge-' + size + (this.color === 'custom' ? '' : ' badge-' + this.color) },
              badgeStyle(dark) {
                  if (this.color !== 'custom') return '';
                  const bg = dark && this.darkOn ? this.darkBg : this.lightBg;
                  const fg = dark && this.darkOn ? this.darkText : this.lightText;
                  return 'background:' + bg + ';border-color:' + bg + ';color:' + fg;
              },
          }">
        @csrf
        @if ($label->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.catalog.labels.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to labels') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $label->exists ? __('Edit label') : __('New label') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $label->exists ? ($label->name_for_admin ?: '—') : __('New label') }}</div>
            </div>

            @if ($label->exists && (($prevLabel ?? null) || ($nextLabel ?? null)))
                <div class="join">
                    @if ($prevLabel ?? null)
                        <a href="{{ route('admin.catalog.labels.edit', $prevLabel) }}" class="btn btn-sm join-item" title="{{ __('Previous label') }}">
                            <x-icon name="chevron-left" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-left" /></span>
                    @endif
                    @if ($nextLabel ?? null)
                        <a href="{{ route('admin.catalog.labels.edit', $nextLabel) }}" class="btn btn-sm join-item" title="{{ __('Next label') }}">
                            <x-icon name="chevron-right" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-right" /></span>
                    @endif
                </div>
            @endif

            @if ($label->exists)
                {{-- "Delete" submits the standalone form below through the HTML
                     "form" attribute — a nested <form> would leak its
                     _method=DELETE into this label's own submit. --}}
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
                            <button type="submit" form="label-delete" class="text-error"
                                    data-confirm="{{ __('Delete this label? It is removed from every product.') }}">
                                <x-icon name="trash-2" /> {{ __('Delete this label') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.catalog.labels.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $label->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            {{-- Left: what the badge SAYS, how it looks, and which products get it. --}}
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Text') }}</h3>

                        <x-admin.translatable-field name="name" label="{{ __('Name on the badge') }}" :value="$label->name ?? []"
                                                     placeholder="{{ __('e.g. New') }}" x-model="previewName" />
                        <div class="text-xs opacity-60 -mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span>{{ __('Worked out per product:') }}</span>
                            <span><code>[discount]</code> {{ __('discount in percent') }}</span>
                            <span><code>[price]</code> <code>[old_price]</code> {{ __('in the visitor\'s currency') }}</span>
                            <span><code>[views]</code> <code>[sales]</code> {{ __('plain counts') }}</span>
                            <span><code>[feature:ram]</code> {{ __("the product's answer for the characteristic with this code; a product without one gets no label") }}</span>
                        </div>

                        <x-admin.translatable-field name="tooltip" label="{{ __('Hint over the badge') }}" :value="$label->tooltip ?? []"
                                                     placeholder="{{ __('e.g. A French brand') }}" />
                        <p class="text-xs opacity-60 -mt-2">{{ __('Opens when the pointer rests on the badge, or on a tap on the product page. Empty — no hint.') }}</p>

                        {{-- Distinct from the badge above on purpose: the badge is
                             per-product and may carry placeholders, a section heading
                             has no product to resolve them against. --}}
                        <x-admin.translatable-field name="section_title" label="{{ __('Heading of its section on the storefront') }}"
                                                     :value="$label->section_title ?? []"
                                                     placeholder="{{ __('e.g. On sale') }}" />
                        <p class="text-xs opacity-60 -mt-2">{{ __('A home-page block can list the products carrying this label under this heading. Empty — the badge\'s name without its placeholders.') }}</p>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Internal name') }} *</legend>
                            <input name="name_for_admin" value="{{ old('name_for_admin', $label->name_for_admin) }}" required
                                   class="input input-bordered w-full" placeholder="{{ __('Seen only in the admin, e.g. New arrivals, 30 days') }}">
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Look') }}</h3>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Badge type') }}</legend>
                            <div class="join">
                                <label class="btn btn-sm join-item" :class="style === 'text' && 'btn-active'">
                                    <input type="radio" name="style" value="text" x-model="style" class="hidden"> {{ __('Text badge') }}
                                </label>
                                <label class="btn btn-sm join-item" :class="style === 'image' && 'btn-active'">
                                    <input type="radio" name="style" value="image" x-model="style" class="hidden"> {{ __('Picture') }}
                                </label>
                                <label class="btn btn-sm join-item" :class="style === 'tile' && 'btn-active'">
                                    <input type="radio" name="style" value="tile" x-model="style" class="hidden"> {{ __('Tile') }}
                                </label>
                            </div>
                            <p class="label text-xs opacity-60 whitespace-normal" x-show="style === 'tile'" x-cloak>{{ __('A square with a picture and the words under it — a pictogram. Upload a picture (SVG too) or name a Lucide icon.') }}</p>
                        </fieldset>

                        <fieldset class="fieldset p-0" x-show="style !== 'image'" style="{{ $labelPaletteVars }}"><legend class="fieldset-legend">{{ __('Colour') }}</legend>
                            {{-- The colours themselves, not their names: the theme
                                 decides what "accent" looks like, and this is it. --}}
                            <div class="flex flex-wrap gap-2">
                                @foreach (\Torgash\Catalog\Http\Admin\LabelsController::COLORS as $colorName)
                                    <label class="cursor-pointer rounded-lg p-1 border-2"
                                           :class="color === '{{ $colorName }}' ? 'border-primary' : 'border-transparent'">
                                        <input type="radio" name="color" value="{{ $colorName }}" x-model="color" class="hidden">
                                        <span class="badge badge-{{ $colorName }}" x-text="previewName || @js(__('Badge'))"></span>
                                    </label>
                                @endforeach
                                <label class="cursor-pointer rounded-lg p-1 border-2"
                                       :class="color === 'custom' ? 'border-primary' : 'border-transparent'">
                                    <input type="radio" name="color" value="custom" x-model="color" class="hidden">
                                    <span class="badge gap-1" :style="'background:' + lightBg + ';border-color:' + lightBg + ';color:' + lightText">
                                        <x-icon name="palette" size="sm" /> {{ __('Own colours') }}
                                    </span>
                                </label>
                            </div>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('The theme\'s colours change with the light and dark theme by themselves; your own are set for each.') }}</p>
                        </fieldset>

                        <div x-show="style !== 'image' && color === 'custom'" x-cloak class="grid sm:grid-cols-2 gap-4 rounded-box bg-base-200 p-4">
                            <div class="flex flex-col gap-2">
                                <div class="text-sm font-medium flex items-center gap-2"><x-icon name="sun" size="sm" /> {{ __('Light theme') }}</div>
                                {{-- The shop's palette first: a label that matches the
                                     theme is one press away, any colour still is. --}}
                                <div class="flex flex-wrap gap-1.5" role="group" aria-label="{{ __("The shop's colours") }}">
                                    @foreach ($labelPalette->colours() as $paletteRole => $paletteHex)
                                        <button type="button" class="w-6 h-6 rounded-full ring-1 ring-base-300" style="background: {{ $paletteHex }}"
                                                x-on:click="lightBg = @js($paletteHex); lightText = @js(\App\Core\Support\Colour::ink($paletteHex))"
                                                title="{{ $paletteHex }}" aria-label="{{ $paletteHex }}"></button>
                                    @endforeach
                                </div>
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="color" name="colors[light][bg]" x-model="lightBg" class="w-9 h-9 rounded cursor-pointer border border-base-300"> {{ __('Background') }}
                                </label>
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="color" name="colors[light][text]" x-model="lightText" class="w-9 h-9 rounded cursor-pointer border border-base-300"> {{ __('Text') }}
                                </label>
                            </div>
                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-medium flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="colors_dark" value="1" x-model="darkOn" class="checkbox checkbox-sm">
                                    <x-icon name="moon" size="sm" /> {{ __('Other colours in the dark theme') }}
                                </label>
                                <template x-if="darkOn">
                                    <div class="flex flex-col gap-2">
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="color" name="colors[dark][bg]" x-model="darkBg" class="w-9 h-9 rounded cursor-pointer border border-base-300"> {{ __('Background') }}
                                        </label>
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="color" name="colors[dark][text]" x-model="darkText" class="w-9 h-9 rounded cursor-pointer border border-base-300"> {{ __('Text') }}
                                        </label>
                                    </div>
                                </template>
                                <p x-show="!darkOn" class="text-xs opacity-60">{{ __('Off — the same colours in both themes.') }}</p>
                            </div>
                        </div>

                        <div x-show="style !== 'image'" class="grid sm:grid-cols-2 gap-4" style="{{ $labelPaletteVars }}">
                            @foreach (['size_list' => ['sizeList', __('Size on product cards')], 'size_detail' => ['sizeDetail', __('Size on the product page')]] as $sizeField => [$sizeModel, $sizeText])
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ $sizeText }}</legend>
                                    {{-- The sizes drawn, not named: "md" tells nobody anything. --}}
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach (\Torgash\Catalog\Models\Label::SIZES as $sizeStep)
                                            <label class="cursor-pointer rounded-lg px-1.5 py-1 border-2 flex items-center"
                                                   :class="{{ $sizeModel }} === '{{ $sizeStep }}' ? 'border-primary' : 'border-transparent'">
                                                <input type="radio" name="{{ $sizeField }}" value="{{ $sizeStep }}" x-model="{{ $sizeModel }}" class="hidden">
                                                <span :class="badgeClass('{{ $sizeStep }}')" :style="badgeStyle(false)">Aa</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>

                        <div x-show="style !== 'text'" x-cloak class="flex flex-wrap items-end gap-4">
                            @if ($label->exists && $label->imageUrl())
                                <img src="{{ $label->imageUrl() }}" class="h-12 rounded border border-base-300 p-1" alt="">
                            @endif
                            <fieldset class="fieldset p-0 grow"><legend class="fieldset-legend">{{ __('Picture') }}</legend>
                                <input name="image" type="file" accept="image/*,.svg" class="file-input file-input-bordered w-full">
                                <p class="label text-xs opacity-60">{{ __('Up to 2 MB. A new file replaces the old one.') }}</p>
                            </fieldset>
                            <fieldset class="fieldset p-0 w-56" x-show="style === 'tile'" x-cloak><legend class="fieldset-legend">{{ __('Icon instead of a picture') }}</legend>
                                <input name="icon" value="{{ old('icon', $label->icon) }}" class="input input-bordered w-full font-mono" placeholder="cpu">
                                <p class="label text-xs opacity-60 whitespace-normal">{!! __('A name from :link, like smartphone or gamepad-2.', ['link' => '<a href="https://lucide.dev/icons" target="_blank" rel="noopener" class="link">lucide.dev</a>']) !!}</p>
                            </fieldset>
                            @if ($label->exists && $label->style === 'tile')
                                <div class="rounded-lg bg-base-200 p-3">
                                    @include('torgash.catalog::partials.label-badge', ['badgeLabel' => $label, 'badgePlace' => 'detail', 'badgeText' => $label->plainName(), 'badgeCorner' => 'top-left', 'badgeHint' => false])
                                </div>
                            @endif
                        </div>

                        <div x-show="style === 'image'" x-cloak class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Height on product cards') }}</legend>
                                <label class="input input-bordered w-full">
                                    <input type="number" name="image_height_list" min="12" max="160" class="grow"
                                           value="{{ old('image_height_list', $label->image_height_list ?? 28) }}">
                                    <span class="opacity-50 text-sm">px</span>
                                </label>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Height on the product page') }}</legend>
                                <label class="input input-bordered w-full">
                                    <input type="number" name="image_height_detail" min="12" max="160" class="grow"
                                           value="{{ old('image_height_detail', $label->image_height_detail ?? 28) }}">
                                    <span class="opacity-50 text-sm">px</span>
                                </label>
                            </fieldset>
                            <fieldset class="fieldset p-0 sm:col-span-2"><legend class="fieldset-legend">{{ __('Shape') }}</legend>
                                <div class="flex flex-wrap gap-2">
                                    @foreach (['as_is' => __('As drawn'), 'square' => __('Square'), 'rounded' => __('Rounded square'), 'circle' => __('Circle')] as $shapeKey => $shapeText)
                                        <label class="cursor-pointer rounded-lg border-2 px-3 py-2 flex items-center gap-2 text-sm"
                                               :class="shape === '{{ $shapeKey }}' ? 'border-primary' : 'border-base-300'">
                                            <input type="radio" name="image_shape" value="{{ $shapeKey }}" x-model="shape" class="hidden">
                                            <span @class([
                                                'inline-block bg-base-content/30',
                                                'w-6 h-4 rounded-sm' => $shapeKey === 'as_is',
                                                'w-5 h-5' => $shapeKey !== 'as_is',
                                                'rounded-md' => $shapeKey === 'rounded',
                                                'rounded-full' => $shapeKey === 'circle',
                                            ])></span>
                                            {{ $shapeText }}
                                        </label>
                                    @endforeach
                                </div>
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('Any shape but "as drawn" crops the picture to a square of that height — a flag drawn from a rectangle is still a circle.') }}</p>
                            </fieldset>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <h3 class="font-semibold">
                                {{ __('Put on automatically') }}
                                <span class="badge badge-ghost badge-sm ml-1" x-show="rules.length" x-text="rules.length"></span>
                            </h3>
                            <button type="button" @click="addRule()" class="btn btn-sm gap-1.5">
                                <x-icon name="plus" size="sm" /> {{ __('Add condition') }}
                            </button>
                        </div>
                        <p class="text-xs opacity-60 -mt-2">
                            {{ __('Products matching these conditions carry the label on their own, on top of those it is put on by hand in the product form. No conditions — by hand only.') }}
                        </p>

                        {{-- One condition needs no "every/any"; the select is only hidden,
                             so the choice is still posted and survives a second one. --}}
                        <div class="flex items-center gap-2 text-sm" x-show="rules.length > 1">
                            <span>{{ __('When') }}</span>
                            <select name="match" x-model="match" class="select select-bordered w-auto">
                                <option value="all">{{ __('every condition holds') }}</option>
                                <option value="any">{{ __('any condition holds') }}</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-2">
                            <template x-for="(rule, i) in rules" :key="i">
                                <div class="flex flex-wrap gap-2 items-center bg-base-200 rounded-lg p-2.5">
                                    <select :name="'rules['+i+'][field]'" x-model="rule.field" class="select select-bordered">
                                        @foreach ($ruleFields as $fieldKey => $fieldText)
                                            <option value="{{ $fieldKey }}">{{ $fieldText }}</option>
                                        @endforeach
                                    </select>

                                    <template x-if="['price', 'stock', 'age_days', 'discount', 'views', 'sales'].includes(rule.field)">
                                        <div class="flex gap-2 items-center">
                                            <select :name="'rules['+i+'][operator]'" x-model="rule.operator" class="select select-bordered">
                                                <option value="eq">=</option>
                                                <option value="neq">≠</option>
                                                <option value="lt">&lt;</option>
                                                <option value="lte">&le;</option>
                                                <option value="gt">&gt;</option>
                                                <option value="gte">&ge;</option>
                                            </select>
                                            <input type="number" step="any" :name="'rules['+i+'][value]'" x-model="rule.value"
                                                   class="input input-bordered w-28"
                                                   :placeholder="{ price: @js(__('amount')), age_days: @js(__('days')), discount: @js(__('percent')), views: @js(__('count')), sales: @js(__('count')) }[rule.field] || @js(__('pcs'))">
                                        </div>
                                    </template>
                                    <template x-if="rule.field === 'discount'">
                                        <div class="text-xs opacity-60 basis-full">
                                            {{ __('Any cut in price — a customer group\'s discount, a wholesale price type or the RRP. Cannot be combined with a category, characteristic or age condition on one label.') }}
                                        </div>
                                    </template>
                                    <template x-if="rule.field === 'rrp'">
                                        <div class="text-xs opacity-60">
                                            {{ __('Only when the product has an RRP above its own price; group and wholesale discounts are ignored.') }}
                                        </div>
                                    </template>

                                    <template x-if="rule.field === 'category'">
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <select :name="'rules['+i+'][category_ids][]'" x-model="rule.category_ids" multiple
                                                    class="select select-bordered min-w-48" size="1">
                                                @foreach ($categories as $catId => $catLabel)
                                                    <option value="{{ $catId }}">{{ $catLabel }}</option>
                                                @endforeach
                                            </select>
                                            {{-- No "incl. subcategories" box: a category IS its
                                                 subcategories everywhere in this engine. --}}
                                            <span class="text-xs opacity-60">{{ __('subcategories included') }}</span>
                                        </div>
                                    </template>

                                    <template x-if="rule.field === 'feature'">
                                        <div class="flex gap-2 items-center">
                                            <select :name="'rules['+i+'][feature_id]'" x-model="rule.feature_id" class="select select-bordered">
                                                <option value="">— {{ __('characteristic') }} —</option>
                                                @foreach ($features as $f)
                                                    <option value="{{ $f->id }}">{{ $f->t('name', $locale) }}</option>
                                                @endforeach
                                            </select>
                                            <select :name="'rules['+i+'][value]'" x-model="rule.value" class="select select-bordered">
                                                <option value="">— {{ __('value') }} —</option>
                                                <template x-for="fv in (featureValues[rule.feature_id] || [])" :key="fv.id">
                                                    <option :value="fv.id" x-text="fv.label"></option>
                                                </template>
                                            </select>
                                        </div>
                                    </template>

                                    <button type="button" @click="removeRule(i)" class="btn btn-ghost btn-sm btn-square text-error ml-auto"
                                            title="{{ __('Remove condition') }}" aria-label="{{ __('Remove condition') }}">
                                        <x-icon name="x" />
                                    </button>
                                </div>
                            </template>
                            <div x-show="rules.length === 0" class="text-sm opacity-50 py-2">{{ __('No conditions — the label is put on by hand only.') }}</div>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Right: whether it is on, when, and where on the picture. --}}
            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-primary" @checked(old('enabled', $label->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <div class="flex items-center gap-3 rounded-lg bg-base-200 p-3" x-show="style === 'text'" style="{{ $labelPaletteVars }}">
                            <span class="text-xs opacity-60 w-20">{{ __('On a card:') }}</span>
                            <span :class="badgeClass(sizeList)" :style="badgeStyle(false)" x-text="previewName || @js(__('Badge'))"></span>
                        </div>
                        <div class="flex items-center gap-3 rounded-lg bg-base-200 p-3" x-show="style === 'text'" style="{{ $labelPaletteVars }}">
                            <span class="text-xs opacity-60 w-20">{{ __('On the page:') }}</span>
                            <span :class="badgeClass(sizeDetail)" :style="badgeStyle(false)" x-text="previewName || @js(__('Badge'))"></span>
                        </div>
                        {{-- The dark pair is only worth a look when there is one. --}}
                        <div class="flex items-center gap-3 rounded-lg p-3 bg-neutral text-neutral-content" x-show="style === 'text' && color === 'custom' && darkOn" x-cloak>
                            <span class="text-xs opacity-70 w-20">{{ __('Dark theme:') }}</span>
                            <span :class="badgeClass(sizeList)" :style="badgeStyle(true)" x-text="previewName || @js(__('Badge'))"></span>
                        </div>
                        <div class="flex items-center gap-3 rounded-lg bg-base-200 p-3" x-show="style === 'image'" x-cloak>
                            <span class="text-xs opacity-60">{{ __('On a card:') }}</span>
                            @if ($label->exists && $label->imageUrl())
                                @php $previewBox = $label->imageBox('list'); @endphp
                                <img src="{{ $label->imageUrl() }}" style="{{ $previewBox['style'] }}" class="{{ $previewBox['class'] }}" alt="">
                            @else
                                <span class="text-xs opacity-60">{{ __('no picture yet') }}</span>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('When it shows') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Starts') }}</legend>
                            <input name="starts_at" type="datetime-local"
                                   value="{{ old('starts_at', $label->starts_at?->format('Y-m-d\TH:i')) }}" class="input input-bordered w-full">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Ends') }}</legend>
                            <input name="ends_at" type="datetime-local"
                                   value="{{ old('ends_at', $label->ends_at?->format('Y-m-d\TH:i')) }}" class="input input-bordered w-full">
                        </fieldset>
                        <p class="text-xs opacity-60 -mt-2">{{ __('Empty — always.') }}</p>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Where it stands') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('On product cards') }}</legend>
                            <select name="position_list" class="select select-bordered w-full">
                                @foreach ($listPositions as $pos => $posText)
                                    <option value="{{ $pos }}" @selected(old('position_list', $label->position_list ?? 'top-left') === $pos)>{{ $posText }}</option>
                                @endforeach
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('On the product page') }}</legend>
                            <select name="position_detail" class="select select-bordered w-full">
                                @foreach ($detailPositions as $pos => $posText)
                                    <option value="{{ $pos }}" @selected(old('position_detail', $label->position_detail ?? 'top-left') === $pos)>{{ $posText }}</option>
                                @endforeach
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input name="sort" type="number" value="{{ old('sort', $label->sort ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Several labels in one corner stand in this order.') }}</p>
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($label->exists)
        <form id="label-delete" method="POST" action="{{ route('admin.catalog.labels.destroy', $label) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
