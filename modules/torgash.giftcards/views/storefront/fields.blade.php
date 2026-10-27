{{-- A gift card's questions, inside the buy box's form (cart.buy_box.fields),
     so they ride the same POST as the quantity. Named distinctively: a Blade
     include inherits the caller's whole scope. --}}
@php
    $giftError = function (string $field) use ($giftErrors) {
        $said = $giftErrors['config.giftcard.'.$field] ?? null;

        return is_array($said) ? ($said[0] ?? null) : $said;
    };
    $giftAmount = isset($giftAnswers['amount']) ? rtrim(rtrim(number_format($giftAnswers['amount'] / 100, 2, '.', ''), '0'), '.') : '';
    $giftFor = ! empty($giftAnswers['to_email']) || ! empty($giftAnswers['to_name']);
@endphp
<div class="flex flex-col gap-3" x-data="{ gift: {{ \Illuminate\Support\Js::from($giftFor) }} }">
    @if ($giftRow->free_amount)
        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend">{{ __('Card amount') }} *</legend>
            <label class="input input-bordered w-full">
                <input type="text" inputmode="decimal" name="config[giftcard][amount]" value="{{ $giftAmount }}" required class="grow"
                       placeholder="{{ number_format($giftRow->minimum() / 100, 0, '.', ' ') }}">
                <span class="opacity-50">{{ shop_currency() }}</span>
            </label>
            <p class="label text-xs opacity-60 whitespace-normal">
                @if ($giftRow->maximum())
                    {{ __('From :min to :max.', ['min' => format_price($giftRow->minimum(), shop_currency()), 'max' => format_price($giftRow->maximum(), shop_currency())]) }}
                @else
                    {{ __('From :min.', ['min' => format_price($giftRow->minimum(), shop_currency())]) }}
                @endif
            </p>
            @if ($giftError('amount'))
                <p class="text-error text-sm">{{ $giftError('amount') }}</p>
            @endif
        </fieldset>
    @endif

    <label class="label cursor-pointer justify-start gap-3">
        <input type="checkbox" class="checkbox" x-model="gift">
        <span class="label-text">{{ __('It is a present — send it to somebody') }}</span>
    </label>

    {{-- Disabled while hidden, so an unticked box sends none of it. Without a
         script the fields simply stay open. --}}
    <div class="flex flex-col gap-3" x-show="gift" x-cloak>
        <div class="grid sm:grid-cols-2 gap-3">
            <label class="fieldset p-0">
                <span class="fieldset-legend">{{ __('Recipient\'s name') }}</span>
                <input type="text" name="config[giftcard][to_name]" maxlength="190" value="{{ $giftAnswers['to_name'] ?? '' }}"
                       class="input input-bordered w-full" :disabled="!gift">
            </label>
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Recipient\'s email') }}</legend>
                <input aria-label="{{ __('Recipient\'s email') }}" type="email" name="config[giftcard][to_email]" maxlength="190" value="{{ $giftAnswers['to_email'] ?? '' }}"
                       class="input input-bordered w-full" :disabled="!gift">
                @if ($giftError('to_email'))
                    <p class="text-error text-sm">{{ $giftError('to_email') }}</p>
                @endif
            </fieldset>
        </div>
        <label class="fieldset p-0">
            <span class="fieldset-legend">{{ __('From') }}</span>
            <input type="text" name="config[giftcard][from_name]" maxlength="190" value="{{ $giftAnswers['from_name'] ?? '' }}"
                   class="input input-bordered w-full" :disabled="!gift" placeholder="{{ auth()->user()?->name }}">
        </label>
        <label class="fieldset p-0">
            <span class="fieldset-legend">{{ __('Message') }}</span>
            <textarea name="config[giftcard][message]" rows="3" maxlength="500" class="textarea textarea-bordered w-full"
                      :disabled="!gift">{{ $giftAnswers['message'] ?? '' }}</textarea>
        </label>
        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend">{{ __('Send on') }}</legend>
            <input aria-label="{{ __('Send on') }}" type="date" name="config[giftcard][deliver_on]" value="{{ $giftAnswers['deliver_on'] ?? '' }}" class="input input-bordered w-full"
                   min="{{ today()->toDateString() }}" max="{{ today()->addYear()->toDateString() }}" :disabled="!gift">
            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Empty — as soon as the order is paid.') }}</p>
            @if ($giftError('deliver_on'))
                <p class="text-error text-sm">{{ $giftError('deliver_on') }}</p>
            @endif
        </fieldset>
    </div>
</div>
