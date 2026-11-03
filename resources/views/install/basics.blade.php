{{-- The install code and the database: the same two cards in front of a new
     shop and of one restored from a backup. Inside the form that sends them
     (#install-form), so "Check" includes them. --}}
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
