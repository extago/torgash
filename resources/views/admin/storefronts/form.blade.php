@extends('admin.layout')

@section('title', $storefront->exists ? $storefront->name : __('New storefront'))
@section('own_heading', '1')

@section('content')
    @php
        $isDefault = $storefront->exists && $storefront->is_default;
        $storefrontUrl = $storefront->exists ? $storefront->url() : null;
        // A language or currency switched off shop-wide is still listed while
        // this storefront has it, so saving the form does not quietly drop it.
        $offeredLanguages = $languages->filter(fn ($l) => $l->enabled || in_array($l->code, $storefront->locales ?? [], true));
        $offeredCurrencies = $currencies->filter(fn ($c) => $c->enabled || in_array($c->code, $storefront->currencies ?? [], true));
        $defaultLocale = old('default_locale', $storefront->default_locale);
        $defaultCurrency = old('default_currency', $storefront->default_currency);
    @endphp

    <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
        <a href="{{ route('admin.storefronts.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to storefronts') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Storefront') }}</div>
            <div class="font-semibold truncate leading-tight">{{ $storefront->exists ? $storefront->name : __('New storefront') }}</div>
        </div>
        @if ($storefrontUrl)
            <a href="{{ $storefrontUrl }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm btn-square" title="{{ __('Open the storefront') }}">
                <x-icon name="external-link" size="lg" />
            </a>
        @endif
        <div class="dropdown dropdown-end">
            <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                <x-icon name="settings" size="lg" />
            </div>
            <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-64 mt-2 p-2 z-50 border border-base-300">
                <li><button type="submit" form="storefront-form" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                @if ($storefront->exists)
                    @can('themes.manage')
                        <li><a href="{{ route('admin.themes.customize', $storefront) }}"><x-icon name="sliders-horizontal" /> {{ __('Configure the theme') }}</a></li>
                    @endcan
                    @unless ($isDefault)
                        <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                        <li><button type="submit" form="storefront-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete the storefront') }}</button></li>
                    @endunless
                @endif
            </ul>
        </div>
        <a href="{{ route('admin.storefronts.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
        <button type="submit" form="storefront-form" class="btn btn-primary btn-sm">{{ $storefront->exists ? __('Save') : __('Create') }}</button>
    </div>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <form method="POST" id="storefront-form"
          action="{{ $storefront->exists ? route('admin.storefronts.update', $storefront) : route('admin.storefronts.store') }}"
          class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
        @csrf
        @if ($storefront->exists) @method('PUT') @endif

        <div class="flex flex-col gap-4 min-w-0">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Storefront') }}</h3>
                    <div class="grid sm:grid-cols-[minmax(0,1fr)_14rem] gap-4">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }} *</legend>
                            <input name="name" value="{{ old('name', $storefront->name) }}" required class="input input-bordered w-full" placeholder="{{ __('My Store') }}">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }} *</legend>
                            <input name="code" value="{{ old('code', $storefront->code) }}" required class="input input-bordered w-full font-mono" placeholder="main">
                            <p class="label whitespace-normal">{{ __('For exchanges and the API (X-Storefront).') }}</p>
                        </fieldset>
                    </div>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Domains') }} *</legend>
                        <input name="domains" value="{{ old('domains', implode(', ', $storefront->domains ?? [])) }}" required
                               class="input input-bordered w-full font-mono" placeholder="shop.com, www.shop.com">
                        <p class="label whitespace-normal">{{ __('The addresses this storefront answers on, separated by commas. The first one is its own address — links in letters and the preview lead there. A pasted https:// or trailing slash is removed.') }}</p>
                    </fieldset>
                </div>
            </section>

            {{-- What a search engine shows for the home page — this
                 storefront's own, in the content language picked at the top. --}}
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <div>
                        <h3 class="font-semibold">{{ __('Home page') }}</h3>
                        <p class="text-sm opacity-60 mt-0.5">{{ __('What a search engine shows for this storefront\'s home page. Empty title: the storefront\'s name.') }}</p>
                    </div>
                    <x-admin.translatable-field name="home_title" :label="__('Title')" :value="$storefront->home_title ?? []" :placeholder="$storefront->name" />
                    <x-admin.translatable-field name="home_description" :label="__('Description')" :value="$storefront->home_description ?? []" textarea :rows="3"
                                                :placeholder="__('Two sentences about the shop: what it sells and why here — up to about 160 characters.')" />
                </div>
            </section>

            {{-- The default is always one of the offered: ticking it off would
                 leave the storefront refusing its own language, so its box is
                 drawn ticked and locked while it is the default. --}}
            <section class="card bg-base-100 border border-base-300" x-data="{ def: @js($defaultLocale) }">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Languages') }}</h3>
                    <fieldset class="fieldset p-0 max-w-sm"><legend class="fieldset-legend">{{ __('Default language') }} *</legend>
                        <select name="default_locale" x-model="def" class="select select-bordered w-full">
                            @foreach ($offeredLanguages as $language)
                                <option value="{{ $language->code }}" @selected($defaultLocale === $language->code)>{{ $language->native_name }}</option>
                            @endforeach
                        </select>
                        <p class="label whitespace-normal">{{ __('What an address without a language prefix is shown in.') }}</p>
                    </fieldset>
                    <div>
                        <div class="text-sm opacity-70 mb-1">{{ __('Offered on this storefront') }}</div>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            @foreach ($offeredLanguages as $language)
                                <label class="label cursor-pointer gap-2">
                                    <input type="checkbox" name="locales[]" value="{{ $language->code }}" class="checkbox checkbox-sm"
                                           @checked(in_array($language->code, old('locales', $storefront->locales ?? []), true) || $defaultLocale === $language->code)
                                           x-effect="if (def === @js($language->code)) $el.checked = true"
                                           @click="if (def === @js($language->code)) $event.preventDefault()" :title="def === @js($language->code) ? @js(__('The default language is always offered')) : ''">
                                    <span class="label-text">{{ $language->native_name }} <span class="font-mono text-xs opacity-50 uppercase">{{ $language->code }}</span></span>
                                    @unless ($language->enabled)
                                        <span class="badge badge-sm badge-ghost">{{ __('switched off') }}</span>
                                    @endunless
                                </label>
                            @endforeach
                        </div>
                        <p class="text-xs opacity-60 mt-2">{{ __('Each extra language gets its own addresses (/ru/…).') }}
                            @can('languages.manage')<a href="{{ route('admin.languages.index') }}" class="link">{{ __('Languages of the shop') }}</a>@endcan</p>
                    </div>
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300" x-data="{ def: @js($defaultCurrency) }">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Currencies') }}</h3>
                    <fieldset class="fieldset p-0 max-w-sm"><legend class="fieldset-legend">{{ __('Shop currency') }} *</legend>
                        <select name="default_currency" x-model="def" class="select select-bordered w-full">
                            @foreach ($offeredCurrencies as $currency)
                                <option value="{{ $currency->code }}" @selected($defaultCurrency === $currency->code)>{{ $currency->code }} — {{ $currency->name }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                    {{-- The trap this warns about: a visitor switching currency
                         in the header changes what prices LOOK like; this select
                         changes what the numbers in the database MEAN. --}}
                    <div class="alert alert-warning text-sm py-2">
                        <x-icon name="triangle-alert" />
                        <span>{{ __('The currency the shop keeps its books in: every price, order and rate is stored in it. Changing it converts nothing — the same stored amount simply starts meaning a different sum. It has to be the currency whose rate is 1.') }}</span>
                    </div>
                    <div>
                        <div class="text-sm opacity-70 mb-1">{{ __('A customer can switch to') }}</div>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            @foreach ($offeredCurrencies as $currency)
                                <label class="label cursor-pointer gap-2">
                                    <input type="checkbox" name="currencies[]" value="{{ $currency->code }}" class="checkbox checkbox-sm"
                                           @checked(in_array($currency->code, old('currencies', $storefront->currencies ?? []), true) || $defaultCurrency === $currency->code)
                                           x-effect="if (def === @js($currency->code)) $el.checked = true"
                                           @click="if (def === @js($currency->code)) $event.preventDefault()" :title="def === @js($currency->code) ? @js(__('The shop currency is always offered')) : ''">
                                    <span class="label-text"><span class="font-mono">{{ $currency->code }}</span> <span class="opacity-60">{{ $currency->symbol }}</span></span>
                                    @unless ($currency->enabled)
                                        <span class="badge badge-sm badge-ghost">{{ __('switched off') }}</span>
                                    @endunless
                                </label>
                            @endforeach
                        </div>
                        @can('currencies.manage')<p class="text-xs opacity-60 mt-2"><a href="{{ route('admin.currencies.index') }}" class="link">{{ __('Currencies and exchange rates') }}</a></p>@endcan
                    </div>
                </div>
            </section>

            {{--
                Contact details belong to the storefront, not to whichever
                module happens to render them: an order email, an invoice or the
                footer must be able to find the shop's phone number without
                depending on a header widget being installed.
            --}}
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <div>
                        <h3 class="font-semibold">{{ __('Contact details') }}</h3>
                        <p class="text-sm opacity-60 mt-0.5">{{ __('Used by anything that needs to show how to reach this store — the header contacts block, emails, invoices.') }}</p>
                    </div>
                    <div class="grid md:grid-cols-2 gap-4">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Phone') }}</legend>
                            <input name="contacts[phone]" value="{{ old('contacts.phone', $storefront->contact('phone')) }}"
                                   class="input input-bordered w-full" placeholder="+380 44 000 00 00">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Email') }}</legend>
                            <input name="contacts[email]" type="email" value="{{ old('contacts.email', $storefront->contact('email')) }}"
                                   class="input input-bordered w-full" placeholder="sale@example.com">
                        </fieldset>
                    </div>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Opening hours') }}</legend>
                        <input name="contacts[hours]" value="{{ old('contacts.hours', $storefront->contact('hours')) }}"
                               class="input input-bordered w-full" placeholder="{{ __('Free text, e.g. Mon–Sun 10:00–20:00.') }}">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Legal address') }}</legend>
                        <input name="contacts[address]" value="{{ old('contacts.address', $storefront->contact('address')) }}"
                               class="input input-bordered w-full" placeholder="{{ __('The company\'s registered address — the footer\'s contacts show it.') }}">
                    </fieldset>
                    <div class="grid md:grid-cols-3 gap-4">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">Telegram</legend>
                            <input name="contacts[telegram]" value="{{ old('contacts.telegram', $storefront->contact('telegram')) }}"
                                   class="input input-bordered w-full" placeholder="https://t.me/…">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">WhatsApp</legend>
                            <input name="contacts[whatsapp]" value="{{ old('contacts.whatsapp', $storefront->contact('whatsapp')) }}"
                                   class="input input-bordered w-full" placeholder="https://wa.me/…">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">Viber</legend>
                            <input name="contacts[viber]" value="{{ old('contacts.viber', $storefront->contact('viber')) }}"
                                   class="input input-bordered w-full" placeholder="viber://…">
                        </fieldset>
                    </div>
                    {{-- The lists (Storefront::CONTACT_LISTS): rows the admin
                         adds and removes; a row left without its number or
                         address is dropped on save. --}}
                    @php
                        $contactRows = fn (string $key) => old('contacts.'.$key) !== null
                            ? array_values((array) old('contacts.'.$key)) : $storefront->contactList($key);
                    @endphp
                    <fieldset class="fieldset p-0" x-data="{ rows: @js($contactRows('phones')) }">
                        <legend class="fieldset-legend">{{ __('More phone numbers') }}</legend>
                        <template x-for="(row, index) in rows" :key="index">
                            <div class="flex items-center gap-2">
                                <input class="input input-bordered w-full max-w-60" :name="`contacts[phones][${index}][number]`" x-model="row.number"
                                       placeholder="+380 67 000 00 00" aria-label="{{ __('Phone') }}">
                                <input class="input input-bordered w-full" :name="`contacts[phones][${index}][label]`" x-model="row.label"
                                       placeholder="{{ __('Caption, e.g. Service centre or Kyivstar') }}" aria-label="{{ __('Caption') }}">
                                <button type="button" class="btn btn-ghost btn-sm btn-square text-error" @click="rows.splice(index, 1)"
                                        title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}"><x-icon name="trash-2" /></button>
                            </div>
                        </template>
                        <button type="button" class="btn btn-ghost btn-sm justify-self-start gap-1.5" @click="rows.push({ number: '', label: '' })">
                            <x-icon name="plus" size="sm" /> {{ __('Add a number') }}
                        </button>
                        <p class="label whitespace-normal">{{ __('Shown under the main phone by {phones} — the header contacts panel has it.') }}</p>
                    </fieldset>
                    <fieldset class="fieldset p-0" x-data="{ rows: @js($contactRows('socials')) }">
                        <legend class="fieldset-legend">{{ __('Social networks') }}</legend>
                        <template x-for="(row, index) in rows" :key="index">
                            <div class="flex items-center gap-2">
                                <input class="input input-bordered w-full" :name="`contacts[socials][${index}][url]`" x-model="row.url"
                                       type="url" placeholder="https://instagram.com/…" aria-label="{{ __('Address') }}">
                                <input class="input input-bordered w-full max-w-44" :name="`contacts[socials][${index}][network]`" x-model="row.network"
                                       list="contact-networks" placeholder="{{ __('By the address') }}" aria-label="{{ __('Social network') }}">
                                <button type="button" class="btn btn-ghost btn-sm btn-square text-error" @click="rows.splice(index, 1)"
                                        title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}"><x-icon name="trash-2" /></button>
                            </div>
                        </template>
                        <datalist id="contact-networks">
                            @foreach (['telegram', 'instagram', 'facebook', 'youtube', 'tiktok', 'x', 'vk', 'whatsapp', 'viber', 'odnoklassniki', 'pinterest', 'linkedin', 'threads'] as $contactNetwork)
                                <option value="{{ $contactNetwork }}"></option>
                            @endforeach
                        </datalist>
                        <button type="button" class="btn btn-ghost btn-sm justify-self-start gap-1.5" @click="rows.push({ url: '', network: '' })">
                            <x-icon name="plus" size="sm" /> {{ __('Add a network') }}
                        </button>
                        <p class="label whitespace-normal">{{ __('Printed as a row of icons by {socials}. The network is worked out from the address when left empty.') }}</p>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Callback link') }}</legend>
                        <input name="contacts[callback_url]" value="{{ old('contacts.callback_url', $storefront->contact('callback_url')) }}"
                               class="input input-bordered w-full">
                        <p class="label whitespace-normal">{{ __('Where the callback button goes. Leave empty and it falls back to dialling the number.') }}</p>
                    </fieldset>
                </div>
            </section>
        </div>

        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Theme') }}</h3>
                    {{-- The themes on disk (themes/<dir>/theme.json), named as they
                         name themselves; a child theme draws only what it overrides
                         and takes the rest from its parent. --}}
                    @php $storefrontTheme = old('theme', $storefront->theme ?? 'default'); @endphp
                    <select name="theme" required class="select select-bordered w-full">
                        @foreach ($themes as $themeKey => $themeName)
                            <option value="{{ $themeKey }}" @selected($storefrontTheme === $themeKey)>{{ $themeName }}@if ($themeKey !== $themeName) ({{ $themeKey }})@endif</option>
                        @endforeach
                    </select>
                    @if ($storefront->exists)
                        @can('themes.manage')
                            <a href="{{ route('admin.themes.customize', $storefront) }}" class="btn btn-sm gap-2 w-fit">
                                <x-icon name="sliders-horizontal" /> {{ __('Configure the theme') }}
                            </a>
                            <p class="text-xs opacity-60">{{ __('Colours, fonts and the header are set per storefront in the theme configurator. Save first if you have just changed the theme.') }}</p>
                        @endcan
                    @endif
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Status') }}</h3>
                    {{-- A disabled toggle reads as "off", so the default one's two
                         locked answers are written out rather than drawn greyed. --}}
                    @if ($isDefault)
                        <div class="flex flex-wrap gap-2">
                            <span class="badge badge-success gap-1"><x-icon name="check" size="sm" /> {{ __('Open to visitors') }}</span>
                            <span class="badge badge-primary gap-1"><x-icon name="star" size="sm" /> {{ __('Default storefront') }}</span>
                        </div>
                    @else
                        <label class="label justify-start gap-2 p-0 cursor-pointer">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-primary toggle-sm"
                                   @checked(old('enabled', $storefront->enabled ?? true))>
                            <span class="label-text whitespace-normal">{{ __('Open to visitors') }}</span>
                        </label>
                        <label class="label justify-start gap-2 p-0 cursor-pointer">
                            <input type="checkbox" name="is_default" value="1" class="toggle toggle-primary toggle-sm"
                                   @checked(old('is_default', $storefront->is_default ?? false))>
                            <span class="label-text whitespace-normal">{{ __('Default storefront') }}</span>
                        </label>
                    @endif
                    <p class="text-xs opacity-60">
                        @if ($isDefault)
                            {{ __('This is the default: it serves every address no other storefront claims, so it can be neither switched off nor deleted. To change that, make another storefront the default.') }}
                        @else
                            {{ __('The default serves every address no other storefront claims. Making this one the default takes it from the current one.') }}
                        @endif
                    </p>
                </div>
            </section>

            {{-- Closed is not switched off: the addresses still answer, with
                 a notice, and staff still see the whole shop
                 (App\Core\Storefronts\ClosedStorefront). --}}
            <section class="card bg-base-100 border border-base-300" x-data="{ closed: @js((bool) old('closed', $storefront->closed ?? false)) }">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Closed for visitors') }}</h3>
                    <label class="label justify-start gap-2 p-0 cursor-pointer">
                        <input type="checkbox" name="closed" value="1" class="toggle toggle-primary toggle-sm" x-model="closed">
                        <span class="label-text whitespace-normal">{{ __('Show visitors a notice instead of the shop') }}</span>
                    </label>
                    <p class="text-xs opacity-60">{{ __('For a shop being built or repaired. Staff signed in to the admin see the storefront as usual; everyone else gets the notice with the shop\'s phone and email. Search engines are told to come back later, so a live shop keeps its place in the index.') }}</p>
                    <div x-show="closed" x-cloak class="flex flex-col gap-3">
                        <x-admin.translatable-field name="closed_message" :label="__('Notice text')" :value="$storefront->closed_message ?? []" textarea :rows="3"
                                                    :placeholder="__('The shop is being prepared. Please come back a little later.')" />
                        <label class="label justify-start gap-2 p-0 cursor-pointer">
                            <input type="checkbox" name="closed_contacts" value="1" class="checkbox checkbox-sm"
                                   @checked(old('closed_contacts', $storefront->closed_contacts ?? false))>
                            <span class="label-text whitespace-normal">{{ __('Show the shop\'s contacts on the notice') }}</span>
                        </label>
                        @if ($storefront->previewUrl())
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Preview link') }}</legend>
                                <input type="text" readonly value="{{ $storefront->previewUrl() }}" class="input input-bordered input-sm w-full font-mono text-xs" aria-label="{{ __('Preview link') }}">
                                <p class="label whitespace-normal">{{ __('Send it to someone without an account in the admin — a client, a tester — to show them the closed shop. Valid until you make a new one.') }}</p>
                            </fieldset>
                            <button type="submit" form="storefront-preview-link" class="btn btn-sm w-fit gap-2"><x-icon name="refresh-cw" size="sm" /> {{ __('Make a new link') }}</button>
                        @else
                            <p class="text-xs opacity-60">{{ __('Save to get a preview link for someone without an account.') }}</p>
                        @endif
                    </div>
                </div>
            </section>
        </div>
    </form>

    @if ($storefront->exists && $storefront->closed_token)
        <form id="storefront-preview-link" method="POST" action="{{ route('admin.storefronts.preview_link', $storefront) }}" class="hidden"
              data-confirm="{{ __('Make a new preview link? Whoever has the old one will see the notice.') }}">
            @csrf
        </form>
    @endif

    @if ($storefront->exists && ! $isDefault)
        <form id="storefront-delete" method="POST" action="{{ route('admin.storefronts.destroy', $storefront) }}" class="hidden"
              data-confirm="{{ __('Delete the storefront :name? Its orders stay; its own settings go with it.', ['name' => $storefront->name]) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
