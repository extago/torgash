{{--
    One section's body — the same content whichever layout is drawing it.

    `$detailTab` is one entry from Torgash\Catalog\Support\ProductSections: a
    written section carries its finished html, the page's own two carry none and
    are rendered here. Named `$detailTab` and not `$section`, because an
    @include inherits every variable in the caller's scope and a common name is
    how a partial quietly picks up whatever a loop two hundred lines earlier
    left behind.
--}}
@if (isset($detailTab['html']) && ! empty($detailTab['rich']))
    <div class="torgash-rich opacity-80 leading-relaxed">{!! $detailTab['html'] !!}</div>
@elseif (isset($detailTab['html']))
    {{-- A module's own section, drawn as the module drew it. --}}
    {!! $detailTab['html'] !!}
@elseif ($detailTab['key'] === 'description')
    {{-- `$descriptionParts` is the page's (product.blade.php): above, INSTEAD
         of and below the text — asked once, isolated (zone / hook_render). --}}
    {!! $descriptionParts['before'] ?? '' !!}
    @if (trim($descriptionParts['instead'] ?? '') !== '')
        {!! $descriptionParts['instead'] !!}
    @elseif ($product->hasDescription())
    {{-- Admin-authored markup from the rich text editor, printed as HTML —
         cleaned first: the right to edit products is not the right to run
         scripts on the shop (App\Core\Content\SafeHtml). --}}
    {{-- A long description folds (PLAN §38): the first screenful, a fade, and
         "Show in full" — the reviews and the characteristics below it are what
         a customer scrolls for next. The height is the shop's
         (`description_fold_height`, 0 = never); partials/fold measures it. --}}
    @php
        $foldHeight = max(0, (int) setting('torgash.catalog', 'description_fold_height', 420));
        $descriptionHtml = '<div class="torgash-rich opacity-80 leading-relaxed">'.safe_html($product->t('description')).'</div>';
    @endphp
    @if ($foldHeight > 0)
        @include('torgash.catalog::partials.fold', [
            'foldHtml' => $descriptionHtml,
            'foldMore' => __('Show the whole description'),
            'foldLess' => __('Fold the description'),
        ])
    @else
        {!! $descriptionHtml !!}
    @endif
    @endif
    {!! $descriptionParts['after'] ?? '' !!}
@elseif ($detailTab['key'] === 'specs')
    @php
        // GROUPS, each kept whole: the catalogued characteristics under their
        // own characteristic group (the admin's), then the product's own rows
        // cut at each of its section headings — rows before the first heading
        // are a group with no name. A group is the unit the two columns below
        // share out, so a heading never ends one column with its rows starting
        // the next.
        $specGroups = [];

        foreach ($globalSpecs->groupBy(fn ($pf) => (int) $pf->feature->group_id) as $specGroupRows) {
            $specGroups[] = [
                'title' => $specGroupRows->first()->feature->group?->t('name'),
                'rows' => $specGroupRows->map(fn ($pf) => [$pf->feature->t('name'), $pf->display()])->all(),
            ];
        }

        $specOwn = null;

        foreach ($specFeatures as $specRow) {
            if ($specRow['type'] === 'section') {
                if ($specOwn && $specOwn['rows'] !== []) {
                    $specGroups[] = $specOwn;
                }
                $specOwn = ['title' => $specRow['label'], 'rows' => []];

                continue;
            }

            $specOwn ??= ['title' => null, 'rows' => []];
            $specOwn['rows'][] = [
                $specRow['label'] ?? $specRow['key'],
                is_scalar($specRow['value']) ? $specRow['value'] : json_encode($specRow['value'], JSON_UNESCAPED_UNICODE),
            ];
        }

        if ($specOwn && $specOwn['rows'] !== []) {
            $specGroups[] = $specOwn;
        }

        // One group is a list, not a layout: two columns only once there is
        // something to share out.
        $specColumns = count($specGroups) > 1;
    @endphp
    {{-- CSS columns, each group `break-inside-avoid`: the browser balances
         the two by height and moves a whole group across, never half of one.
         Down the first column, then the second — the reading order is the
         admin's order. --}}
    {{-- Folds like the description when the shop asks for it
         (`specs_fold_height`, off by default: the characteristics are often
         the very thing a customer came to read). --}}
    @php $specsFold = max(0, (int) setting('torgash.catalog', 'specs_fold_height', 0)); @endphp
    @if ($specsFold > 0)
    <div x-data="{ open: false, long: false }" x-init="long = $refs.specs.scrollHeight > {{ (int) round($specsFold * 1.15) }}">
    <div class="relative" :class="long && ! open && 'overflow-hidden'" :style="long && ! open ? 'max-height: {{ $specsFold }}px' : ''">
    @endif
    <div x-ref="specs" @class(['lg:columns-2 lg:gap-x-12' => $specColumns])>
        @foreach ($specGroups as $specGroup)
            <section class="break-inside-avoid pb-6">
                @if ($specGroup['title'])
                    <h3 class="text-sm font-semibold pb-2 mb-1 border-b border-base-300">{{ $specGroup['title'] }}</h3>
                @endif
                <dl class="flex flex-col">
                    @foreach ($specGroup['rows'] as [$specLabel, $specValue])
                        {{-- The name and the value joined by a dotted line, so
                             the eye travels along the row rather than
                             guessing which value is whose across a wide gap. --}}
                        <div class="flex items-baseline gap-2 py-1.5 text-sm">
                            <dt class="opacity-60 shrink min-w-0">{{ $specLabel }}</dt>
                            <span class="grow min-w-4 border-b border-dotted border-base-content/25" aria-hidden="true"></span>
                            <dd class="font-medium text-right max-w-[60%]">{{ $specValue }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
    @if ($specsFold > 0)
        <div x-show="long && ! open" x-cloak class="absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-base-100 to-transparent pointer-events-none"></div>
    </div>
    <button type="button" x-show="long" x-cloak x-on:click="open = ! open"
            class="btn btn-outline btn-sm btn-touch w-full mt-3 gap-1.5">
        <span x-text="open ? @js(__('Fold the characteristics')) : @js(__('Show all characteristics'))"></span>
        <span :class="open && 'rotate-180'" class="transition-transform"><x-icon name="chevron-down" size="sm" /></span>
    </button>
    </div>
    @endif
@endif
