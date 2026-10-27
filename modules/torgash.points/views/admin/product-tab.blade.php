{{-- The product form's "Bonus points" tab, inside the product form.
     `points_form` says the tab was on the page, so a save from elsewhere
     (an import, the API) never clears what is set here. --}}
<div class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4">
        <input type="hidden" name="points_form" value="1">

        <div class="grid md:grid-cols-2 gap-4 max-w-2xl">
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Earns, multiplier') }}</legend>
                <label class="input input-bordered w-full">
                    <span class="opacity-50">×</span>
                    <input name="points_multiplier" type="number" step="0.01" min="0" max="100" class="grow"
                           value="{{ $pointsMultiplier !== null ? rtrim(rtrim(number_format((float) $pointsMultiplier, 2, '.', ''), '0'), '.') : '' }}"
                           placeholder="{{ __('as its category') }}">
                </label>
                <p class="label text-xs opacity-60 whitespace-normal">{{ __('×2 earns double, ×0 earns nothing. Empty — as its category, else as the shop.') }}</p>
            </fieldset>

            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Price in points') }}</legend>
                <input name="points_price" type="number" min="0" aria-label="{{ __('Price in points') }}" class="input input-bordered w-full" value="{{ $pointsPrice ?: '' }}" placeholder="{{ __('none') }}">
                <p class="label text-xs opacity-60 whitespace-normal">
                    @if (setting('torgash.points', 'points_prices', false))
                        {{ __('Shown under the price as "or N points"; a customer can buy the line outright for them in the basket.') }}
                    @else
                        {{ __('Prices in points are off in the module\'s settings: this is kept and shown once they are on.') }}
                    @endif
                </p>
            </fieldset>
        </div>
    </div>
</div>
