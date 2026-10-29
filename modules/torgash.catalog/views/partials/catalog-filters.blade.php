@php
    use Torgash\Catalog\Filters\Flags;

    $flags = app(Flags::class);
    $hasActiveFilters = request()->filled('price_min') || request()->filled('price_max')
        || $flags->anyActive(request()) || collect($filters['selected'])->flatten()->isNotEmpty();

    // Which blocks start OPEN (setting `filter_open_blocks`): all, none, or
    // the first few, counted from the price. A block with something in force
    // is open whatever the setting says — a ticked box hidden under a closed
    // heading is a filter the visitor cannot see is on. Each block is a
    // <details>, so without a script a heading still opens it.
    $filterOpenSetting = (string) setting('torgash.catalog', 'filter_open_blocks', 'all');
    $filterOpenFirst = match ($filterOpenSetting) {
        'all' => PHP_INT_MAX,
        'none' => 0,
        default => max(0, (int) $filterOpenSetting),
    };
    $filterBlockIndex = 0;
    $filterBlockOpen = function (bool $inForce) use (&$filterBlockIndex, $filterOpenFirst) {
        return $filterBlockIndex++ < $filterOpenFirst || $inForce;
    };
    // From how many values a block gets a search box and scrolls inside
    // itself rather than stretching the panel (0 = never).
    $filterSearchFrom = max(0, (int) setting('torgash.catalog', 'filter_search_from', 10));
@endphp

@php
    // The panel speaks the VISITOR's currency — the one every card on the page
    // is priced in. The numbers are converted from the books here, and typed
    // ones go back with `price_currency` beside them, which the server turns
    // into the books again (FilterPages::priceBounds()). In the shop's own
    // currency there is nothing to convert and no `price_currency` to send.
    $priceCurrencyCode = current_currency();
    $priceCurrency = app(\App\Core\Support\Currencies::class)->find($priceCurrencyCode);
    $priceRate = $priceCurrencyCode === shop_currency() ? 1.0 : (float) ($priceCurrency?->rate ?: 1);
    $priceSymbol = $priceCurrency?->symbol ?: $priceCurrencyCode;
    // The range this page's products span, in whole units of that currency.
    $priceFloor = (int) floor(((int) $filters['price_min']) / 100 * $priceRate);
    $priceCeil = (int) ceil(((int) $filters['price_max']) / 100 * $priceRate);
    // About two hundred stops across the range, rounded to 1, 2 or 5 of a
    // power of ten, so the thumb lands on numbers a person would type.
    $priceSpan = max(1, $priceCeil - $priceFloor);
    $priceMagnitude = 10 ** (int) floor(log10(max(1, $priceSpan / 200)));
    $priceStep = max(1, collect([1, 2, 5, 10])->map(fn ($m) => $m * $priceMagnitude)->first(fn ($s) => $priceSpan / $s <= 200) ?? $priceMagnitude * 10);
    // What the boxes hold. Typed in THIS currency: shown as typed. Typed in
    // another (a link from a visitor in euros), or a published price page's
    // own bounds: the books, converted.
    [$priceBooksMin, $priceBooksMax] = \Torgash\Catalog\Filters\FilterPages::priceBounds(request());
    $priceTypedHere = strtoupper((string) request('price_currency', shop_currency())) === $priceCurrencyCode;
    $priceShown = function (string $field, ?int $books, $pageBare) use ($priceTypedHere, $priceRate) {
        if (request()->filled($field)) {
            return $priceTypedHere ? (string) request($field) : (string) round($books / 100 * $priceRate);
        }

        return is_numeric($pageBare) ? (string) round((float) $pageBare * $priceRate) : '';
    };
    $priceTypedMin = $priceShown('price_min', $priceBooksMin, $filters['page_price_min'] ?? null);
    $priceTypedMax = $priceShown('price_max', $priceBooksMax, $filters['page_price_max'] ?? null);
@endphp
<details class="group bg-base-100 border border-base-300 rounded-box" data-filter-block="price"
         @if ($filterBlockOpen($priceTypedMin !== '' || $priceTypedMax !== '')) open @endif>
    @include('torgash.catalog::partials.filter-block-heading', ['filterHeading' => __('Price'), 'filterCount' => 0])
    <div class="px-4 pb-4">
    {{-- ONE field to the eye, two controls to the form: `price_min` and
         `price_max` are still the only things that submit. --}}
    <div class="input w-full px-0 gap-0 overflow-hidden">
        <input type="number" name="price_min" value="{{ $priceTypedMin }}" min="0" inputmode="numeric"
               placeholder="{{ $priceFloor }}" aria-label="{{ __('Price from') }}"
               class="torgash-qty grow w-0 h-full px-1.5 bg-transparent text-center text-sm tabular-nums outline-none">
        <span class="opacity-30 shrink-0" aria-hidden="true">&mdash;</span>
        <input type="number" name="price_max" value="{{ $priceTypedMax }}" min="0" inputmode="numeric"
               placeholder="{{ $priceCeil }}" aria-label="{{ __('Price to') }}"
               class="torgash-qty grow w-0 h-full px-1.5 bg-transparent text-center text-sm tabular-nums outline-none">
        <span class="shrink-0 self-stretch flex items-center px-3 text-sm opacity-60 bg-base-200 border-s border-base-300">{{ $priceSymbol }}</span>
    </div>
    @if ($priceRate !== 1.0)
        {{-- Which currency the two numbers are in. The panel's script sends it
             only beside a typed price (data-filter-with). --}}
        <input type="hidden" name="price_currency" value="{{ $priceCurrencyCode }}" data-filter-with="price_min price_max">
    @endif
    @if ($priceCeil > $priceFloor)
        {{-- The slider is a way of TYPING into the two boxes above, nothing
             more: its inputs have no name, so they never submit, and a box
             left at the edge of the range is emptied rather than filled with
             the edge — "no limit", not a limit that happens to be today's
             cheapest price. Drawn only where a script runs. --}}
        <div class="torgash-js-only torgash-range mt-4 mb-1" data-price-range
             style="--from: 0%; --to: 100%">
            <input type="range" min="{{ $priceFloor }}" max="{{ $priceCeil }}" step="{{ $priceStep }}"
                   value="{{ is_numeric($priceTypedMin) ? max($priceFloor, min($priceCeil, (float) $priceTypedMin)) : $priceFloor }}"
                   data-price-range-for="price_min" aria-label="{{ __('Price from') }}">
            <input type="range" min="{{ $priceFloor }}" max="{{ $priceCeil }}" step="{{ $priceStep }}"
                   value="{{ is_numeric($priceTypedMax) ? max($priceFloor, min($priceCeil, (float) $priceTypedMax)) : $priceCeil }}"
                   data-price-range-for="price_max" aria-label="{{ __('Price to') }}">
        </div>
    @endif
    </div>
</details>

{{-- The yes/no filters: which of them exist is a registry
     (Torgash\Catalog\Filters\Flags), which of them this shop offers is a
     setting, and which of them can narrow THIS page was worked out by the
     controller. None of the three was expressible while the stock box was
     written out here by hand. --}}
@if ($filters['flags'])
    <div class="bg-base-100 border border-base-300 rounded-box p-4 flex flex-col gap-1.5" data-filter-block>
        @foreach ($filters['flags'] as $flag)
            <label class="flex items-center gap-2 py-0.5 text-sm cursor-pointer hover:text-primary">
                <input type="checkbox" name="{{ $flag }}" value="1" class="checkbox checkbox-primary"
                       @checked(in_array($flag, $filters['flags_on'] ?? [], true))>
                <span>{{ $flags->label($flag) }}</span>
            </label>
        @endforeach
    </div>
@endif

@foreach ($filters['features'] as $feature)
    @continue($feature->values->isEmpty())
    @php
        $filterTicked = array_map('intval', (array) ($filters['selected'][$feature->id] ?? []));
        $filterLong = $filterSearchFrom > 0 && $feature->values->count() > $filterSearchFrom;
        // In a long list the ticked values come first, so a scrolled block
        // never hides what is in force below its fold.
        $filterValues = $filterLong
            ? $feature->values->sortBy(fn ($value) => in_array((int) $value->id, $filterTicked, true) ? 0 : 1)->values()
            : $feature->values;
    @endphp
    <details class="group bg-base-100 border border-base-300 rounded-box" data-filter-block="f{{ $feature->id }}"
             @if ($filterBlockOpen($filterTicked !== [])) open @endif>
        @include('torgash.catalog::partials.filter-block-heading', [
            'filterHeading' => $feature->t('name'),
            'filterCount' => count($filterTicked),
        ])
        <div class="px-4 pb-4 flex flex-col gap-2">
            @if ($filterLong)
                {{-- Narrows the list under it, in the browser: every value is
                     already on the page. No name, so it never submits. --}}
                <label class="torgash-js-only input input-sm w-full">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" data-filter-search autocomplete="off" placeholder="{{ __('Search…') }}"
                           aria-label="{{ __('Search') }}: {{ $feature->t('name') }}">
                </label>
            @endif
            {{-- How the values look is the characteristic's `filter_style`
                 (Feature::filterStyle()): ticks in a list, buttons in rows, or
                 colour swatches. The CONTROL is the same checkbox in every
                 style — in the other two it is visually hidden and its label
                 is drawn from its state (peer-checked) — so the address, a
                 filter page and a browser without JS see no difference. --}}
            @php $filterStyle = $feature->filterStyle(); @endphp
            <div @class([
                    'flex flex-col gap-1.5' => $filterStyle === 'list',
                    'flex flex-wrap gap-1.5' => $filterStyle === 'chips',
                    'flex flex-wrap gap-2 p-0.5' => $filterStyle === 'swatches',
                    // A computer scrolls a long list inside itself; a phone,
                    // whose sheet already scrolls, shows the first eight and a
                    // "Show all" under them — a scroll inside a scroll is the
                    // one a thumb keeps catching (app.css, "filter-long").
                    'torgash-filter-long torgash-scroll-thin lg:max-h-64 lg:overflow-y-auto lg:overscroll-contain lg:-me-2 lg:pe-2' => $filterLong,
                 ]) @if ($filterLong) data-filter-long @endif>
                @foreach ($filterValues as $value)
                    @php $spoken = $filters['params'][$feature->id]['values'][$value->id] ?? null; @endphp
                    @if ($filterStyle !== 'list')
                        @php $filterSwatch = $filterStyle === 'swatches' && $value->swatch; @endphp
                        <label class="cursor-pointer" data-filter-value @if ($filterSwatch) title="{{ $value->display() }}" @endif>
                            <input type="checkbox" name="feature[{{ $feature->id }}][]" value="{{ $value->id }}" class="peer sr-only"
                                   @if ($spoken) data-param="{{ $filters['params'][$feature->id]['param'] }}" data-value="{{ $spoken }}" @endif
                                   @checked(in_array((int) $value->id, $filterTicked, true))>
                            @if ($filterSwatch)
                                <span class="block h-8 w-8 rounded-full border border-black/15 ring-offset-2 ring-offset-base-100 hover:ring-2 hover:ring-base-300 peer-checked:ring-2 peer-checked:ring-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary/60"
                                      style="background:{{ $value->swatch }}"></span>
                                <span class="sr-only">{{ $value->display() }}</span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full border border-base-300 text-sm hover:border-primary/50 peer-checked:border-primary peer-checked:bg-primary/10 peer-checked:text-primary peer-checked:font-medium peer-focus-visible:outline-2 peer-focus-visible:outline-primary">
                                    @include('torgash.catalog::partials.feature-value-swatch', ['feature' => $feature, 'value' => $value])
                                    {{ $value->display() }}
                                </span>
                            @endif
                        </label>
                        @continue
                    @endif
                    <label class="flex items-center gap-2 py-0.5 text-sm cursor-pointer hover:text-primary" data-filter-value>
                        {{-- The box still carries the id form, which is what a
                             browser with no JS submits and what every old link
                             already says. When the characteristic and the value
                             both have a code, it also carries the readable name,
                             and the form's own handler builds the spoken address
                             out of those — see index.blade.php. --}}
                        <input type="checkbox" name="feature[{{ $feature->id }}][]" value="{{ $value->id }}" class="checkbox checkbox-primary"
                               @if ($spoken) data-param="{{ $filters['params'][$feature->id]['param'] }}" data-value="{{ $spoken }}" @endif
                               @checked(in_array((int) $value->id, $filterTicked, true))>
                        @include('torgash.catalog::partials.feature-value-swatch', ['feature' => $feature, 'value' => $value])
                        <span>{{ $value->display() }}</span>
                    </label>
                @endforeach
                @if ($filterLong)
                    <p class="text-sm opacity-50 py-1" data-filter-search-empty hidden>{{ __('Nothing found') }}</p>
                @endif
            </div>
            @if ($filterLong)
                <button type="button" class="torgash-filter-more link link-hover text-sm text-start" data-filter-more>
                    {{ __('Show all (:count)', ['count' => $filterValues->count()]) }}
                </button>
            @endif
        </div>
    </details>
@endforeach

<div class="bg-base-100 border border-base-300 rounded-box p-4 flex gap-2 torgash-sheet-footer">
    <button class="btn btn-primary btn-sm btn-touch flex-1" data-filter-submit>{{ __('Apply') }}</button>
    @if ($hasActiveFilters)
        <a href="{{ $currentUrl }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
    @endif
</div>

{{-- "Show 12 products", beside the block just changed — on a computer the
     panel is taller than the screen and Apply is at its foot, a scroll away
     from the box that was ticked. It submits the same form; the number is
     the server's answer to the boxes as they stand (StorefrontController::
     countAnswer(), the listing's own query), asked before anything is
     applied. On a phone the sheet's footer is already stuck to the bottom of
     the screen and this stays away. --}}
<div class="torgash-filter-apply" data-filter-apply hidden>
    <button type="submit" class="btn btn-primary btn-sm btn-touch shadow-lg whitespace-nowrap">
        <span class="torgash-filter-apply-arrow" aria-hidden="true"></span>
        <span data-filter-apply-text>{{ __('Apply') }}</span>
    </button>
</div>

<script nonce="{{ csp_nonce() }}">
    (function () {
        var form = document.getElementById('catalog-filters');

        if (! form) {
            return;
        }

        // The slider moves the boxes; the boxes move the slider.
        form.querySelectorAll('[data-price-range]').forEach(function (range) {
            var thumbs = range.querySelectorAll('input[type=range]');
            var low = thumbs[0], high = thumbs[1];
            var min = Number(low.min), max = Number(low.max);

            function box(thumb) {
                return form.elements[thumb.dataset.priceRangeFor];
            }

            function paint() {
                range.style.setProperty('--from', ((Number(low.value) - min) / (max - min) * 100) + '%');
                range.style.setProperty('--to', ((Number(high.value) - min) / (max - min) * 100) + '%');
            }

            [low, high].forEach(function (thumb) {
                thumb.addEventListener('input', function () {
                    // The thumbs may not cross: the one being dragged stops
                    // at the other.
                    if (Number(low.value) > Number(high.value)) {
                        thumb.value = thumb === low ? high.value : low.value;
                    }

                    var atEdge = thumb === low ? Number(thumb.value) <= min : Number(thumb.value) >= max;

                    box(thumb).value = atEdge ? '' : thumb.value;
                    box(thumb).dispatchEvent(new Event('input', { bubbles: true }));
                    paint();
                });
            });

            [low, high].forEach(function (thumb) {
                box(thumb).addEventListener('input', function (event) {
                    if (event.target !== document.activeElement) {
                        return;
                    }

                    var typed = event.target.value === '' ? (thumb === low ? min : max) : Number(event.target.value);

                    thumb.value = Math.max(min, Math.min(max, typed));
                    paint();
                });
            });

            paint();
        });

        // A long list narrowed by what is typed above it.
        form.querySelectorAll('[data-filter-search]').forEach(function (search) {
            var block = search.closest('[data-filter-block]');
            var empty = block.querySelector('[data-filter-search-empty]');

            search.addEventListener('input', function () {
                var typed = search.value.trim().toLocaleLowerCase();
                var shown = 0;

                block.querySelectorAll('[data-filter-value]').forEach(function (row) {
                    var hit = typed === '' || row.textContent.toLocaleLowerCase().indexOf(typed) !== -1;

                    row.hidden = ! hit;
                    shown += hit ? 1 : 0;
                });

                if (empty) {
                    empty.hidden = shown > 0;
                }

                // What is typed is looked for in the whole list, folded or not.
                block.querySelector('[data-filter-long]').classList.toggle('is-searching', typed !== '');
            });

            // Enter in the search box is not "apply the filters".
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
            });
        });

        // A phone's folded long list, opened whole by its "Show all".
        form.querySelectorAll('[data-filter-more]').forEach(function (more) {
            more.addEventListener('click', function () {
                more.closest('[data-filter-block]').querySelector('[data-filter-long]').classList.add('is-all');
                more.hidden = true;
            });
        });

        // Which blocks the visitor opened or closed, kept in this browser —
        // a convenience, so it fails quietly where storage is refused. A block
        // with something in force stays open whatever was remembered.
        var remembered = {};

        try {
            remembered = JSON.parse(localStorage.getItem('torgash.filterBlocks') || '{}') || {};
        } catch (error) {}

        form.querySelectorAll('details[data-filter-block]').forEach(function (block) {
            var key = block.dataset.filterBlock;
            var inForce = block.querySelector('input:checked') !== null
                || Array.prototype.some.call(block.querySelectorAll('input[type=number][name]'), function (input) { return input.value !== ''; });

            if (key in remembered && ! inForce) {
                block.open = remembered[key];
            }

            // The heading's click, not `toggle`: the browser fires `toggle`
            // for every block that STARTS open as well, and remembering those
            // would freeze today's setting into every visitor's browser.
            block.querySelector('summary').addEventListener('click', function () {
                remembered[key] = ! block.open;

                try {
                    localStorage.setItem('torgash.filterBlocks', JSON.stringify(remembered));
                } catch (error) {}
            });
        });

        // The floating button on a computer; on a phone the sheet's own
        // Apply at its foot says the same number.
        var bubble = form.querySelector('[data-filter-apply]');
        var text = bubble && bubble.querySelector('[data-filter-apply-text]');
        var footer = form.querySelector('[data-filter-submit]');
        var wide = window.matchMedia('(min-width: 64rem)');
        var timer = null;
        var asked = null;

        function place(control) {
            var block = control.closest('[data-filter-block]') || control;
            var box = control.getBoundingClientRect();
            var top = box.top + box.height / 2 - form.getBoundingClientRect().top;

            // Level with the MIDDLE of the control that changed (the bubble
            // centres itself on `top`), but never past its own block's edges.
            top = Math.max(block.offsetTop + 20, Math.min(top, block.offsetTop + block.offsetHeight - 20));
            bubble.style.top = top + 'px';
        }

        function count() {
            if (asked) {
                asked.abort();
            }

            asked = new AbortController();
            bubble.classList.add('is-busy');

            fetch(window.torgashCatalogAddress(form), {
                headers: { 'X-Catalog-Count': '1' },
                signal: asked.signal,
            })
                .then(function (response) { return response.ok ? response.text() : Promise.reject(); })
                .then(function (phrase) {
                    (wide.matches ? text : footer).textContent = phrase;
                    bubble.classList.remove('is-busy');
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }

                    bubble.classList.remove('is-busy');
                });
        }

        function changed(event) {
            if (! bubble || ! event.target.name || ! event.target.closest('[data-filter-block]')) {
                return;
            }

            if (wide.matches) {
                place(event.target);
                bubble.hidden = false;
            }

            clearTimeout(timer);
            timer = setTimeout(count, event.type === 'input' ? 400 : 0);
        }

        form.addEventListener('change', changed);
        form.addEventListener('input', changed);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && bubble) {
                bubble.hidden = true;
            }
        });
    })();
</script>
