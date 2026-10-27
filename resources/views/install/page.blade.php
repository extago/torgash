{{-- The web installer (InstallController, PLAN §54). A page of its own: no
     theme (its settings live in the database that does not exist yet), no
     session, no cookies. The language rides in every form as `lang`. --}}
@php
    $installLang = app()->getLocale();
    $installUrl = fn (string $lang) => route('install.show', ['lang' => $lang]);
@endphp
<!DOCTYPE html>
<html lang="{{ $installLang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Install Torgash') }}</title>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/vendor/htmx.min.js') }}" defer></script>
</head>
<body class="min-h-screen bg-base-200 text-base-content">
    <div class="max-w-2xl mx-auto px-4 py-10 flex flex-col gap-6">
        <header class="flex items-center gap-3">
            <div class="size-11 rounded-box bg-primary text-primary-content flex items-center justify-center"><x-icon name="store" size="lg" /></div>
            <div class="flex-1">
                <h1 class="text-2xl font-bold leading-tight">{{ __('Install Torgash') }}</h1>
                <p class="text-sm opacity-60">{{ __('Version :version', ['version' => config('torgash.version')]) }}</p>
            </div>
            <nav class="flex gap-1 text-sm">
                @foreach (\App\Core\Install\Installer::LOCALES as $lang)
                    <a href="{{ $installUrl($lang) }}" @class(['btn btn-sm', 'btn-ghost' => $lang !== $installLang, 'btn-active' => $lang === $installLang])>{{ strtoupper($lang) }}</a>
                @endforeach
            </nav>
        </header>

        <main id="install-main" class="flex flex-col gap-6">
            @if ($pending)
                {{-- An install that stopped half way: every step may run
                     again, so it is finished from the first one. --}}
                <section class="card bg-base-100 border border-base-300">
                    <form class="card-body gap-4" hx-post="{{ route('install.step') }}" hx-target="#install-main">
                        <h2 class="font-semibold text-lg">{{ __('The install has not finished') }}</h2>
                        <p class="text-sm opacity-70">{{ __('It stopped half way. Type the install code and it goes on from where it stopped — what is already done is not done twice.') }}</p>
                        <input type="hidden" name="lang" value="{{ $installLang }}">
                        <input type="hidden" name="step" value="0">
                        <fieldset class="fieldset p-0 max-w-xs"><legend class="fieldset-legend">{{ __('Install code') }}</legend>
                            <input name="key" required class="input input-bordered w-full font-mono uppercase" autocomplete="off">
                            <p class="label whitespace-normal">{{ __('In the file :path on the server.', ['path' => $keyPath]) }}</p>
                        </fieldset>
                        <div id="install-problem"></div>
                        <button class="btn btn-primary w-fit">{{ __('Go on') }}</button>
                    </form>
                </section>
            @else
                <form id="install-form" class="flex flex-col gap-6" hx-post="{{ route('install.start') }}" hx-target="#install-main">
                    <input type="hidden" name="lang" value="{{ $installLang }}">

                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h2 class="font-semibold text-lg flex items-center gap-2"><x-icon name="key-round" /> {{ __('Install code') }}</h2>
                            <p class="text-sm opacity-70">{{ __('So that only the owner of this server can install the shop, open the file :path — over SSH, in the hosting\'s file manager or by FTP — and type the code from it.', ['path' => $keyPath]) }}</p>
                            <input name="key" required class="input input-bordered w-full max-w-xs font-mono uppercase" autocomplete="off" aria-label="{{ __('Install code') }}">
                        </div>
                    </section>

                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h2 class="font-semibold text-lg flex items-center gap-2"><x-icon name="database" /> {{ __('Database') }}</h2>
                            <p class="text-sm opacity-70">{{ __('PostgreSQL. The database is created by the installer when the user may do it; otherwise create it first (INSTALL.md says how).') }}</p>
                            <div class="grid sm:grid-cols-[minmax(0,1fr)_8rem] gap-3">
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Server') }}</legend>
                                    <input name="db_host" value="127.0.0.1" class="input input-bordered w-full font-mono">
                                </fieldset>
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Port') }}</legend>
                                    <input name="db_port" value="5432" inputmode="numeric" class="input input-bordered w-full font-mono">
                                </fieldset>
                            </div>
                            <div class="grid sm:grid-cols-3 gap-3">
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Database name') }}</legend>
                                    <input name="db_database" value="torgash" class="input input-bordered w-full font-mono">
                                </fieldset>
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('User') }}</legend>
                                    <input name="db_username" class="input input-bordered w-full font-mono" autocomplete="off">
                                </fieldset>
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Password') }}</legend>
                                    <input name="db_password" type="password" class="input input-bordered w-full" autocomplete="new-password">
                                </fieldset>
                            </div>
                            <button type="button" class="btn btn-sm w-fit gap-2" hx-post="{{ route('install.check') }}" hx-include="#install-form" hx-target="#install-checks">
                                <x-icon name="stethoscope" size="sm" /> {{ __('Check the server and the database') }}
                            </button>
                            <div id="install-checks">@include('install.checks', ['rows' => $server])</div>
                        </div>
                    </section>

                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h2 class="font-semibold text-lg flex items-center gap-2"><x-icon name="store" /> {{ __('The shop') }}</h2>
                            <div class="grid sm:grid-cols-2 gap-3">
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }}</legend>
                                    <input name="name" required class="input input-bordered w-full" placeholder="{{ __('My shop') }}">
                                </fieldset>
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Address') }}</legend>
                                    <input name="url" value="{{ $url }}" required class="input input-bordered w-full font-mono">
                                </fieldset>
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Storefront language') }}</legend>
                                    <select name="locale" class="select select-bordered w-full">
                                        <option value="ru" @selected($installLang === 'ru')>Русский</option>
                                        <option value="en" @selected($installLang === 'en')>English</option>
                                    </select>
                                </fieldset>
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Currency') }}</legend>
                                    <input name="currency" value="UAH" list="install-currencies" maxlength="3" class="input input-bordered w-full font-mono uppercase">
                                    <datalist id="install-currencies">
                                        @foreach (['UAH', 'USD', 'EUR', 'RUB', 'KZT', 'PLN'] as $code)<option value="{{ $code }}"></option>@endforeach
                                    </datalist>
                                    <p class="label whitespace-normal">{{ __('The one the shop keeps its prices and books in. Others for customers are added later.') }}</p>
                                </fieldset>
                                <fieldset class="fieldset p-0 sm:col-span-2"><legend class="fieldset-legend">{{ __('Country') }}</legend>
                                    <select name="country" class="select select-bordered w-full sm:max-w-xs">
                                        <option value="">{{ __('Another / not now') }}</option>
                                        @foreach (['UA' => 'Україна', 'KZ' => 'Қазақстан', 'RU' => 'Россия', 'BY' => 'Беларусь', 'PL' => 'Polska', 'DE' => 'Deutschland', 'US' => 'United States'] as $code => $countryName)
                                            <option value="{{ $code }}">{{ $countryName }}</option>
                                        @endforeach
                                    </select>
                                    <p class="label whitespace-normal">{{ __('Its delivery services and payment gateways come with the chosen modules; other countries\' stay uninstalled. It is also how a phone number typed without its code is read.') }}</p>
                                </fieldset>
                            </div>
                            <div class="flex flex-col gap-2">
                                <div class="text-sm font-medium">{{ __('Modules') }}</div>
                                @foreach ($bundles as $bundle => $count)
                                    <label class="flex items-start gap-3 border border-base-300 rounded-box p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                        <input type="radio" name="modules" value="{{ $bundle }}" class="radio mt-0.5" @checked($bundle === 'store')>
                                        <span class="flex flex-col">
                                            <span class="font-medium">{{ __(match ($bundle) { 'minimal' => 'The minimum', 'store' => 'A shop', 'all' => 'Every module', default => $bundle }) }} <span class="opacity-50 font-normal">· {{ trans_choice(':count module|:count modules', $count) }}</span></span>
                                            <span class="text-sm opacity-70">{{ __(match ($bundle) {
                                                'minimal' => 'Catalogue, cart, checkout, payment and delivery, pages, menus and blocks.',
                                                'store' => 'The minimum plus search, reviews, wishlist, comparison, sales, banners, blog, enquiries, warehouses, options and kits.',
                                                'all' => 'Every module shipped with the engine: payment gateways, Nova Poshta, 1C exchange, the API, returns, gift cards and more.',
                                                default => '',
                                            }) }}</span>
                                        </span>
                                    </label>
                                @endforeach
                                <p class="text-xs opacity-60">{{ __('Any module can be installed or removed later in the admin.') }}</p>
                                <label class="flex items-start gap-3 border border-base-300 rounded-box p-3 cursor-pointer mt-2 has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                    <input type="checkbox" name="demo" value="1" class="checkbox mt-0.5">
                                    <span class="flex flex-col">
                                        <span class="font-medium">{{ __('Fill it with the demo data') }}</span>
                                        <span class="text-sm opacity-70">{{ __('About sixty products with prices, sales, a coupon, reviews, pages, a blog and the home page's slider — to see the shop working before your own catalogue is in. System → Remove the demo data takes it all out in one go; what you add yourself stays.') }}</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h2 class="font-semibold text-lg flex items-center gap-2"><x-icon name="user-round" /> {{ __('The owner') }}</h2>
                            <div class="grid sm:grid-cols-2 gap-3">
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Email') }}</legend>
                                    <input name="owner_email" type="email" required class="input input-bordered w-full" autocomplete="off">
                                </fieldset>
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Your name') }}</legend>
                                    <input name="owner_name" class="input input-bordered w-full" autocomplete="off">
                                </fieldset>
                            </div>
                            <fieldset class="fieldset p-0 max-w-sm"><legend class="fieldset-legend">{{ __('Password') }}</legend>
                                <input name="owner_password" type="password" minlength="10" class="input input-bordered w-full" autocomplete="new-password">
                                <p class="label whitespace-normal">{{ __('At least 10 characters. Leave it empty and one is made up and shown once at the end.') }}</p>
                            </fieldset>
                        </div>
                    </section>

                    <div id="install-problem"></div>
                    <button class="btn btn-primary btn-lg w-fit gap-2"><x-icon name="download" /> {{ __('Install') }}</button>
                </form>
            @endif
        </main>
    </div>
</body>
</html>
