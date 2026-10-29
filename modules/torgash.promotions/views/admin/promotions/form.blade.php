@extends('admin.layout')

@section('title', $promotion->exists ? ($promotion->t('name', admin_content_locale()) ?: __('Promotion')) : __('New promotion'))
@section('own_heading', '1')

@section('content')
    @php
        use Torgash\Promotions\Conditions;

        $catalogLabels = array_map(fn ($l) => __($l), Conditions::CATALOG_LABELS);
        $cartLabels = array_map(fn ($l) => __($l), Conditions::CART_LABELS);

        $operatorsFor = function (array $kinds) {
            $out = [];
            foreach ($kinds as $kind => $ops) {
                $out[$kind] = array_map(fn ($l) => __($l), $ops);
            }
            return $out;
        };
        $catalogOperators = $operatorsFor(Conditions::CATALOG_KINDS);
        $cartOperators = $operatorsFor(Conditions::CART_KINDS);

        $tierRows = old('tiers', $promotion->exists
            ? $promotion->tiers->map(fn ($t) => [
                'match' => $t->matchAny() ? 'any' : 'all',
                'discount_type' => $t->discount_type,
                'discount_value' => in_array($t->discount_type, \Torgash\Promotions\Models\PromotionTier::MONEY_VALUE_TYPES, true)
                    ? ($t->discount_value ?? 0) / 100
                    : ($t->discount_value ?? 0),
                'discount_scope_type' => $t->scope()['type'] ?? '',
                'discount_scope_ids' => array_map('strval', $t->scope()['ids'] ?? []),
                'give_group_id' => (string) ($t->give_group_id ?? ''),
                'free_product_id' => (string) ($t->free_product_id ?? ''),
                'free_qty' => $t->free_qty ?? 1,
                'rules' => array_map(fn ($r) => [
                    'kind' => $r['kind'] ?? '',
                    'operator' => $r['operator'] ?? '',
                    'ids' => array_map('strval', $r['ids'] ?? []),
                    'feature_id' => (string) ($r['feature_id'] ?? ''),
                    'value' => isset($r['value'])
                        ? (in_array($r['kind'] ?? '', ['price'], true) ? $r['value'] / 100 : $r['value'])
                        : '',
                ], $t->rules()),
            ])->all()
            : []);

        // Days as strings: Alpine binds a checkbox group to string values, and
        // a numeric 1 would never match the "1" the checkbox puts in the array.
        $windowRows = array_map(fn ($w) => [
            'days' => array_map('strval', $w['days'] ?? []),
            'from' => $w['from'] ?? '',
            'to' => $w['to'] ?? '',
        ], old('schedule.windows', $promotion->scheduleWindows()->toArray()) ?: []);

        $generalFields = ['name', 'zone', 'combine', 'priority', 'enabled', 'starts_at', 'ends_at', 'usage_limit', 'once_per_customer'];
        $storefrontFields = ['slug', 'description', 'page_title', 'meta_description', 'banner', 'badge', 'badge_text', 'badge_icon', 'badge_image', 'tint'];
        $initialTab = collect($generalFields)->contains(fn ($f) => $errors->has($f))
            ? 'general'
            : (collect($storefrontFields)->contains(fn ($f) => $errors->has($f))
                ? 'storefront'
                : (collect($errors->keys())->contains(fn ($k) => $k === 'tiers' || str_starts_with($k, 'tiers.')) ? 'tiers' : 'general'));
    @endphp

    <form method="POST" id="promotion-form" enctype="multipart/form-data"
          action="{{ $promotion->exists ? route('admin.promotions.update', $promotion) : route('admin.promotions.store') }}"
          class="flex flex-col gap-4"
          x-data="{
              tab: '{{ $initialTab }}',
              zone: '{{ old('zone', $promotion->zone) }}',
              previewName: {{ \Illuminate\Support\Js::from(old('name', $promotion->t('name', admin_content_locale()) ?: '')) }},
              enabled: {{ old('enabled', $promotion->enabled ?? true) ? 'true' : 'false' }},
              published: {{ old('published', $promotion->published ?? false) ? 'true' : 'false' }},
              startsAt: {{ \Illuminate\Support\Js::from(old('starts_at', optional($promotion->starts_at)->format('Y-m-d\TH:i') ?: '')) }},
              endsAt: {{ \Illuminate\Support\Js::from(old('ends_at', optional($promotion->ends_at)->format('Y-m-d\TH:i') ?: '')) }},
              windows: {{ \Illuminate\Support\Js::from($windowRows) }},
              addWindow() { this.windows.push({ days: [], from: '09:00', to: '18:00' }); },
              removeWindow(i) { this.windows.splice(i, 1); },
              // The same reading as Torgash\Promotions\Schedule, so the badge
              // in the header agrees with what the storefront will do: an
              // empty day list is every day, `to` at or before `from` crosses
              // midnight, and equal times mean the whole day.
              inWindow() {
                  if (! this.windows.length) return true;
                  const now = new Date();
                  const minutes = now.getHours() * 60 + now.getMinutes();
                  const isoDay = now.getDay() === 0 ? 7 : now.getDay();
                  const yesterday = isoDay === 1 ? 7 : isoDay - 1;
                  return this.windows.some(w => {
                      const parse = t => { const m = /^(\d{1,2}):(\d{2})/.exec(t || ''); return m ? +m[1] * 60 + +m[2] : null; };
                      const from = parse(w.from), to = parse(w.to);
                      if (from === null || to === null) return false;
                      const length = to > from ? to - from : to - from + 1440;
                      const onDay = d => ! w.days.length || w.days.map(Number).includes(d);
                      // Started today, or yesterday and still running past midnight.
                      return (onDay(isoDay) && minutes >= from && minutes < from + length)
                          || (onDay(yesterday) && minutes + 1440 >= from && minutes + 1440 < from + length);
                  });
              },
              statusMeta: {
                  running: { badge: 'badge-success', icon: 'circle-check', label: {{ \Illuminate\Support\Js::from(__('running')) }} },
                  paused: { badge: 'badge-warning', icon: 'pause', label: {{ \Illuminate\Support\Js::from(__('paused')) }} },
                  scheduled: { badge: 'badge-warning', icon: 'clock', label: {{ \Illuminate\Support\Js::from(__('scheduled')) }} },
                  ended: { badge: 'badge-ghost', icon: 'calendar-x', label: {{ \Illuminate\Support\Js::from(__('ended')) }} },
                  off: { badge: 'badge-ghost', icon: 'power-off', label: {{ \Illuminate\Support\Js::from(__('off')) }} },
              },
              status() {
                  if (! this.enabled) return 'off';
                  const now = new Date();
                  if (this.startsAt && now < new Date(this.startsAt)) return 'scheduled';
                  if (this.endsAt && now > new Date(this.endsAt)) return 'ended';
                  if (! this.inWindow()) return 'paused';
                  return 'running';
              },
              tiers: {{ \Illuminate\Support\Js::from($tierRows) }},
              catalogLabels: {{ \Illuminate\Support\Js::from($catalogLabels) }},
              cartLabels: {{ \Illuminate\Support\Js::from($cartLabels) }},
              catalogOperators: {{ \Illuminate\Support\Js::from($catalogOperators) }},
              cartOperators: {{ \Illuminate\Support\Js::from($cartOperators) }},
              features: {{ \Illuminate\Support\Js::from($featureOptions) }},
              labels() { return this.zone === 'catalog' ? this.catalogLabels : this.cartLabels; },
              operators() { return this.zone === 'catalog' ? this.catalogOperators : this.cartOperators; },
              firstKind() { return Object.keys(this.labels())[0]; },
              // The four that adjust a price — mirrors PromotionTier::ADJUSTING_TYPES.
              adjusting: ['percent', 'fixed', 'to_percent', 'to_amount'],
              valueLabel(type) {
                  if (type === 'percent') return {{ \Illuminate\Support\Js::from(__('Percent')) }};
                  if (type === 'to_percent') return {{ \Illuminate\Support\Js::from(__('% of the price')) }};
                  if (type === 'to_amount') return {{ \Illuminate\Support\Js::from(__('New price')) }};
                  return {{ \Illuminate\Support\Js::from(__('Amount')) }};
              },
              addTier() { this.tiers.push({ match: 'all', discount_type: 'percent', discount_value: '', discount_scope_type: '', discount_scope_ids: [], give_group_id: '', free_product_id: '', free_qty: 1, rules: [] }); },
              // The submitted array's own order is the saved order — saveTiers()
              // writes `position` from the index — so swapping the rows here is
              // the whole of it, with no id to renumber.
              moveTier(index, by) {
                  const to = index + by;
                  if (to < 0 || to >= this.tiers.length) return;
                  [this.tiers[index], this.tiers[to]] = [this.tiers[to], this.tiers[index]];
              },
              addRule(tier) { const k = this.firstKind(); tier.rules.push({ kind: k, operator: Object.keys(this.operators()[k])[0], ids: [], feature_id: '', value: '' }); },
              onKindChange(row) { row.operator = Object.keys(this.operators()[row.kind])[0]; },
              valuesFor(featureId) { const f = this.features.find(f => String(f.id) === String(featureId)); return f ? f.values : []; },
          }">
        @csrf
        @if ($promotion->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.promotions.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to promotions') }}" aria-label="{{ __('Back to promotions') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $promotion->exists ? __('Edit promotion') : __('New promotion') }}</div>
                <div class="font-semibold truncate leading-tight" x-text="previewName || '{{ __('New promotion') }}'"></div>
            </div>
            <span class="badge gap-1" :class="statusMeta[status()].badge">
                <x-icon name="circle-check" size="sm" x-show="status() === 'running'" x-cloak />
                <x-icon name="clock" size="sm" x-show="status() === 'scheduled'" x-cloak />
                <x-icon name="pause" size="sm" x-show="status() === 'paused'" x-cloak />
                <x-icon name="calendar-x" size="sm" x-show="status() === 'ended'" x-cloak />
                <x-icon name="power-off" size="sm" x-show="status() === 'off'" x-cloak />
                <span x-text="statusMeta[status()].label"></span>
            </span>
            @if ($promotion->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-64 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        @if ($promotion->published && $promotion->slug)
                            <li><a href="{{ route('promotions.show', $promotion->slug) }}" target="_blank" rel="noopener"><x-icon name="external-link" /> {{ __('Open the page on the storefront') }}</a></li>
                        @endif
                        <li><button type="submit" form="promotion-duplicate"><x-icon name="copy" /> {{ __('Make a copy') }}</button></li>
                        @if ($promotion->zone === 'cart')
                            <li><a href="{{ route('admin.promotions.usage', ['promotion' => $promotion->id]) }}"><x-icon name="list-checks" /> {{ __('Usage log') }}</a></li>
                        @endif
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="promotion-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete promotion') }}</button></li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.promotions.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $promotion->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div role="tablist" class="tabs tabs-border">
            <a role="tab" class="tab" :class="tab === 'general' ? 'tab-active font-semibold' : ''"
               @click.prevent="tab = 'general'" href="#">{{ __('General') }}</a>
            <a role="tab" class="tab" :class="tab === 'tiers' ? 'tab-active font-semibold' : ''"
               @click.prevent="tab = 'tiers'" href="#">
                {{ __('Tiers') }}
                <span class="badge badge-ghost badge-sm ml-1" x-show="tiers.length" x-text="tiers.length"></span>
            </a>
            <a role="tab" class="tab" :class="tab === 'storefront' ? 'tab-active font-semibold' : ''"
               @click.prevent="tab = 'storefront'" href="#">
                {{ __('Storefront') }}
                <x-icon name="globe" size="sm" class="ms-1 opacity-60" x-show="published" x-cloak />
            </a>
        </div>

        {{-- The sale as customers meet it: its own page, its address, what
             search engines are told. Separate from the switch on the General
             tab on purpose - `published` says there is something to read,
             `enabled` says money moves, and a shop needs both halves
             independently (most sales have no page; a page must outlive its
             sale so last week's newsletter does not 404). --}}
        <div x-show="tab === 'storefront'" x-cloak class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div class="flex flex-wrap gap-4 items-center">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="published" value="1" class="toggle toggle-sm toggle-success" x-model="published">
                        <span class="text-sm">{{ __('Give this sale a page') }}</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="show_countdown" value="1" class="toggle toggle-sm toggle-success"
                               @checked(old('show_countdown', $promotion->show_countdown ?? true))>
                        <span class="text-sm">{{ __('Show a countdown') }}</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer" title="{{ __('Off: the sale still runs by its schedule, but the customer is not told it repeats ("every day") — only the countdown to the current close is seen.') }}">
                        <input type="checkbox" name="show_schedule" value="1" class="toggle toggle-sm toggle-success"
                               @checked(old('show_schedule', $promotion->show_schedule ?? true))>
                        <span class="text-sm">{{ __('Show the schedule') }}</span>
                    </label>
                    <span class="text-xs opacity-50">{{ __('The countdown runs to whichever comes first: the end of the sale or the close of the current window. With neither, nothing is shown.') }}</span>
                </div>

                <div class="flex flex-wrap gap-3">
                    <fieldset class="fieldset p-0 grow min-w-64">
                        <legend class="fieldset-legend">{{ __('Address') }}</legend>
                        <input name="slug" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('slug', $promotion->slug) }}"
                               class="input input-bordered w-full" placeholder="{{ __('made from the name') }}">
                        @if ($promotion->exists && $promotion->slug)
                            <div class="text-xs opacity-50 mt-1">
                                <a href="{{ route('promotions.show', $promotion->slug) }}" target="_blank" class="link link-hover">
                                    {{ route('promotions.show', $promotion->slug) }}
                                </a>
                            </div>
                        @endif
                    </fieldset>

                    <fieldset class="fieldset p-0 w-64">
                        <legend class="fieldset-legend">{{ __('Banner') }}</legend>
                        @if ($promotion->exists && $promotion->bannerUrl())
                            <img src="{{ $promotion->bannerUrl() }}" class="h-16 rounded-md mb-2 object-cover" alt="">
                            <label class="flex items-center gap-2 text-xs cursor-pointer mb-1">
                                <input type="checkbox" name="banner_remove" value="1" class="checkbox checkbox-sm">
                                {{ __('Remove') }}
                            </label>
                        @endif
                        <input name="banner" type="file" accept="image/*" class="file-input file-input-bordered w-full">
                    </fieldset>
                </div>

                @php
                    // What "worked out from the sale" puts on the tile today, for
                    // the preview: asked of the model with the choice set aside.
                    $badgeAutoText = '';
                    if ($promotion->exists) {
                        $badgeChosen = $promotion->badge;
                        $promotion->badge = 'auto';
                        $badgeAuto = $promotion->tile(app(\Torgash\Promotions\Showcase::class)->headline($promotion));
                        $promotion->badge = $badgeChosen;
                        $badgeAutoText = ($badgeAuto[0] ?? null) === 'text' ? $badgeAuto[1] : '';
                    }
                @endphp
                {{-- How the sale looks where it is announced: the panel under a
                     product's price and the "Current promotions" cards —
                     Promotion::tile() and ::hue(). --}}
                @php
                    // The shop's own colours as tints (PLAN §52): their hue, as set now.
                    $brandHues = collect(\Torgash\Promotions\Models\Promotion::BRAND_TINTS)
                        ->mapWithKeys(fn ($role) => [$role => \Torgash\Promotions\Models\Promotion::brandHue($role)])
                        ->filter(fn ($hue) => $hue !== null)->all();
                @endphp
                <div class="rounded-box border border-base-300 p-4 flex flex-col gap-3"
                     x-data="{
                         badge: @js(old('badge', $promotion->badge ?? 'auto')),
                         tint: @js(old('tint', $promotion->tint ?? '')),
                         words: @js(old('badge_text', $promotion->t('badge_text', admin_content_locale()) ?: '')),
                         hues: @js(\Torgash\Promotions\Models\Promotion::TINTS + $brandHues),
                         autoHue: @js($promotion->exists ? \Torgash\Promotions\Models\Promotion::AUTO_HUES[$promotion->id % 8] : 25),
                         autoText: @js($badgeAutoText),
                         get hue() { return this.hues[this.tint] ?? this.autoHue; },
                     }">
                    <div class="flex items-center gap-3">
                        <span class="torgash-promo-note-tile" :style="`--promo-hue: ${hue}`" aria-hidden="true">
                            <b x-show="(badge === 'text' && words) || (badge === 'auto' && autoText)" x-text="badge === 'auto' ? autoText : words" :class="(badge === 'auto' ? autoText : words).length > 4 && 'is-long'"></b>
                            <x-icon name="badge-percent" size="lg" x-show="badge !== 'none' && !((badge === 'text' && words) || (badge === 'auto' && autoText))" />
                        </span>
                        <div>
                            <h3 class="font-semibold">{{ __('On the product page and the sale cards') }}</h3>
                            <p class="text-xs opacity-60">{{ __("The panel under the price of every product in the sale, and the sale's card in the \"Current promotions\" block.") }}</p>
                        </div>
                    </div>

                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Colour') }}</legend>
                        <div class="flex flex-wrap gap-2">
                            <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                                <input type="radio" name="tint" value="" x-model="tint" class="radio radio-sm">
                                {{ __('Automatic') }}
                            </label>
                            @foreach ($brandHues as $tintKey => $tintHue)
                                <label class="cursor-pointer" title="{{ match ($tintKey) { 'primary' => __("The shop's base colour"), 'secondary' => __("The shop's second colour"), default => __("The shop's accent colour") } }}">
                                    <input type="radio" name="tint" value="{{ $tintKey }}" x-model="tint" class="peer sr-only">
                                    <span class="w-7 h-7 rounded-full border-2 border-transparent peer-checked:border-base-content peer-focus-visible:outline grid place-items-center text-white"
                                          style="background: oklch(62% 0.16 {{ $tintHue }})"><x-icon name="store" size="sm" /></span>
                                </label>
                            @endforeach
                            @if ($brandHues !== [])
                                <span class="w-px self-stretch bg-base-300 mx-1"></span>
                            @endif
                            @foreach (\Torgash\Promotions\Models\Promotion::TINTS as $tintKey => $tintHue)
                                <label class="cursor-pointer" title="{{ __(ucfirst($tintKey)) }}">
                                    <input type="radio" name="tint" value="{{ $tintKey }}" x-model="tint" class="peer sr-only">
                                    <span class="block w-7 h-7 rounded-full border-2 border-transparent peer-checked:border-base-content peer-focus-visible:outline"
                                          style="background: oklch(62% 0.16 {{ $tintHue }})"></span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="flex flex-wrap gap-3 items-start">
                        <fieldset class="fieldset p-0 w-56">
                            <legend class="fieldset-legend">{{ __('On the tile') }}</legend>
                            <select name="badge" x-model="badge" class="select select-bordered w-full">
                                @foreach (['auto' => __('Worked out from the sale'), 'picture' => __('Own picture'), 'text' => __('Own words'), 'icon' => __('Icon'), 'none' => __('No tile')] as $badgeKey => $badgeText)
                                    <option value="{{ $badgeKey }}">{{ $badgeText }}</option>
                                @endforeach
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal" x-show="badge === 'auto'">{{ __('The banner if there is one, else the discount (−20%), else an icon.') }}</p>
                        </fieldset>

                        <fieldset class="fieldset p-0 w-64" x-show="badge === 'picture'" x-cloak>
                            <legend class="fieldset-legend">{{ __('Picture') }}</legend>
                            @if ($promotion->exists && $promotion->badgeUrl())
                                <img src="{{ $promotion->badgeUrl() }}" class="h-12 w-12 rounded-md mb-2 object-cover" alt="">
                                <label class="flex items-center gap-2 text-xs cursor-pointer mb-1">
                                    <input type="checkbox" name="badge_image_remove" value="1" class="checkbox checkbox-sm">
                                    {{ __('Remove') }}
                                </label>
                            @endif
                            <input name="badge_image" type="file" accept="image/*" class="file-input file-input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Square, from 104 pixels; it is shown 52 pixels wide.') }}</p>
                        </fieldset>

                        <div class="w-56" x-show="badge === 'text'" x-cloak x-on:input="words = $event.target.value">
                            <x-admin.translatable-field name="badge_text" :label="__('Words')"
                                :value="$promotion->badge_text ?? []" :placeholder="__('−20%, 1+1, Gift')" />
                        </div>

                        <fieldset class="fieldset p-0 w-56" x-show="badge === 'icon'" x-cloak>
                            <legend class="fieldset-legend">{{ __('Icon') }}</legend>
                            <input name="badge_icon" value="{{ old('badge_icon', $promotion->badge_icon) }}" class="input input-bordered w-full font-mono" placeholder="gift">
                            <p class="label text-xs opacity-60 whitespace-normal">{!! __('A name from :link, like smartphone or gamepad-2.', ['link' => '<a href="https://lucide.dev/icons" target="_blank" rel="noopener" class="link">lucide.dev</a>']) !!}</p>
                        </fieldset>
                    </div>
                </div>

                <x-admin.translatable-field name="description" :label="__('Description')"
                    :value="$promotion->description ?? []" rich :rows="8" />

                <h3 class="font-semibold mt-2">{{ __('SEO') }}</h3>
                <div class="flex flex-wrap gap-3">
                    <div class="grow min-w-64">
                        <x-admin.translatable-field name="page_title" :label="__('Page title')"
                            :value="$promotion->page_title ?? []" :placeholder="__('the name of the sale')" />
                    </div>
                    <div class="grow min-w-64">
                        <x-admin.translatable-field name="meta_description" :label="__('Meta description')"
                            :value="$promotion->meta_description ?? []" textarea :rows="2" />
                    </div>
                </div>
            </div>
        </div>

        <div x-show="tab === 'general'" class="flex flex-col gap-4">
        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Basics') }}</h3>

                <div class="flex flex-wrap gap-3">
                    <fieldset class="fieldset p-0 grow min-w-64">
                        <legend class="fieldset-legend">{{ __('Name') }}</legend>
                        <input name="name" x-model="previewName"
                               class="input input-bordered w-full" placeholder="{{ __('Black Friday') }}">
                    </fieldset>

                    <fieldset class="fieldset p-0 w-80">
                        <legend class="fieldset-legend">{{ __('Zone') }}</legend>
                        <select name="zone" x-model="zone" class="select select-bordered w-full" {{ $promotion->exists ? 'disabled' : '' }}>
                            <option value="catalog">{{ __('Catalog — changes the product price') }}</option>
                            <option value="cart">{{ __('Cart — a discount on the order') }}</option>
                        </select>
                        @if ($promotion->exists)
                            {{-- Disabled selects submit nothing; the value still has to travel. --}}
                            <input type="hidden" name="zone" value="{{ $promotion->zone }}">
                        @endif
                    </fieldset>

                    <fieldset class="fieldset p-0 w-48">
                        <legend class="fieldset-legend">{{ __('Combine with others') }}</legend>
                        <select name="combine" class="select select-bordered w-full">
                            <option value="replace" @selected(old('combine', $promotion->combine) === 'replace')>{{ __('Replace') }}</option>
                            <option value="stack_add" @selected(old('combine', $promotion->combine) === 'stack_add')>{{ __('Stack (add)') }}</option>
                            <option value="stack_multiply" @selected(old('combine', $promotion->combine) === 'stack_multiply')>{{ __('Stack (multiply)') }}</option>
                        </select>
                    </fieldset>

                    <fieldset class="fieldset p-0 w-24">
                        <legend class="fieldset-legend">{{ __('Priority') }}</legend>
                        <input type="number" name="priority" value="{{ old('priority', $promotion->priority ?? 0) }}"
                               class="input input-bordered w-full">
                    </fieldset>

                    <label class="flex items-center gap-2 self-end pb-2 cursor-pointer">
                        <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" x-model="enabled">
                        <span class="text-sm">{{ __('Enabled') }}</span>
                    </label>
                </div>

                <p class="text-xs opacity-60">
                    {{ __('Replace competes with other Replace promotions for the same product/cart — the highest-priority match wins and the rest are skipped. Stack (add) sums its discount with whatever else already applied. Stack (multiply) applies to the ALREADY-discounted price — a real double discount.') }}
                </p>

            </div>
        </section>

        {{-- WHERE it applies — storefront, language, who is looking. Not what
             it applies TO: that is the tiers' business, and saying so here is
             what stops the two from being confused. --}}
        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div>
                    <x-admin.display-rules
                        :rules="$promotion->rules ?? []"
                        :match="$promotion->rules_match ?? 'all'"
                        :groups="['where', 'when', 'who']"
                        :heading="__('Applies when')"
                        :hint="__('No conditions means every storefront and every visitor. These say WHERE the sale runs; what it applies to is the tiers.')" />
                </div>

            </div>
        </section>

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Schedule') }}</h3>
                <div class="flex flex-wrap gap-3">
                    <fieldset class="fieldset p-0 w-56">
                        <legend class="fieldset-legend">{{ __('Starts') }}</legend>
                        <input type="datetime-local" name="starts_at" x-model="startsAt"
                               class="input input-bordered w-full">
                    </fieldset>
                    <fieldset class="fieldset p-0 w-56">
                        <legend class="fieldset-legend">{{ __('Ends') }}</legend>
                        <input type="datetime-local" name="ends_at" x-model="endsAt"
                               class="input input-bordered w-full">
                    </fieldset>
                    <p class="text-xs opacity-50 self-end pb-2">{{ __('Empty means no limit on that side.') }}</p>
                </div>

                {{-- The repeating window INSIDE those dates — a deal of the day,
                     a happy hour, a weekend sale. Dates say when the sale
                     exists; this says when it is open. --}}
                <div class="rounded-box border border-base-300 p-3 flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <x-icon name="timer" size="sm" class="opacity-60" />
                        <span class="text-sm font-medium">{{ __('Repeats') }}</span>
                        <span class="text-xs opacity-50">{{ __('No windows: the sale is open the whole time between the dates above.') }}</span>
                        <button type="button" class="btn btn-ghost btn-sm ms-auto" @click="addWindow()">
                            <x-icon name="plus" size="sm" /> {{ __('Add window') }}
                        </button>
                    </div>

                    <template x-for="(w, wi) in windows" :key="wi">
                        <div class="flex flex-wrap items-end gap-3 border-t border-base-300 pt-3">
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Days') }}</legend>
                                {{-- Buttons that edit the array, plus a hidden input per
                                     CHOSEN day — not checkboxes styled out of sight. A
                                     display:none checkbox behind a label depends on the
                                     browser forwarding the label's click to a control it
                                     is not painting, and on a peer-checked rule beating a
                                     daisyUI component class; both are true today in this
                                     browser and neither is something to bet a form on. --}}
                                <div class="flex flex-wrap gap-1">
                                    @foreach ([1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')] as $dayNumber => $dayName)
                                        <button type="button" class="badge badge-sm"
                                                :class="w.days.includes('{{ $dayNumber }}') ? 'badge-primary' : 'badge-ghost'"
                                                @click="w.days.includes('{{ $dayNumber }}')
                                                    ? w.days = w.days.filter(d => d !== '{{ $dayNumber }}')
                                                    : w.days.push('{{ $dayNumber }}')">{{ $dayName }}</button>
                                    @endforeach
                                </div>
                                <template x-for="day in w.days" :key="day">
                                    <input type="hidden" form="promotion-form"
                                           :name="`schedule[windows][${wi}][days][]`" :value="day">
                                </template>
                            </fieldset>

                            <fieldset class="fieldset p-0 w-32">
                                <legend class="fieldset-legend">{{ __('Open from') }}</legend>
                                <input type="time" class="input input-bordered w-full" form="promotion-form"
                                       :name="`schedule[windows][${wi}][from]`" x-model="w.from">
                            </fieldset>
                            <fieldset class="fieldset p-0 w-32">
                                <legend class="fieldset-legend">{{ __('Open until') }}</legend>
                                <input type="time" class="input input-bordered w-full" form="promotion-form"
                                       :name="`schedule[windows][${wi}][to]`" x-model="w.to">
                            </fieldset>

                            <button type="button" class="btn btn-ghost btn-sm btn-square text-error mb-0.5"
                                    @click="removeWindow(wi)" :title="'{{ __('Remove') }}'" :aria-label="'{{ __('Remove') }}'">
                                <x-icon name="trash-2" size="sm" />
                            </button>
                        </div>
                    </template>

                    <p class="text-xs opacity-50" x-show="windows.length" x-cloak>
                        {{ __('No days ticked means every day. An end time at or before the start time crosses midnight (22:00 → 02:00); the same time twice means the whole day.') }}
                    </p>
                </div>

            </div>
        </section>

        <section class="card bg-base-100 border border-base-300" x-show="zone === 'cart'" x-cloak>
            <div class="card-body gap-4">
                <div>
                    <h3 class="font-semibold mb-4">{{ __('Limits (cart only)') }}</h3>
                    <div class="flex flex-wrap gap-3 items-end">
                        <fieldset class="fieldset p-0 w-40">
                            <legend class="fieldset-legend">{{ __('Usage limit') }}</legend>
                            <input type="number" min="1" name="usage_limit" value="{{ old('usage_limit', $promotion->usage_limit) }}"
                                   placeholder="{{ __('Unlimited') }}" class="input input-bordered w-full">
                            @if ($promotion->exists)
                                <div class="text-xs opacity-50 mt-1">{{ __('Used :count time(s) so far.', ['count' => $promotion->used_count]) }}</div>
                            @endif
                        </fieldset>
                        <label class="flex items-center gap-2 pb-2 cursor-pointer">
                            <input type="checkbox" name="once_per_customer" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('once_per_customer', $promotion->once_per_customer ?? false))>
                            <span class="text-sm">{{ __('Once per customer') }}</span>
                        </label>
                        <p class="text-xs opacity-50 pb-2">{{ __('A guest checking out is only matched to their past orders once this one is placed, so "once per customer" is exact for signed-in accounts and best-effort for guests.') }}</p>
                    </div>
                </div>
            </div>
        </section>
        </div>

        {{-- Tiers: ordered, first match wins — one sale event, several
             discount depths, without needing a promotion per depth. --}}
        <div x-show="tab === 'tiers'" class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <p class="text-sm opacity-60">
                    {{ __('Tried top to bottom — the FIRST matching tier wins. Put the narrow discount first and a sitewide fallback last (no conditions = matches everything).') }}
                </p>

                <template x-for="(tier, ti) in tiers" :key="ti">
                    <div class="flex gap-3">
                        {{-- The rail: tiers are tried top to bottom and the FIRST
                             match wins, so the order between cards is part of what
                             the admin is editing, not just a list index. --}}
                        <div class="flex flex-col items-center shrink-0 w-8">
                            <div class="w-7 h-7 rounded-full bg-primary/10 text-primary text-xs font-semibold flex items-center justify-center" x-text="ti + 1"></div>
                            {{-- Reordering IS editing here: the order decides which
                                 tier wins, so moving one is a change of meaning, not
                                 of presentation. Buttons rather than drag — the list
                                 is short, and a drag handle inside a form that is
                                 already a repeater of repeaters is a way to lose a
                                 half-filled tier. --}}
                            <div class="flex flex-col mt-1">
                                <button type="button" class="btn btn-ghost btn-xs px-1 min-h-0 h-5"
                                        :disabled="ti === 0" @click="moveTier(ti, -1)"
                                        :title="'{{ __('Move up') }}'" :aria-label="'{{ __('Move up') }}'">
                                    <x-icon name="chevron-up" size="sm" />
                                </button>
                                <button type="button" class="btn btn-ghost btn-xs px-1 min-h-0 h-5"
                                        :disabled="ti === tiers.length - 1" @click="moveTier(ti, 1)"
                                        :title="'{{ __('Move down') }}'" :aria-label="'{{ __('Move down') }}'">
                                    <x-icon name="chevron-down" size="sm" />
                                </button>
                            </div>
                            <div class="w-px grow bg-base-300" x-show="ti < tiers.length - 1"></div>
                        </div>

                        <div class="flex-1 flex flex-col gap-2" :class="{ 'pb-1': ti < tiers.length - 1 }">
                        <div class="rounded-box border border-base-300 p-3 flex flex-col gap-3">
                        <div class="flex flex-wrap gap-2 items-end">
                            <div class="w-9 h-9 rounded-lg bg-base-200 flex items-center justify-center shrink-0 mb-0.5">
                                <x-icon name="percent" size="sm" x-show="tier.discount_type === 'percent'" x-cloak class="opacity-70" />
                                <x-icon name="banknote" size="sm" x-show="tier.discount_type === 'fixed'" x-cloak class="opacity-70" />
                                <x-icon name="circle-percent" size="sm" x-show="tier.discount_type === 'to_percent'" x-cloak class="opacity-70" />
                                <x-icon name="tag" size="sm" x-show="tier.discount_type === 'to_amount'" x-cloak class="opacity-70" />
                                <x-icon name="truck" size="sm" x-show="tier.discount_type === 'free_shipping'" x-cloak class="opacity-70" />
                                <x-icon name="user-check" size="sm" x-show="tier.discount_type === 'give_group'" x-cloak class="opacity-70" />
                                <x-icon name="gift" size="sm" x-show="tier.discount_type === 'free_product'" x-cloak class="opacity-70" />
                            </div>

                            <fieldset class="fieldset p-0 w-28">
                                <legend class="fieldset-legend">{{ __('Match') }}</legend>
                                <select class="select select-bordered w-full" form="promotion-form"
                                        :name="`tiers[${ti}][match]`" x-model="tier.match">
                                    <option value="all">{{ __('all') }}</option>
                                    <option value="any">{{ __('any') }}</option>
                                </select>
                            </fieldset>

                            <fieldset class="fieldset p-0 w-44">
                                <legend class="fieldset-legend">{{ __('Discount') }}</legend>
                                <select class="select select-bordered w-full" form="promotion-form"
                                        :name="`tiers[${ti}][discount_type]`" x-model="tier.discount_type">
                                    <optgroup label="{{ __('Take off') }}">
                                        <option value="percent">{{ __('Percent off') }}</option>
                                        <option value="fixed">{{ __('Fixed amount off') }}</option>
                                    </optgroup>
                                    {{-- The same arithmetic stated from the other
                                         end: not how much comes off, but what the
                                         price ends up as. --}}
                                    <optgroup label="{{ __('Set the price to') }}">
                                        <option value="to_percent">{{ __('A percent of the original price') }}</option>
                                        <option value="to_amount">{{ __('A fixed price') }}</option>
                                    </optgroup>
                                    <template x-if="zone === 'cart'">
                                        <optgroup label="{{ __('Cart only') }}">
                                            <option value="free_shipping">{{ __('Free shipping') }}</option>
                                            <option value="give_group">{{ __('Give customer group') }}</option>
                                            <option value="free_product">{{ __('Free gift product') }}</option>
                                        </optgroup>
                                    </template>
                                </select>
                            </fieldset>

                            <fieldset class="fieldset p-0 w-32" x-show="adjusting.includes(tier.discount_type)" x-cloak>
                                <legend class="fieldset-legend" x-text="valueLabel(tier.discount_type)"></legend>
                                <input type="number" step="0.01" min="0" class="input input-bordered w-full" form="promotion-form"
                                       :name="`tiers[${ti}][discount_value]`" x-model="tier.discount_value">
                            </fieldset>

                            <template x-if="adjusting.includes(tier.discount_type) && zone === 'cart'">
                                <fieldset class="fieldset p-0 w-52">
                                    <legend class="fieldset-legend">{{ __('Only off (optional)') }}</legend>
                                    <select class="select select-bordered w-full" form="promotion-form"
                                            :name="`tiers[${ti}][discount_scope_type]`" x-model="tier.discount_scope_type">
                                        <option value="">{{ __('The whole subtotal') }}</option>
                                        <option value="product">{{ __('Specific products') }}</option>
                                        <option value="category">{{ __('Specific categories') }}</option>
                                    </select>
                                </fieldset>
                            </template>

                            <template x-if="tier.discount_type === 'give_group'">
                                <fieldset class="fieldset p-0 w-52">
                                    <legend class="fieldset-legend">{{ __('Upgrade to group') }}</legend>
                                    <select class="select select-bordered w-full" form="promotion-form"
                                            :name="`tiers[${ti}][give_group_id]`" x-model="tier.give_group_id">
                                        <option value="">—</option>
                                        @foreach ($groupOptions as $g)
                                            <option value="{{ $g['id'] }}">{{ $g['label'] }}</option>
                                        @endforeach
                                    </select>
                                </fieldset>
                            </template>

                            <template x-if="tier.discount_type === 'free_product'">
                                <fieldset class="fieldset p-0 w-52">
                                    <legend class="fieldset-legend">{{ __('Gift product (SKU)') }}</legend>
                                    <select class="select select-bordered w-full" form="promotion-form"
                                            :name="`tiers[${ti}][free_product_id]`" x-model="tier.free_product_id">
                                        <option value="">—</option>
                                        @foreach ($productOptions as $p)
                                            <option value="{{ $p['id'] }}">{{ $p['label'] }}</option>
                                        @endforeach
                                    </select>
                                </fieldset>
                            </template>

                            <template x-if="tier.discount_type === 'free_product'">
                                <fieldset class="fieldset p-0 w-20">
                                    <legend class="fieldset-legend">{{ __('Qty') }}</legend>
                                    <input type="number" min="1" class="input input-bordered w-full" form="promotion-form"
                                           :name="`tiers[${ti}][free_qty]`" x-model="tier.free_qty">
                                </fieldset>
                            </template>

                            <div class="grow"></div>
                            <button type="button" class="btn btn-ghost btn-sm text-error" @click="tiers.splice(ti, 1)">{{ __('Remove tier') }}</button>
                        </div>

                        <template x-if="['product', 'category'].includes(tier.discount_scope_type) && adjusting.includes(tier.discount_type)">
                            <div class="pl-2 border-l-2 border-base-200">
                                <fieldset class="fieldset p-0 w-full max-w-md">
                                    <legend class="fieldset-legend" x-text="tier.discount_scope_type === 'category' ? '{{ __('Categories the discount applies to') }}' : '{{ __('Products the discount applies to') }}'"></legend>
                                    <select multiple size="3" class="select select-bordered w-full h-auto" form="promotion-form"
                                            :name="`tiers[${ti}][discount_scope_ids][]`" x-model="tier.discount_scope_ids">
                                        <template x-if="tier.discount_scope_type === 'category'">
                                            <template x-for="c in {{ \Illuminate\Support\Js::from($categoryOptions) }}" :key="c.id">
                                                <option :value="c.id" x-text="c.label"></option>
                                            </template>
                                        </template>
                                        <template x-if="tier.discount_scope_type === 'product'">
                                            <template x-for="p in {{ \Illuminate\Support\Js::from($productOptions) }}" :key="p.id">
                                                <option :value="p.id" x-text="p.label"></option>
                                            </template>
                                        </template>
                                    </select>
                                </fieldset>
                            </div>
                        </template>

                        <div class="pl-2 border-l-2 border-base-200 flex flex-col gap-2">
                            <template x-for="(row, ri) in tier.rules" :key="ri">
                                <div class="flex flex-wrap gap-2 items-end">
                                    <fieldset class="fieldset p-0 w-40">
                                        <legend class="fieldset-legend">{{ __('When') }}</legend>
                                        <select class="select select-bordered w-full" form="promotion-form"
                                                :name="`tiers[${ti}][rules][${ri}][kind]`" x-model="row.kind" @change="onKindChange(row)">
                                            <template x-for="(label, kind) in labels()" :key="kind">
                                                <option :value="kind" x-text="label"></option>
                                            </template>
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 w-48">
                                        <legend class="fieldset-legend">{{ __('Is') }}</legend>
                                        <select class="select select-bordered w-full" form="promotion-form"
                                                :name="`tiers[${ti}][rules][${ri}][operator]`" x-model="row.operator">
                                            <template x-for="(label, op) in operators()[row.kind]" :key="op">
                                                <option :value="op" x-text="label"></option>
                                            </template>
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 w-56" x-show="row.kind === 'category'" x-cloak>
                                        <legend class="fieldset-legend">{{ __('Categories') }}</legend>
                                        <select multiple size="3" class="select select-bordered w-full h-auto" form="promotion-form"
                                                :name="`tiers[${ti}][rules][${ri}][ids][]`" x-model="row.ids">
                                            @foreach ($categoryOptions as $c)
                                                <option value="{{ $c['id'] }}">{{ $c['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 w-56" x-show="row.kind === 'product'" x-cloak>
                                        <legend class="fieldset-legend">{{ __('Products (by SKU)') }}</legend>
                                        <select multiple size="3" class="select select-bordered w-full h-auto" form="promotion-form"
                                                :name="`tiers[${ti}][rules][${ri}][ids][]`" x-model="row.ids">
                                            @foreach ($productOptions as $p)
                                                <option value="{{ $p['id'] }}">{{ $p['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 w-56" x-show="row.kind === 'usergroup'" x-cloak>
                                        <legend class="fieldset-legend">{{ __('Groups') }}</legend>
                                        <select multiple size="3" class="select select-bordered w-full h-auto" form="promotion-form"
                                                :name="`tiers[${ti}][rules][${ri}][ids][]`" x-model="row.ids">
                                            @foreach ($groupOptions as $g)
                                                <option value="{{ $g['id'] }}">{{ $g['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 w-56" x-show="row.kind === 'payment'" x-cloak>
                                        <legend class="fieldset-legend">{{ __('Payment methods') }}</legend>
                                        <select multiple size="3" class="select select-bordered w-full h-auto" form="promotion-form"
                                                :name="`tiers[${ti}][rules][${ri}][ids][]`" x-model="row.ids">
                                            @foreach ($paymentMethodOptions as $m)
                                                <option value="{{ $m['id'] }}">{{ $m['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 w-44" x-show="row.kind === 'feature'" x-cloak>
                                        <legend class="fieldset-legend">{{ __('Feature') }}</legend>
                                        <select class="select select-bordered w-full" form="promotion-form"
                                                :name="`tiers[${ti}][rules][${ri}][feature_id]`" x-model="row.feature_id">
                                            <option value="">—</option>
                                            @foreach ($featureOptions as $f)
                                                <option value="{{ $f['id'] }}">{{ $f['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 w-44" x-show="row.kind === 'feature'" x-cloak>
                                        <legend class="fieldset-legend">{{ __('Value') }}</legend>
                                        <select class="select select-bordered w-full" form="promotion-form"
                                                :name="`tiers[${ti}][rules][${ri}][value]`" x-model="row.value">
                                            <template x-for="v in valuesFor(row.feature_id)" :key="v.id">
                                                <option :value="v.id" x-text="v.label"></option>
                                            </template>
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 w-32" x-show="['price', 'subtotal', 'quantity'].includes(row.kind)" x-cloak>
                                        <legend class="fieldset-legend" x-text="row.kind === 'quantity' ? '{{ __('Pieces') }}' : '{{ __('Amount') }}'"></legend>
                                        <input type="number" step="0.01" min="0" class="input input-bordered w-full" form="promotion-form"
                                               :name="`tiers[${ti}][rules][${ri}][value]`" x-model="row.value">
                                    </fieldset>

                                    <button type="button" class="btn btn-ghost btn-sm text-error" @click="tier.rules.splice(ri, 1)">{{ __('Remove') }}</button>
                                </div>
                            </template>

                            <div>
                                <button type="button" class="btn btn-sm gap-1" @click="addRule(tier)"><x-icon name="plus" size="sm" /> {{ __('Add a condition') }}</button>
                                <span class="text-xs opacity-50" x-show="tier.rules.length === 0" x-cloak>{{ __('No conditions: matches everything.') }}</span>
                            </div>
                        </div>
                        </div>

                        <div class="flex items-center gap-1.5 text-xs opacity-40" x-show="ti < tiers.length - 1" x-cloak>
                            <x-icon name="corner-down-right" size="sm" />
                            {{ __('else, try the next tier') }}
                        </div>
                        </div>
                    </div>
                </template>

                <div>
                    <button type="button" class="btn btn-sm gap-1.5" @click="addTier()"><x-icon name="plus" size="sm" /> {{ __('Add a tier') }}</button>
                </div>
            </div>
        </div>
    </form>

    @if ($promotion->exists)
        {{-- Beside the form, never inside it: a nested form is dropped by the
             parser while its inputs stay behind. The gear's buttons aim here. --}}
        <form method="POST" id="promotion-duplicate" action="{{ route('admin.promotions.duplicate', $promotion) }}" class="hidden">@csrf</form>
        <form method="POST" id="promotion-delete" action="{{ route('admin.promotions.destroy', $promotion) }}" class="hidden"
              data-confirm="{{ __('Delete this promotion?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
