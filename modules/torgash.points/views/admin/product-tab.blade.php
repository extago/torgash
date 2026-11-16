{{-- The product form's "Bonus points" tab, inside the product form.
     `points_form` says the tab was on the page, so a save from elsewhere
     (an import, the API) never clears what is set here.

     Each of the two questions — what it earns, whether points pay for it —
     is "as the rules say" until the product answers it itself (PLAN §74);
     what the answer comes to today, and whose it is, is printed above. --}}
@php
    $pointsEarnMode = in_array($pointsOwn->earn_mode ?? null, \Torgash\Points\Rules::EARN_MODES, true) ? $pointsOwn->earn_mode : '';
    $pointsSpendMode = in_array($pointsOwn->spend_mode ?? null, \Torgash\Points\Rules::SPEND_MODES, true) ? $pointsOwn->spend_mode : '';
    $pointsNumber = fn ($value) => $value === null ? '' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $pointsWhose = function (array $answer): string {
        return match ($answer['source']) {
            'product' => __('set on this product'),
            'rule' => __('rule ":name"', ['name' => $answer['rule']['name'] ?: '#'.$answer['rule']['id']]),
            'outside' => __('this kind of goods is outside the programme'),
            default => __('the shop\'s settings'),
        };
    };
@endphp
<div class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4">
        <input type="hidden" name="points_form" value="1">

        @if ($pointsNow)
            <div class="text-sm flex flex-col gap-1 max-w-2xl">
                <p class="flex flex-wrap items-center gap-x-1.5">
                    <x-icon name="coins" size="sm" class="opacity-60" />
                    @if ($pointsNow['earn'] > 0)
                        {{ trans_choice('Buying it earns :points point now|Buying it earns :points points now', $pointsNow['earn'], ['points' => number_format($pointsNow['earn'], 0, '.', ' ')]) }}
                    @else
                        {{ __('Buying it earns no points now') }}
                    @endif
                    <span class="opacity-60">—
                        @if ($pointsNow['earn_by']['source'] === 'rule')
                            <a href="{{ route('admin.points.rules.edit', $pointsNow['earn_by']['rule']['id']) }}" class="link">{{ $pointsWhose($pointsNow['earn_by']) }}</a>
                        @else
                            {{ $pointsWhose($pointsNow['earn_by']) }}
                        @endif
                    </span>
                </p>
                <p class="flex flex-wrap items-center gap-x-1.5">
                    <x-icon name="wallet" size="sm" class="opacity-60" />
                    @if ($pointsNow['spend']['percent'] > 0)
                        {{ __('Points may pay up to :percent% of it', ['percent' => $pointsNow['spend']['percent']]) }}
                    @else
                        {{ __('It cannot be paid with points') }}
                    @endif
                    <span class="opacity-60">—
                        @if ($pointsNow['spend']['source'] === 'rule')
                            <a href="{{ route('admin.points.rules.edit', $pointsNow['spend']['rule']['id']) }}" class="link">{{ $pointsWhose($pointsNow['spend']) }}</a>
                        @else
                            {{ $pointsWhose($pointsNow['spend']) }}
                        @endif
                    </span>
                </p>
                <p class="text-xs opacity-60">{{ __('At its list price, for a customer of no group; counted when the page was opened.') }}</p>
            </div>
        @endif

        <div class="grid md:grid-cols-2 gap-4 max-w-2xl" x-data="{ earn: @js($pointsEarnMode), spend: @js($pointsSpendMode) }">
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Earns') }}</legend>
                <select name="points_earn_mode" x-model="earn" class="select select-bordered w-full" aria-label="{{ __('Earns') }}">
                    <option value="">{{ __('As the rules say') }}</option>
                    <option value="multiplier">{{ __('The shop\'s rate, multiplied') }}</option>
                    <option value="percent">{{ __('A percent of the price, in points') }}</option>
                    <option value="fixed">{{ __('A fixed number of points per piece') }}</option>
                    <option value="none">{{ __('Nothing') }}</option>
                </select>
                <label class="input input-bordered w-full mt-2" x-show="earn !== '' && earn !== 'none'" x-cloak>
                    <span class="opacity-50" x-text="earn === 'multiplier' ? '×' : (earn === 'percent' ? '%' : '+')"></span>
                    <input name="points_earn_value" type="number" step="0.01" min="0" class="grow" aria-label="{{ __('Value') }}"
                           :disabled="earn === '' || earn === 'none'"
                           value="{{ $pointsEarnMode !== '' ? $pointsNumber($pointsOwn->earn_value) : '' }}">
                </label>
                <p class="label text-xs opacity-60 whitespace-normal">{{ __('×2 earns double the shop\'s rate; 5% of a price of 1 000 is 50 points. The product\'s own answer wins over every rule.') }}</p>
            </fieldset>

            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Paying with points') }}</legend>
                <select name="points_spend_mode" x-model="spend" class="select select-bordered w-full" aria-label="{{ __('Paying with points') }}">
                    <option value="">{{ __('As the rules say') }}</option>
                    <option value="allowed">{{ __('Allowed, within the shop\'s share') }}</option>
                    <option value="limit">{{ __('Allowed, up to its own share') }}</option>
                    <option value="forbidden">{{ __('Not allowed') }}</option>
                </select>
                <label class="input input-bordered w-full mt-2" x-show="spend === 'limit'" x-cloak>
                    <input name="points_spend_percent" type="number" step="1" min="0" max="100" class="grow" aria-label="{{ __('Share, %') }}"
                           :disabled="spend !== 'limit'"
                           value="{{ $pointsSpendMode === 'limit' ? (int) $pointsOwn->spend_percent : '' }}">
                    <span class="opacity-50">%</span>
                </label>
                <p class="label text-xs opacity-60 whitespace-normal">{{ __('How much of this product\'s price points may take off in a basket.') }}</p>
            </fieldset>

            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Price in points') }}</legend>
                <input name="points_price" type="number" min="0" aria-label="{{ __('Price in points') }}" class="input input-bordered w-full" value="{{ ($pointsOwn->points_price ?? null) ?: '' }}" placeholder="{{ __('none') }}">
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
