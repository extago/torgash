@extends('admin.layout')

@section('title', $coupon->exists ? __('Edit coupon') : __('New coupon'))
@section('own_heading', '1')

@section('content')
    <form method="POST" class="flex flex-col gap-4"
          action="{{ $coupon->exists ? route('admin.checkout.coupons.update', $coupon) : route('admin.checkout.coupons.store') }}"
          x-data="{
              code: {{ \Illuminate\Support\Js::from(old('code', $coupon->code)) }},
              type: {{ \Illuminate\Support\Js::from(old('type', $coupon->type ?? 'percent')) }},
              // A code nobody misreads on a printed card: no 0/O, no 1/I/L —
              // the same alphabet CouponsController::generateCode() uses.
              generate() {
                  const alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
                  const bytes = crypto.getRandomValues(new Uint8Array(8));
                  this.code = Array.from(bytes, (b) => alphabet[b % alphabet.length]).join('');
              },
          }">
        @csrf
        @if ($coupon->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.checkout.coupons.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to coupons') }}" aria-label="{{ __('Back to coupons') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $coupon->exists ? __('Edit coupon') : __('New coupon') }}</div>
                <div class="font-semibold truncate leading-tight font-mono" x-text="code || @js(__('New coupon'))">{{ $coupon->code ?: __('New coupon') }}</div>
            </div>
            @if ($coupon->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        <li><a href="{{ route('admin.orders.index', ['coupon' => $coupon->code]) }}"><x-icon name="list" /> {{ __('Orders with this coupon') }}</a></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="coupon-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.checkout.coupons.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $coupon->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

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
                        <h3 class="font-semibold">{{ __('Code and discount') }}</h3>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }} *</legend>
                            <div class="join w-full max-w-md">
                                <input name="code" autocapitalize="off" autocorrect="off" spellcheck="false" x-model="code" required maxlength="64" pattern="[A-Za-z0-9_\-]+"
                                       class="input input-bordered join-item w-full font-mono uppercase" placeholder="SUMMER10">
                                <button type="button" class="btn join-item gap-1.5" @click="generate()" title="{{ __('Make up a code nobody can guess') }}">
                                    <x-icon name="dices" size="sm" /> {{ __('Generate') }}
                                </button>
                            </div>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('What the customer types in the basket. Latin letters, digits, dashes; the case does not matter.') }}</p>
                        </fieldset>

                        <div class="grid md:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Discount') }} *</legend>
                                <select name="type" x-model="type" class="select select-bordered w-full">
                                    <option value="percent">{{ __('Percent off the order') }}</option>
                                    <option value="fixed">{{ __('Amount off the order') }}</option>
                                </select>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Size') }} *</legend>
                                <label class="input input-bordered w-full">
                                    <input name="value" type="number" step="0.01" min="0" :max="type === 'percent' ? 100 : null" required class="grow"
                                           value="{{ old('value', $coupon->exists ? ($coupon->type === 'percent' ? $coupon->value : $coupon->value / 100) : '') }}">
                                    <span class="opacity-50" x-text="type === 'percent' ? '%' : @js(shop_currency())"></span>
                                </label>
                            </fieldset>
                        </div>
                        <p class="text-xs opacity-60 -mt-2">{{ __('Taken off the goods in the basket; never more than they cost. Delivery is not discounted.') }}</p>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Conditions') }}</h3>

                        <div class="grid md:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Minimum order') }}</legend>
                                <label class="input input-bordered w-full">
                                    <input name="min_subtotal" type="number" step="0.01" min="0" class="grow"
                                           value="{{ old('min_subtotal', $coupon->min_subtotal !== null ? $coupon->min_subtotal / 100 : '') }}"
                                           placeholder="{{ __('no minimum') }}">
                                    <span class="opacity-50">{{ shop_currency() }}</span>
                                </label>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('How many times it can be used') }}</legend>
                                <input name="usage_limit" type="number" min="1" value="{{ old('usage_limit', $coupon->usage_limit) }}"
                                       class="input input-bordered w-full" placeholder="{{ __('Unlimited') }}">
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('In total, by every customer together.') }}</p>
                            </fieldset>
                        </div>

                        <div class="grid md:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Works from') }}</legend>
                                <input name="starts_at" type="date" value="{{ old('starts_at', $coupon->starts_at?->format('Y-m-d')) }}" class="input input-bordered w-full">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Works until') }}</legend>
                                <input name="expires_at" type="date" value="{{ old('expires_at', $coupon->expires_at?->format('Y-m-d')) }}" class="input input-bordered w-full">
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('Including that day. Empty means no limit.') }}</p>
                            </fieldset>
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $coupon->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        @if ($coupon->exists)
                            <div class="text-sm">
                                {{ __('Used') }}:
                                <a href="{{ route('admin.orders.index', ['coupon' => $coupon->code]) }}" class="link link-hover font-medium">
                                    {{ $coupon->used_count }}{{ $coupon->usage_limit ? ' / '.$coupon->usage_limit : '' }}
                                </a>
                            </div>
                        @endif
                        <p class="text-xs opacity-60">{{ __('A coupon already in somebody\'s basket stops discounting by itself once it is switched off, expires or runs out.') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($coupon->exists)
        {{-- Beside the form, never inside it. --}}
        <form method="POST" id="coupon-delete" action="{{ route('admin.checkout.coupons.destroy', $coupon) }}" class="hidden"
              data-confirm="{{ __('Delete this coupon? Orders that used it keep their discount.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
