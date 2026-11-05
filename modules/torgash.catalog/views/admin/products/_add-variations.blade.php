{{--
    "Add variation": new members of this product's group, made FROM it
    (Support\VariationMaker). A dialog with its own form, standing BESIDE
    the product's form (never inside it — the nesting trap in CLAUDE.md),
    opened by the button on the Variations tab.

    One row per variation characteristic the group switches by or this
    product answers: its values as boxes, this product's own ticked, so the
    staff untick it and tick the ones to make — several on one row make
    several products, several rows multiply. The characteristics that make
    cards in the catalogue come first and say so: a new colour is a new
    card and wants photos of its own; a new size stays on this card.
--}}
@php
    use Torgash\Catalog\Models\Feature;

    $askedFeatures = collect($variationAxes)->map(fn ($axis) => $axis['feature'])->keyBy('id');
    $thisMember = $variationInfo['members']->firstWhere('id', $product->id) ?? $product;
    $ownAnswers = $thisMember->featureAssignments->keyBy('feature_id');
    foreach (Feature::query()->where('purpose', Feature::PURPOSE_VARIATION)->orderBy('sort')->orderBy('id')->get() as $variationFeature) {
        if ($ownAnswers->has($variationFeature->id) && ! $askedFeatures->has($variationFeature->id)) {
            $askedFeatures[$variationFeature->id] = $variationFeature;
        }
    }
    $askedFeatures = collect(\Torgash\Catalog\Support\Variations::axesForTable(
        $askedFeatures->map(fn ($feature) => ['feature' => $feature, 'options' => []])->values()->all(),
    ))->map(fn ($axis) => $axis['feature']);
    $makesCards = $askedFeatures->contains(fn ($feature) => $feature->variation_role !== Feature::ROLE_CHOICE);
@endphp
<dialog id="product-add-variations" class="modal">
    <div class="modal-box max-w-3xl p-0 flex flex-col max-h-[90vh]">
        <form method="POST" action="{{ route('admin.catalog.products.variations.add', $product) }}" class="contents"
              x-data="{
                  n: 0,
                  count() {
                      let n = 1, any = false;
                      this.$el.querySelectorAll('[data-axis]').forEach((axis) => {
                          const text = axis.querySelector('input[type=text]');
                          const c = text ? (text.value.trim() ? 1 : 0) : axis.querySelectorAll('input:checked').length;
                          if (c) { any = true; n *= c; }
                      });
                      this.n = any ? n : 0;
                  },
              }" x-init="count()" x-on:change="count()" x-on:input="count()">
            @csrf
            <div class="flex items-center gap-3 px-6 py-4 border-b border-base-300">
                <h3 class="font-semibold text-lg grow">{{ __('Add variation') }}</h3>
                <button type="button" class="btn btn-ghost btn-sm btn-square" x-on:click="$el.closest('dialog').close()" aria-label="{{ __('Close') }}">
                    <x-icon name="x" size="lg" />
                </button>
            </div>

            <div class="overflow-y-auto px-6 py-5 flex flex-col gap-5">
                @if ($askedFeatures->isEmpty())
                    <div class="alert alert-warning text-sm py-2">
                        <x-icon name="triangle-alert" size="sm" />
                        <span>{{ __('This product has no value on any variation characteristic yet. Give it one on the Attributes tab and save; a new variation is then this product with another value.') }}</span>
                    </div>
                @else
                    <p class="text-sm opacity-70">
                        {{ __('Tick the values the new products should have; this product\'s own are ticked. Several values on one row make one product each; several rows multiply — red × 40, 42, 44 is three products.') }}
                    </p>

                    @foreach ($askedFeatures as $feature)
                        @php
                            $own = $ownAnswers->get($feature->id);
                            $isCard = $feature->variation_role !== Feature::ROLE_CHOICE;
                        @endphp
                        <section class="flex flex-col gap-2" data-axis>
                            <div class="flex items-baseline gap-2 flex-wrap">
                                <span class="font-semibold">{{ $feature->t('name', $locale) }}</span>
                                <span class="text-xs opacity-60">
                                    @if ($isCard)
                                        {{ __('Every value is a card of its own in the catalogue: a new one wants photos of its own and shows this product\'s until it has them.') }}
                                    @else
                                        {{ __('Chosen on the card: a new value stays on this product\'s card and shows its photos.') }}
                                    @endif
                                </span>
                            </div>
                            @if ($feature->isChoiceType())
                                @php $ticked = array_map('intval', (array) old('values.'.$feature->id, $own?->feature_value_id ? [$own->feature_value_id] : [])); @endphp
                                <div class="flex flex-wrap gap-x-4 gap-y-2">
                                    @forelse ($feature->values as $value)
                                        <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer">
                                            <input type="checkbox" name="values[{{ $feature->id }}][]" value="{{ $value->id }}" class="checkbox checkbox-sm"
                                                   @checked(in_array($value->id, $ticked, true))>
                                            @if ($value->swatch)
                                                <span class="h-3 w-3 rounded-full border border-black/15" style="background-color: {{ $value->swatch }}"></span>
                                            @endif
                                            <span>{{ $value->display($locale) }}</span>
                                            @if ($own && (int) $own->feature_value_id === $value->id)
                                                <span class="text-xs opacity-50">({{ __('this product') }})</span>
                                            @endif
                                        </label>
                                    @empty
                                        <span class="text-xs opacity-60">{{ __('No values yet — add them on the characteristic first.') }}</span>
                                    @endforelse
                                </div>
                            @else
                                <input type="text" name="texts[{{ $feature->id }}]" value="{{ old('texts.'.$feature->id, $own?->value_text) }}"
                                       class="input input-sm w-full md:max-w-sm" aria-label="{{ $feature->t('name', $locale) }}"
                                       placeholder="{{ __('One value; this product has ":value"', ['value' => $own?->value_text ?? '—']) }}">
                            @endif
                        </section>
                    @endforeach

                    <div class="grid sm:grid-cols-3 gap-4 pt-2 border-t border-base-300">
                        <label class="fieldset p-0">
                            <span class="fieldset-legend">{{ __('Price') }} <span class="opacity-50 font-normal">{{ $product->pricedIn() }}</span></span>
                            <input type="number" step="{{ price_step() }}" min="0" name="price" value="{{ old('price', price_input($product->typedPrice())) }}" class="input input-sm w-full">
                        </label>
                        <label class="fieldset p-0">
                            <span class="fieldset-legend">{{ __('Stock') }}</span>
                            <input type="number" min="0" name="stock" value="{{ old('stock', 0) }}" class="input input-sm w-full">
                        </label>
                        <label class="flex items-start gap-2.5 cursor-pointer sm:pt-6">
                            <input type="checkbox" name="activate" value="1" class="checkbox checkbox-sm mt-0.5" @checked(old('activate'))>
                            <span class="text-sm leading-snug">{{ __('Switch on right away') }}
                                <span class="block text-xs opacity-60">{{ __('Otherwise made disabled, to be switched on from the table once it is ready.') }}</span>
                            </span>
                        </label>
                    </div>

                    <p class="text-xs opacity-60">
                        {{ __('The new product is named after this one with the value swapped in. It copies the group, categories, labels, prices, weight and the other characteristics; the photos, description and tabs are not copied but shown from this product until the new one gets its own.') }}
                        @if ($makesCards)
                            {{ __('A new card made in several sizes needs its photos on one of them only: the others of that card find them.') }}
                        @endif
                    </p>
                @endif
            </div>

            <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-base-300">
                <span class="text-sm opacity-60" x-show="n > 1" x-cloak>{!! __('Up to :n products', ['n' => '<span x-text="n"></span>']) !!}</span>
                <span class="grow"></span>
                <button type="button" class="btn btn-ghost btn-sm" x-on:click="$el.closest('dialog').close()">{{ __('Cancel') }}</button>
                @unless ($askedFeatures->isEmpty())
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="n === 0">{{ __('Add') }}</button>
                @endunless
            </div>
        </form>
    </div>
    <form method="dialog" class="modal-backdrop"><button type="submit" aria-label="{{ __('Close') }}"></button></form>
</dialog>
