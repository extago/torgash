{{-- The wizard's "Restore from a backup" (PLAN §67.8, stage 6): the same
     Installer as the console's `torgash:install --restore=`. Nothing about
     the shop is asked — its name, language, modules and owner are in the
     backup; what is asked is where the backup is and how it opens. --}}
<form id="install-form" class="flex flex-col gap-6" hx-post="{{ route('install.start') }}" hx-target="#install-main" hx-encoding="multipart/form-data">
    <input type="hidden" name="lang" value="{{ app()->getLocale() }}">
    <input type="hidden" name="mode" value="restore">

    @include('install.basics')

    <section class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            <h2 class="font-semibold text-lg flex items-center gap-2"><x-icon name="archive" /> {{ __('The backup') }}</h2>
            <p class="text-sm opacity-70">{{ __('A backup made by Torgash: a .zip, .tar, .tar.gz or .enc file, or the folder of one made without an archive. A big one is best put on the server first — by FTP or SSH into :path — rather than uploaded here.', ['path' => 'storage/app/restore']) }}</p>

            @if ($found !== [])
                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Found on this server') }}</legend>
                    <select name="backup_found" class="select select-bordered w-full font-mono">
                        <option value="">—</option>
                        @foreach ($found as $foundPath => $foundLabel)
                            <option value="{{ $foundPath }}">{{ $foundLabel }}</option>
                        @endforeach
                    </select>
                </fieldset>
            @endif

            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Or its path on the server') }}</legend>
                <input name="backup_path" class="input input-bordered w-full font-mono" placeholder="/home/shop/torgash-2026-11-02-034000.zip" autocomplete="off">
            </fieldset>

            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Or upload it') }}</legend>
                <input type="file" name="backup_file" class="file-input file-input-bordered w-full">
                <p class="label whitespace-normal">{{ __('This server takes files up to :size. An uploaded backup is deleted once the shop is restored.', ['size' => $uploadLimit]) }}</p>
            </fieldset>

            <fieldset class="fieldset p-0 max-w-sm"><legend class="fieldset-legend">{{ __('Password of the backup') }}</legend>
                <input name="backup_password" type="password" class="input input-bordered w-full" autocomplete="off">
                <p class="label whitespace-normal">{{ __('Only for an encrypted backup — the password of the plan that made it.') }}</p>
            </fieldset>
        </div>
    </section>

    <section class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            <h2 class="font-semibold text-lg flex items-center gap-2"><x-icon name="key-round" /> {{ __('The key of the old shop') }}</h2>
            <p class="text-sm opacity-70">{{ __('APP_KEY from the old shop\'s .env. Gift card codes, the mail password, storage passwords and second factors are encrypted with it, and a backup does not open them without it.') }}</p>

            <label class="flex items-start gap-3 border border-base-300 rounded-box p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                <input type="radio" name="key_from" value="typed" class="radio mt-0.5" checked>
                <span class="flex flex-col gap-2 w-full">
                    <span class="font-medium">{{ __('I have the key') }}</span>
                    <input name="app_key" class="input input-bordered w-full font-mono" placeholder="base64:…" autocomplete="off" aria-label="APP_KEY">
                </span>
            </label>
            <label class="flex items-start gap-3 border border-base-300 rounded-box p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                <input type="radio" name="key_from" value="env" class="radio mt-0.5">
                <span class="flex flex-col">
                    <span class="font-medium">{{ __('Take the .env inside the backup') }}</span>
                    <span class="text-sm opacity-70">{{ __('An encrypted backup made with ".env inside" carries the key, the mail and the storage settings. This server\'s database and address are written over it.') }}</span>
                </span>
            </label>
            <label class="flex items-start gap-3 border border-base-300 rounded-box p-3 cursor-pointer has-[:checked]:border-warning has-[:checked]:bg-warning/5">
                <input type="radio" name="key_from" value="new" class="radio mt-0.5">
                <span class="flex flex-col">
                    <span class="font-medium">{{ __('The key is lost — start with a new one') }}</span>
                    <span class="text-sm opacity-70">{{ __('The shop comes back, but what the old key encrypted does not open: gift card codes, the mail password, storage passwords and second factors have to be typed again.') }}</span>
                </span>
            </label>
        </div>
    </section>

    <section class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            <h2 class="font-semibold text-lg flex items-center gap-2"><x-icon name="store" /> {{ __('The shop') }}</h2>
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Address') }}</legend>
                <input name="url" value="{{ $url }}" class="input input-bordered w-full font-mono">
                <p class="label whitespace-normal">{{ __('Where the shop answers now. Left empty, the address it had; a new one is added to its storefront.') }}</p>
            </fieldset>
            <label class="flex items-center gap-3 cursor-pointer"><input type="checkbox" name="restore_files" value="1" class="checkbox" checked> <span>{{ __('The uploaded files (pictures, documents), when the backup has them') }}</span></label>
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Folder of the PostgreSQL tools') }}</legend>
                <input name="pg_bin" class="input input-bordered w-full font-mono" placeholder="/usr/lib/postgresql/18/bin" autocomplete="off">
                <p class="label whitespace-normal">{{ __('Only when pg_restore and psql are not found on their own — the restore says so.') }}</p>
            </fieldset>
            <label class="flex items-center gap-3 cursor-pointer"><input type="checkbox" name="restore_code" value="1" class="checkbox"> <span>{{ __('The modules and themes from the backup too (paid modules, the shop\'s own theme)') }}</span></label>
        </div>
    </section>

    <div id="install-problem"></div>
    <button class="btn btn-primary btn-lg w-fit gap-2"><x-icon name="archive-restore" /> {{ __('Restore the shop') }}</button>
</form>
