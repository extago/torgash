@extends('admin.layout')

@section('title', $currency->exists ? $currency->code.' — '.$currency->name : __('New currency'))
@section('own_heading', '1')

@section('content')
    @php
        $isBooks = $currency->exists && isset($inUse[$currency->code]);
    @endphp

    <x-admin.form-bar>
        <a href="{{ route('admin.currencies.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to currencies') }}" aria-label="{{ __('Back to currencies') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Currency') }}</div>
            <div class="font-semibold truncate leading-tight">
                {{ $currency->exists ? $currency->code.' — '.$currency->name : __('New currency') }}
            </div>
        </div>
        <div class="dropdown dropdown-end">
            <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                <x-icon name="settings" size="lg" />
            </div>
            <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                <li><button type="submit" form="currency-form" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                @if ($currency->exists && ! $isBooks)
                    <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                    <li><button type="submit" form="currency-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete the currency') }}</button></li>
                @endif
            </ul>
        </div>
        <x-slot:actions>
            <a href="{{ route('admin.currencies.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button type="submit" form="currency-form" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
        </x-slot:actions>
    </x-admin.form-bar>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <form method="POST" id="currency-form"
          action="{{ $currency->exists ? route('admin.currencies.update', $currency) : route('admin.currencies.store') }}"
          class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
        @csrf
        @if ($currency->exists) @method('PUT') @endif

        <div class="flex flex-col gap-4 min-w-0">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Currency') }}</h3>
                    <div class="grid sm:grid-cols-[8rem_8rem_minmax(0,1fr)] gap-4">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }} *</legend>
                            <input name="code" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('code', $currency->code) }}" required maxlength="3" class="input input-bordered w-full font-mono uppercase" placeholder="USD">
                            <p class="label">ISO 4217</p>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Symbol') }} *</legend>
                            <input name="symbol" value="{{ old('symbol', $currency->symbol) }}" required class="input input-bordered w-full" placeholder="$">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }} *</legend>
                            <input name="name" value="{{ old('name', $currency->name) }}" required class="input input-bordered w-full" placeholder="{{ __('US Dollar') }}">
                        </fieldset>
                    </div>
                </div>
            </section>

            {{-- How an amount is WRITTEN — per currency, because a shop selling in
                 dollars and hryvnia writes each the way its readers expect. The
                 example follows the choices, so nobody has to picture "1 299,00 ₴". --}}
            @php
                $fmtSample = $currency->exists ? $currency : new \App\Models\Currency(['symbol' => '$', 'decimals' => 2]);
                $fmtThousands = old('thousands_separator', match ($currency->thousands_separator ?? ' ') { ' ' => 'space', '' => 'none', default => $currency->thousands_separator });
            @endphp
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3"
                     x-data="{ pos: @js(old('symbol_position', $currency->symbol_position ?? 'after_space')), dec: @js(old('decimal_separator', $currency->decimal_separator ?? '.')), th: @js($fmtThousands), sym: @js((string) old('symbol', $fmtSample->symbol)), places: {{ (int) old('decimals', $fmtSample->decimals ?? 2) }} }">
                    <h3 class="font-semibold">{{ __('How an amount is written') }}</h3>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sign') }}</legend>
                            <select name="symbol_position" x-model="pos" class="select select-bordered w-full">
                                <option value="before">{{ __('Before the price: $1') }}</option>
                                <option value="before_space">{{ __('Before, with a space: $ 1') }}</option>
                                <option value="after_space">{{ __('After, with a space: 1 $') }}</option>
                                <option value="after">{{ __('After, close: 1$') }}</option>
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Decimal places') }} *</legend>
                            <input name="decimals" type="number" min="0" max="4" x-model.number="places" value="{{ old('decimals', $currency->decimals ?? 2) }}" required class="input input-bordered w-full">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Decimal separator') }}</legend>
                            <select name="decimal_separator" x-model="dec" class="select select-bordered w-full">
                                <option value=".">{{ __('Point') }} (1.50)</option>
                                <option value=",">{{ __('Comma') }} (1,50)</option>
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Thousands separator') }}</legend>
                            <select name="thousands_separator" x-model="th" class="select select-bordered w-full">
                                <option value="space">{{ __('Space') }} (1 299)</option>
                                <option value="none">{{ __('None') }} (1299)</option>
                                <option value=",">{{ __('Comma') }} (1,299)</option>
                                <option value=".">{{ __('Point') }} (1.299)</option>
                                <option value="'">{{ __('Apostrophe') }} (1'299)</option>
                            </select>
                        </fieldset>
                    </div>
                    <div class="text-sm rounded-box bg-base-200 px-3 py-2 w-fit">
                        {{ __('Written as') }}:
                        <span class="font-semibold font-mono" x-text="(() => {
                            const t = { space: ' ', none: '' }[th] ?? th;
                            const [i, f] = (1299.5).toFixed(places).split('.');
                            const n = i.replace(/\B(?=(\d{3})+(?!\d))/g, t) + (places > 0 ? dec + f : '');
                            return { before: sym + n, before_space: sym + ' ' + n, after: n + sym }[pos] ?? n + ' ' + sym;
                        })()">{{ $fmtSample->format(1299.5) }}</span>
                    </div>
                </div>
            </section>
        </div>

        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Exchange rate') }}</h3>

                    @if ($isBooks)
                        <div class="alert alert-info text-sm">
                            {{ __('A storefront keeps its books in this currency: its rate is 1, it is never fetched, and it cannot be switched off.') }}
                        </div>
                        <input type="hidden" name="rate" value="1">
                    @else
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Exchange rate') }} *</legend>
                            <input name="rate" type="number" step="any" min="0" value="{{ old('rate', format_rate($currency->rate ?? 1)) }}" required class="input input-bordered w-full">
                            {{-- Said here because the direction is the thing people get
                                 backwards, and getting it backwards multiplies every price
                                 on the storefront instead of dividing it. --}}
                            <p class="label whitespace-normal">{{ __('How much of THIS currency one unit of the shop\'s own currency buys — 41 means one unit of the shop\'s currency is worth 41 of these.') }}</p>
                        </fieldset>
                    @endif

                    <label @class(['label justify-start gap-2 p-0', 'cursor-pointer' => ! $isBooks, 'opacity-50' => $isBooks])>
                        <input type="checkbox" name="auto_rate" value="1" class="toggle toggle-success toggle-sm"
                               @checked(old('auto_rate', $currency->auto_rate ?? true)) @disabled($isBooks)>
                        <span class="label-text whitespace-normal">{{ __('Update this rate from the shop\'s rate source') }}</span>
                    </label>
                    <p class="text-xs opacity-60 -mt-1">
                        {{ __('Off keeps the number above exactly as typed — for a rate you hold fixed by agreement, or one your source does not carry.') }}
                        @unless ($source)
                            @can('settings.manage')<a href="{{ url('/admin/settings#setting-rates_source') }}" class="link">{{ __('No rate source is chosen yet.') }}</a>@else{{ __('No rate source is chosen yet.') }}@endcan
                        @endunless
                    </p>

                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Key in the feed') }}</legend>
                        <input name="rate_key" value="{{ old('rate_key', $currency->rate_key) }}"
                               class="input input-bordered w-full font-mono" placeholder="{{ $currency->code ?: 'USD' }}">
                        <p class="label whitespace-normal">{{ __('Only when the source calls this currency something other than its code — CoinGecko wants "bitcoin", not "BTC". Empty means the code.') }}</p>
                    </fieldset>

                    @if ($currency->rate_updated_at)
                        <div class="text-xs opacity-60">
                            {{ __('Last updated :when', ['when' => $currency->rate_updated_at->diffForHumans()]) }}
                            ({{ $currency->rate_updated_at->format('Y-m-d H:i') }})
                        </div>
                    @endif
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <label @class(['label justify-start gap-2 p-0', 'cursor-pointer' => ! $isBooks])>
                        <input type="checkbox" name="enabled" value="1" class="toggle toggle-success toggle-sm"
                               @checked(old('enabled', $currency->enabled ?? true)) @disabled($isBooks)>
                        <span class="label-text whitespace-normal">{{ __('Offered on the storefront') }}</span>
                    </label>
                    @if ($isBooks)
                        <input type="hidden" name="enabled" value="1">
                    @endif
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                        <input name="sort" type="number" value="{{ old('sort', $currency->sort ?? 0) }}" class="input input-bordered w-32">
                    </fieldset>
                </div>
            </section>
        </div>
    </form>

    @if ($currency->exists && ! $isBooks)
        <form id="currency-delete" method="POST" action="{{ route('admin.currencies.destroy', $currency) }}" class="hidden"
              data-confirm="{{ __('Delete :code? Products priced in it keep the price they last had in the shop\'s currency and stop following its rate.', ['code' => $currency->code]) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
