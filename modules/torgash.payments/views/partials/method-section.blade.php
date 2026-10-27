{{-- Rendered into checkout's payment-method form through
     checkout.payment_method.extra. Every gateway's fields are here at once and
     the picker shows one; inputs are namespaced per gateway so the hidden ones
     are ignored on save rather than clashing. --}}
<div class="card bg-base-100 border border-base-300"
     x-data="{ gateway: '{{ old('gateway', $binding?->gateway ?? '') }}' }">
    <div class="card-body gap-4">
        <h3 class="font-semibold">{{ __('Taking the money') }}</h3>

        <fieldset class="fieldset p-0 max-w-sm">
            <legend class="fieldset-legend">{{ __('Gateway') }}</legend>
            <select aria-label="{{ __('Gateway') }}" name="gateway" x-model="gateway" class="select select-bordered w-full">
                <option value="">{{ __('Settled by hand') }}</option>
                @foreach ($options as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <p class="text-xs opacity-60 mt-1">
                {{ __('No gateway means somebody marks the order paid from the order screen — the right answer for cash on delivery and bank transfers.') }}
            </p>
        </fieldset>

        @foreach ($fields as $key => $gatewayFields)
            <div x-show="gateway === '{{ $key }}'" x-cloak class="flex flex-col gap-4">
                {{-- What this gateway cannot do for this shop, said here rather
                     than discovered by the first customer who cannot pay: what
                     it charges in against what the shop prices in, plus the
                     gateway's own traps (monobank holds money only in
                     hryvnia). Re-read on every save, so fixing the setting
                     makes it go away — and each one is a key with its
                     parameters rather than a sentence, because the same list is
                     stored by the nightly invariant run for a reader who may be
                     in another language (Torgash\Payments\Readiness). --}}
                @foreach ($warnings[$key] ?? [] as $warning)
                    <div class="alert alert-warning text-sm">{{ __($warning[0], $warning[1]) }}</div>
                @endforeach

                @foreach ($gatewayFields as $name => $field)
                    @php $stored = $binding?->gateway === $key ? data_get($binding->config, $name) : null; @endphp
                    <fieldset class="fieldset p-0 max-w-xl">
                        <legend class="fieldset-legend">{{ __($field['label'] ?? $name) }}</legend>

                        @if (($field['type'] ?? 'text') === 'checkbox')
                            <label class="label cursor-pointer justify-start gap-2">
                                <input type="checkbox" name="gateway_config[{{ $key }}][{{ $name }}]" value="1"
                                       class="toggle toggle-primary" @checked($stored)>
                                <span class="label-text">{{ __('On') }}</span>
                            </label>
                        @elseif (($field['type'] ?? 'text') === 'password')
                            {{-- Never shown back. The placeholder is there
                                 because an empty box otherwise reads as "it did
                                 not save". --}}
                            <input type="password" name="gateway_config[{{ $key }}][{{ $name }}]" autocomplete="new-password"
                                   placeholder="{{ $stored ? __('saved — leave blank to keep it') : '' }}"
                                   class="input input-bordered w-full font-mono">
                            @if ($stored)
                                {{-- Says it is there without showing any of it:
                                     a "helpful" masked hint is still the first
                                     characters of a live credential on screen. --}}
                                <p class="text-xs text-success mt-1">{{ __('Saved and in use.') }}</p>
                            @endif
                        @else
                            <input type="{{ $field['type'] ?? 'text' }}" name="gateway_config[{{ $key }}][{{ $name }}]"
                                   value="{{ $stored }}" class="input input-bordered w-full">
                        @endif

                        @if (! empty($field['help']))
                            <p class="text-xs opacity-60 mt-1">{{ __($field['help']) }}</p>
                        @endif
                    </fieldset>
                @endforeach

                <label class="label cursor-pointer justify-start gap-2">
                    <input type="checkbox" name="gateway_test_mode" value="1" class="toggle toggle-warning"
                           @checked($binding?->gateway === $key && $binding->test_mode)>
                    <span class="label-text">{{ __('Test mode') }}</span>
                </label>

                {{-- Printed so it can be pasted into the gateway's dashboard,
                     which is where every "it never called us back" starts. --}}
                <div class="rounded bg-base-200 p-3 text-sm">
                    <div class="opacity-60">{{ __('Give this address to the gateway:') }}</div>
                    <code class="font-mono text-xs break-all">{{ url('/payment/'.$key.'/callback') }}</code>
                    <div class="mt-2">
                        <a href="{{ route('admin.payments.log') }}" class="link link-primary text-xs">
                            {{ __('Payment log') }} &rarr;
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
