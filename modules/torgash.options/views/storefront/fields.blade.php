@php
    use Torgash\Options\Models\OptionChoice;

    $rules = app(\Torgash\Options\PriceRules::class);
    $configurator = app(\Torgash\Options\Configurator::class);

    // Two things the ADMIN answers differently, and nothing else about this
    // form changes between the shop and the order editor — which is why it is
    // one view and not two that drift.
    //
    // `optionsLive`: on the storefront the server reprices on every change and
    // may redraw the questions; in the order editor there is nothing to
    // reprice against yet — the money appears in the items table when Apply is
    // pressed, exactly as it does for a quantity. A dependent question
    // therefore does not come and go while a manager types, and does not need
    // to: `normalise()` drops an answer to a question this build does not ask,
    // so what is SAVED is the same either way.
    //
    // `optionsPriceBase`: a placed order's line has the price its product was
    // sold at, and the "+500" beside a choice must be measured against that
    // and not against what the catalogue charges today.
    //
    // Named distinctively on purpose — a Blade include inherits every variable
    // in the caller's scope, and `$base`/`$live` are exactly the names
    // something two hundred lines away already has (docs/LESSONS.md).
    $optionsLive = $optionsLive ?? true;
    $base = isset($optionsPriceBase) ? (int) $optionsPriceBase : (int) $product->effectivePrice();
    $showPrices = (bool) setting('torgash.options', 'show_price_in_label', true);
    $searchThreshold = max(2, (int) setting('torgash.options', 'search_threshold', 8));

    // What is already answered — for a line being edited ("change this build"),
    // and for a form coming back with something wrong in it.
    $answered = [];
    $typed = [];
    $fileTokens = [];
    foreach ((array) ($config[\Torgash\Options\Configurator::KEY] ?? []) as $groupId => $entries) {
        foreach ((array) $entries as $entry) {
            $answered[(string) $groupId][(string) ($entry['c'] ?? '').(isset($entry['p']) ? ':'.$entry['p'] : '')] = (int) ($entry['q'] ?? 1);

            if (isset($entry['t'])) {
                $typed[(string) $groupId] = (string) $entry['t'];
            }

            if (isset($entry['f'])) {
                $fileTokens[(string) $groupId] = (string) $entry['f'];
            }
        }
    }

    // One answer is one <option>/<input> value: `choice` or `choice:product`,
    // so a set of products sits in the same control as a plain answer.
    $optionsFor = function ($group) use ($configurator, $rules, $base, $showPrices) {
        $rows = [];

        foreach ($group->choices as $choice) {
            if (! $choice->enabled) {
                continue;
            }

            if ($choice->isSet()) {
                foreach ($configurator->setProducts($choice) as $setProduct) {
                    $rows[] = [
                        'value' => $choice->id.':'.$setProduct->id,
                        'label' => $choice->label($setProduct),
                        'amount' => $rules->apply($choice, $base, $setProduct),
                        'choice' => $choice,
                        'product' => $setProduct,
                        // Drawn and refused rather than left out: "the i9 is
                        // sold out" is an answer, and a list that quietly
                        // shortens itself leaves the customer looking for a
                        // processor they have seen advertised. What is GONE —
                        // deleted, disabled, taken out of the set — is still
                        // not drawn: there is nothing to tell anybody about it.
                        'available' => $setProduct->isOrderable(),
                        'note' => $setProduct->isOrderable() ? null : $setProduct->stockLabel(),
                    ];
                }

                continue;
            }

            $chosen = $choice->kind === 'product' ? $configurator->productFor($choice, []) : null;

            // A `product` answer whose product has gone is not drawn at all —
            // there is no product left to say anything about.
            if ($choice->kind === 'product' && ! $chosen) {
                continue;
            }

            $rows[] = [
                'value' => (string) $choice->id,
                'label' => $choice->label($chosen),
                'amount' => $rules->apply($choice, $base, $chosen),
                'choice' => $choice,
                'product' => $chosen,
                'available' => ! $chosen || $chosen->isOrderable(),
                'note' => $chosen && ! $chosen->isOrderable() ? $chosen->stockLabel() : null,
            ];
        }

        return $rows;
    };

    $priceTag = fn (int $amount) => $showPrices && $amount !== 0
        ? ' '.($amount > 0 ? '+' : '−').format_price(abs($amount))
        : '';

    // One look for every answer that is ticked rather than typed: a framed
    // row the whole width of the column, the price at its right edge, and
    // the frame taking the accent once it is chosen — so a radio, a checkbox
    // and a "how many" read as one list instead of three kinds of control.
    // NOT daisyUI's `.label`: in v5 that class greys its text out, which is
    // what made every answer look disabled.
    $choiceRow = fn (bool $available) => 'flex items-center gap-3 rounded-field border border-base-300 bg-base-100 px-3 py-2 min-h-11 transition-colors '
        .($available ? 'cursor-pointer hover:border-base-content/30 has-checked:border-primary has-checked:bg-primary/5' : 'torgash-faded');
@endphp

@php
    // Does answering something here change WHICH questions are asked? Then the
    // form redraws itself; otherwise only the total does, exactly as before.
    // One boolean, asked of the one class that can answer it — the endpoint
    // asks the same one to decide what to send back, so the two cannot come to
    // disagree about which half of the page is being replaced.
    $redrawsForm = app(\Torgash\Options\Groups::class)->hasDependencies($product);
@endphp
{{-- The wiring lives on the WRAPPER, not on the element that is replaced.
     With hx-post on the total itself, the first swap threw away the very
     attributes that asked for the next one: the box recalculated once and then
     went quiet (measured on the live page). Events from the controls bubble
     here, so `change` needs no `from:` selector.

     Redrawing the form is the one case that replaces this element rather than
     something inside it, and it is safe for the reason the bug above was not:
     what comes back is THIS view, so the replacement carries the same wiring
     and htmx arms it again. Swapping `innerHTML` here would be the real
     mistake — the response holds this div, so the page would end up with one
     inside the other, two ids alike and two requests per change. --}}
<div class="flex flex-col gap-5" id="product-options"
     @if ($optionsLive)
     hx-post="{{ route('options.quote', $product->slug) }}"
     @if ($redrawsForm)
         {{-- `change` only, and never `input`: a question can hang off a
              CHOICE and never off typed text, so redrawing every 400ms while
              somebody types an engraving would take the field out from under
              their cursor for nothing. What a typed option costs does not
              depend on what is written in it either — only on whether anything
              is — and `change` fires on a text field when it is left, which is
              when that stops being true. --}}
         hx-trigger="change"
         hx-target="this"
         hx-swap="outerHTML"
     @else
         hx-trigger="change, input delay:400ms"
         hx-target="#options-total"
         hx-swap="innerHTML"
     @endif
     hx-include="closest form"
     {{-- The newest answer wins. htmx's default DROPS a change made while a
          quote is in flight, and when the form redraws, what comes back is
          the form as it was before that change — a stepper pressed twice
          quickly is exactly that. Replacing aborts the stale quote and asks
          again with everything on screen. --}}
     hx-sync="this:replace"
     @endif>
    @foreach ($groups as $group)
        @php
            $rows = $optionsFor($group);
            $groupErrors = (array) ($errors['options.'.$group->id] ?? []);
            $chosen = $answered[(string) $group->id] ?? [];
            // Nothing LEFT is nothing to ask — every answer deleted, disabled
            // or gone from its set — and drawing an empty control would make a
            // required question impossible to answer. A question whose answers
            // are merely sold out is still asked: the answers are drawn,
            // refused and say why, which is what the customer came to find out.
        @endphp
        {{-- …but an option the customer WRITES or UPLOADS has nothing to
             offer and is asked all the same. --}}
        @continue ($rows === [] && ! $group->isTyped() && ! $group->isFile())

        {{-- Not daisyUI's `.fieldset`: it sets the whole question in 12px
             type, which is what made the configurator read as small print
             beside the rest of the buy box. --}}
        <fieldset class="min-w-0" data-options-group="{{ $group->id }}">
            <legend class="text-sm font-semibold pb-2">
                {{ $group->t('name') }}
                @if ($group->required)<span class="text-error">*</span>@endif
            </legend>
            <div class="flex flex-col gap-2">

            @if ($group->t('hint'))
                <p class="text-xs opacity-60 -mt-1">{{ $group->t('hint') }}</p>
            @endif

            @if ($group->type === 'select')
                <select name="config[options][{{ $group->id }}]" class="select w-full" aria-label="{{ $group->t('name') }}">
                    @unless ($group->required)
                        <option value="">{{ __('Not needed') }}</option>
                    @endunless
                    @foreach ($rows as $row)
                        {{-- An answer that cannot be bought is shown and
                             refused, never hidden: the customer is told why the
                             build they came for is not on offer. `disabled`
                             also keeps it out of the submission, and
                             Configurator::validate() refuses it again for
                             anyone who posts it by hand. --}}
                        <option value="{{ $row['value'] }}" @selected(isset($chosen[$row['value']])) @disabled(! $row['available'])>
                            {{ $row['label'] }}{{ $priceTag($row['amount']) }}@if ($row['note']) — {{ $row['note'] }}@endif
                        </option>
                    @endforeach
                </select>

            @elseif ($group->type === 'radio')
                <div class="flex flex-col gap-1.5">
                    @unless ($group->required)
                        <label class="{{ $choiceRow(true) }}">
                            <input type="radio" name="config[options][{{ $group->id }}]" value="" class="radio radio-primary"
                                   @checked($chosen === [])>
                            <span class="grow min-w-0 text-sm">{{ __('Not needed') }}</span>
                        </label>
                    @endunless
                    @foreach ($rows as $row)
                        <label class="{{ $choiceRow($row['available']) }}">
                            <input type="radio" name="config[options][{{ $group->id }}]" value="{{ $row['value'] }}"
                                   class="radio radio-primary" @checked(isset($chosen[$row['value']])) @disabled(! $row['available'])>
                            <span class="grow min-w-0 text-sm">
                                {{ $row['label'] }}
                                @if ($row['note'])
                                    <span class="block text-xs opacity-60">{{ $row['note'] }}</span>
                                @endif
                            </span>
                            @if ($showPrices && $row['amount'] !== 0)
                                <span class="shrink-0 text-sm tabular-nums whitespace-nowrap opacity-70">{{ trim($priceTag($row['amount'])) }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>

            @elseif ($group->type === 'checkboxes')
                <div class="flex flex-col gap-1.5">
                    @foreach ($rows as $row)
                        <label class="{{ $choiceRow($row['available']) }}">
                            <input type="checkbox" name="config[options][{{ $group->id }}][]" value="{{ $row['value'] }}"
                                   class="checkbox checkbox-primary" @checked(isset($chosen[$row['value']])) @disabled(! $row['available'])>
                            <span class="grow min-w-0 text-sm">
                                {{ $row['label'] }}
                                @if ($row['note'])
                                    <span class="block text-xs opacity-60">{{ $row['note'] }}</span>
                                @endif
                            </span>
                            @if ($showPrices && $row['amount'] !== 0)
                                <span class="shrink-0 text-sm tabular-nums whitespace-nowrap opacity-70">{{ trim($priceTag($row['amount'])) }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>

            @elseif ($group->isTyped())
                @php
                    // What it costs is the group's first choice — an engraving
                    // is priced exactly as a licence is — and a group with no
                    // choice is a free note to the workshop.
                    $priceChoice = $group->priceChoice();
                    $priceAmount = $priceChoice ? $rules->apply($priceChoice, $base, null) : 0;
                @endphp
                @if ($group->type === 'textarea')
                    <textarea name="config[options][{{ $group->id }}][text]" rows="3"
                              class="textarea w-full"
                              @if ($group->max_length) maxlength="{{ $group->max_length }}" @endif
                              placeholder="{{ $group->t('hint') ?: '' }}">{{ $typed[(string) $group->id] ?? '' }}</textarea>
                @else
                    <input type="text" name="config[options][{{ $group->id }}][text]"
                           value="{{ $typed[(string) $group->id] ?? '' }}"
                           class="input w-full"
                           @if ($group->max_length) maxlength="{{ $group->max_length }}" @endif
                           placeholder="{{ $group->t('hint') ?: '' }}">
                @endif
                <p class="text-xs opacity-50">
                    @if ($showPrices && $priceAmount !== 0)
                        {{ $priceAmount > 0 ? '+' : '−' }}{{ format_price(abs($priceAmount)) }}@if ($group->max_length) · @endif
                    @endif
                    @if ($group->max_length)
                        {{ __('up to :n characters', ['n' => $group->max_length]) }}
                    @endif
                </p>

            @elseif ($group->isFile())
                @php
                    // Priced exactly as an engraving is: the group's first
                    // choice, and a group with none is a free "send us your
                    // layout".
                    $priceChoice = $group->priceChoice();
                    $priceAmount = $priceChoice ? $rules->apply($priceChoice, $base, null) : 0;
                    $files = app(\Torgash\Options\Files::class);
                    // What this question is already answered with — the file
                    // the line was bought with, when the form was reopened to
                    // change something else.
                    $chosenFile = $files->find($fileTokens[(string) $group->id] ?? null);
                @endphp
                @if ($chosenFile)
                    {{-- The token, not the bytes: not re-uploading keeps the
                         file, which is the only way "change the processor"
                         can leave the artwork alone. --}}
                    <input type="hidden" name="config[options][{{ $group->id }}][token]" value="{{ $chosenFile->token }}">
                    <div class="flex items-center gap-2 text-sm">
                        <x-icon name="paperclip" size="sm" class="opacity-50 shrink-0" />
                        <span class="truncate">{{ $chosenFile->label() }}</span>
                    </div>
                @endif
                <input type="file" name="config[options][{{ $group->id }}][file]"
                       accept="{{ $files->accept($group) }}"
                       class="file-input w-full">
                <p class="text-xs opacity-50">
                    @if ($showPrices && $priceAmount !== 0)
                        {{ $priceAmount > 0 ? '+' : '−' }}{{ format_price(abs($priceAmount)) }} ·
                    @endif
                    {{ strtoupper(implode(', ', $files->types($group))) }}@if ($files->maxKb($group) > 0), {{ __('up to :n', ['n' => $files->maxKb($group) >= 1024 ? round($files->maxKb($group) / 1024, 1).' MB' : $files->maxKb($group).' KB']) }}@endif
                    @if ($chosenFile)
                        · {{ __('choosing another replaces it') }}
                    @endif
                </p>

            @elseif ($group->type === 'quantities')
                <div class="flex flex-col gap-1.5">
                    @foreach ($rows as $row)
                        @php $ceiling = $group->qtyCeiling($row['choice']); @endphp
                        <div class="flex items-center gap-3 rounded-field border border-base-300 bg-base-100 ps-3 pe-1 py-1">
                            <span class="grow min-w-0 text-sm">
                                {{ $row['label'] }}
                                @if ($showPrices && $row['amount'] !== 0)
                                    <span class="block text-xs tabular-nums opacity-60">{{ trim($priceTag($row['amount'])) }} {{ __('each') }}</span>
                                @endif
                            </span>
                            {{-- A number, not a tick: "how many power supplies"
                                 is the question, and 0 is a perfectly good
                                 answer to it. --}}
                            @include('torgash.options::storefront.stepper', [
                                'stepperName' => 'config[options]['.$group->id.']['.$row['value'].']',
                                'stepperValue' => $chosen[$row['value']] ?? 0,
                                'stepperMin' => 0,
                                'stepperMax' => $ceiling,
                            ])
                        </div>
                    @endforeach
                </div>

            @else
                {{-- Rows you add: the server case. Every row is rendered by the
                     server — the rows already answered and the ones added by
                     hand come from one view, so they cannot drift — and a set
                     inside a row can therefore be SEARCHED, which a list of two
                     hundred processors needs. With no JavaScript the first row
                     is still there and still buys. --}}
                @php
                    $rowEntries = [];
                    foreach ($chosen as $value => $qty) {
                        $rowEntries[] = ['value' => (string) $value, 'qty' => $qty];
                    }
                @endphp
                <div class="flex flex-col gap-2">
                    <div data-options-rows class="flex flex-col gap-2">
                        @foreach ($rowEntries ?: [null] as $rowIndex => $entry)
                            @include('torgash.options::storefront.row', [
                                'group' => $group,
                                'product' => $product,
                                'index' => $rowIndex,
                                'entry' => $entry,
                            ])
                        @endforeach
                    </div>
                    {{-- One more row, asked of the server. `hx-vals` gives it an
                         index nothing else on the page uses; the answers are
                         read as a LIST, so the key is only how the browser
                         tells its own inputs apart. --}}
                    <button type="button" class="btn btn-ghost btn-sm btn-touch self-start text-primary"
                            hx-get="{{ route('options.row', ['group' => $group, 'product' => $product->slug]) }}"
                            hx-vals="js:{ index: Date.now() }"
                            hx-target="previous [data-options-rows]"
                            hx-swap="beforeend">
                        <x-icon name="plus" size="sm" /> {{ __('Add…') }}
                    </button>
                </div>
            @endif

            @foreach ($groupErrors as $message)
                <p class="text-xs text-error">{{ $message }}</p>
            @endforeach
            </div>
        </fieldset>
    @endforeach

    {{-- What it all comes to, worked out by the SERVER on every change: the
         same code that will price the cart line, so a configurator cannot
         quote a number the cart then disagrees with. No JSON map of prices in
         the markup, and nothing to keep in step. --}}
    @if ($optionsLive)
        <div id="options-total">
            @include('torgash.options::storefront.total', ['product' => $product, 'config' => $config, 'qty' => 1])
        </div>
    @endif
</div>
