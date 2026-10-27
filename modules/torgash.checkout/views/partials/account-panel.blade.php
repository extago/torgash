{{--
    Under the checkout's email box (CheckoutAccountController), after the
    owner's mockup: the address has an account → send a code → type it → in.
    It stands INSIDE the checkout form, so every button is type="button"
    driven by HTMX and Enter in the code box presses Sign in rather than
    placing the order.
--}}
@if ($state !== 'none')
    <div class="rounded-box bg-warning/10 border border-warning/30 p-4 flex flex-col gap-3" id="checkout-account-box">
        <div class="flex items-start gap-3 text-sm">
            <x-icon name="lock" class="text-warning shrink-0" />
            <span>
                @if ($state === 'staff')
                    {{ __('This address belongs to the shop\'s staff. Sign in with the password, or use another email.') }}
                @else
                    {{ __('An account already uses this address. Sign in to order with it — the order will be in your history.') }}
                @endif
            </span>
        </div>

        @if ($error)
            <div class="text-sm text-error">{{ $error }}</div>
        @endif

        @if ($state === 'exists')
            <button type="button" class="btn btn-warning w-full"
                    hx-post="{{ route('checkout.account.send') }}" hx-include="[name=email]"
                    hx-target="#checkout-account-panel" hx-swap="innerHTML">
                {{ __('Send the code to the email') }}
            </button>
        @elseif ($state === 'code')
            <div class="text-sm">{{ __('The code was sent to') }} <b>{{ $email }}</b></div>
            <div class="flex gap-2">
                <input name="account_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus
                       class="input input-bordered grow text-center font-mono tracking-[0.4em] text-lg" placeholder="••••••"
                       x-data x-on:keydown="if ($event.key === 'Enter') { $event.preventDefault(); $el.closest('#checkout-account-box').querySelector('[data-account-verify]').click(); }">
                <button type="button" class="btn btn-primary" data-account-verify
                        hx-post="{{ route('checkout.account.verify') }}" hx-include="[name=email],[name=account_code]"
                        hx-target="#checkout-account-panel" hx-swap="innerHTML">
                    {{ __('Sign in') }}
                </button>
            </div>
            <div class="text-center text-sm" x-data="{ wait: {{ (int) $wait }} }"
                 x-init="if (wait > 0) { const t = setInterval(() => { if (--wait <= 0) clearInterval(t) }, 1000) }">
                <span x-show="wait > 0" class="opacity-60">{{ __('Send again in') }} <b x-text="Math.floor(wait / 60) + ':' + String(wait % 60).padStart(2, '0')"></b></span>
                <button type="button" x-show="wait <= 0" @if ($wait > 0) style="display:none" @endif class="link link-primary"
                        hx-post="{{ route('checkout.account.resend') }}" hx-include="[name=email]"
                        hx-target="#checkout-account-panel" hx-swap="innerHTML">{{ __('Send again') }}</button>
            </div>
        @endif

        <div class="flex flex-wrap justify-center gap-x-4 gap-y-1 text-sm">
            @if ($state === 'staff' && \Illuminate\Support\Facades\Route::has('account.login'))
                <a href="{{ route('account.login') }}" class="link link-primary">{{ __('Sign in with a password') }}</a>
            @endif
            <button type="button" class="link link-warning"
                    x-data x-on:click="const f = $el.closest('form').email; f.value = ''; f.focus(); document.getElementById('checkout-account-panel').innerHTML = '';">
                {{ __('Use another email') }}
            </button>
        </div>
    </div>
@endif
