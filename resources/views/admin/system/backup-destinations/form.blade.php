@extends('admin.layout')

@section('title', $destination->exists ? $destination->name : __('New backup storage'))
@section('own_heading', '1')

{{-- A backup storage (PLAN §67.1). Passwords and keys are never printed
     back: a blank field keeps the stored one. --}}
@section('content')
    @php
        $driver = old('driver', $destination->driver);
        $value = fn (string $key, mixed $default = '') => old('settings.'.$key, $destination->setting($key, $default));
        $onS3 = app(\App\Core\Files\FileStorage::class)->onS3();
    @endphp

    <x-admin.form-bar>
        <a href="{{ route('admin.system.backup-destinations.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to backup storages') }}" aria-label="{{ __('Back to backup storages') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Backup storage') }}</div>
            <div class="font-semibold truncate leading-tight">{{ $destination->exists ? $destination->name : __('New backup storage') }}</div>
        </div>
        <x-slot:actions>
            <a href="{{ route('admin.system.backup-destinations.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button type="submit" form="destination-form" name="after_save" value="test" class="btn btn-ghost btn-sm">{{ __('Save and check') }}</button>
            <button type="submit" form="destination-form" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
        </x-slot:actions>
    </x-admin.form-bar>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if (session('test_error'))
        <div class="alert alert-error text-sm">{{ session('test_error') }}</div>
    @endif
    @if (session('host_key') && session('host_key_fingerprint') && $destination->exists)
        {{-- Beside the storage's form, never inside it. --}}
        <form method="POST" action="{{ route('admin.system.backup-destinations.trust', $destination) }}" class="alert alert-warning text-sm flex-col items-start gap-2">
            @csrf
            <div>{{ session('host_key') }}</div>
            <code class="font-mono text-sm break-all select-all">{{ session('host_key_fingerprint') }}</code>
            <div class="text-xs opacity-80">{{ __('On the server itself: ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub') }}</div>
            <input type="hidden" name="fingerprint" value="{{ session('host_key_fingerprint') }}">
            <button type="submit" class="btn btn-sm btn-warning">{{ __('The key is right — trust it') }}</button>
        </form>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm flex-col items-start">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" id="destination-form" x-data="{ driver: @js($driver) }"
          action="{{ $destination->exists ? route('admin.system.backup-destinations.update', $destination) : route('admin.system.backup-destinations.store') }}"
          class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
        @csrf
        @if ($destination->exists) @method('PUT') @endif

        <div class="flex flex-col gap-4 min-w-0">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Backup storage') }}</h3>
                    <div class="grid sm:grid-cols-[minmax(0,1fr)_16rem] gap-4">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }} *</legend>
                            <input name="name" value="{{ old('name', $destination->name) }}" required maxlength="120" class="input input-bordered w-full" placeholder="{{ __('Backup server') }}">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Kind') }}</legend>
                            @if ($destination->system)
                                <input class="input input-bordered w-full" value="{{ __($drivers[$destination->driver] ?? $destination->driver) }}" disabled>
                                <p class="label whitespace-normal">{{ __('Built in: updates keep their backup here.') }}</p>
                            @else
                                <select name="driver" class="select select-bordered w-full" x-model="driver">
                                    @foreach ($drivers as $key => $label)
                                        <option value="{{ $key }}" @selected($driver === $key)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </fieldset>
                    </div>
                </div>
            </section>

            @unless ($destination->system)
                {{-- One set of fields per kind; the hidden ones are disabled, so
                     only the chosen kind's are submitted. --}}
                <section class="card bg-base-100 border border-base-300" x-show="driver === 'disk'" x-cloak>
                    <fieldset class="card-body gap-3" :disabled="driver !== 'disk'">
                        <h3 class="font-semibold">{{ __('A disk of the shop') }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Disk') }}</legend>
                                <select name="settings[disk]" class="select select-bordered w-full">
                                    <option value="local" @selected($value('disk', 'local') === 'local')>{{ __('This server') }}</option>
                                    <option value="private" @selected($value('disk') === 'private')>{{ $onS3 ? __('The private bucket') : __('The private files folder') }}</option>
                                </select>
                                <p class="label whitespace-normal">{{ $onS3 ? __('The bucket keeps a copy away from the server it protects.') : __('Both are on this server while files are a local folder.') }}</p>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Folder on the disk') }}</legend>
                                <input name="settings[root]" value="{{ $value('root', 'backups') }}" class="input input-bordered w-full font-mono" placeholder="backups">
                            </fieldset>
                        </div>
                    </fieldset>
                </section>

                <section class="card bg-base-100 border border-base-300" x-show="driver === 'folder'" x-cloak>
                    <fieldset class="card-body gap-3" :disabled="driver !== 'folder'">
                        <h3 class="font-semibold">{{ __('A folder on this server') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Full path') }} *</legend>
                            <input name="settings[path]" value="{{ $value('path') }}" class="input input-bordered w-full font-mono" placeholder="{{ PHP_OS_FAMILY === 'Windows' ? 'D:\\backups\\shop' : '/var/backups/shop' }}" autocapitalize="off" spellcheck="false">
                            <p class="label whitespace-normal">{{ __('Another disk of the server, or a network drive mounted on it. Never inside the site\'s public folder.') }}</p>
                        </fieldset>
                    </fieldset>
                </section>

                <section class="card bg-base-100 border border-base-300" x-show="driver === 's3'" x-cloak>
                    <fieldset class="card-body gap-3" :disabled="driver !== 's3'">
                        <h3 class="font-semibold">{{ __('S3-compatible storage') }}</h3>
                        <p class="text-sm opacity-70">{{ __('Amazon S3, Backblaze B2, Cloudflare R2, MinIO, RustFS, Yandex Object Storage, Selectel and others.') }}</p>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0 sm:col-span-2"><legend class="fieldset-legend">{{ __('Address (endpoint)') }}</legend>
                                <input name="settings[endpoint]" value="{{ $value('endpoint') }}" class="input input-bordered w-full font-mono" placeholder="https://s3.example.com" autocapitalize="off" spellcheck="false">
                                <p class="label">{{ __('Empty for Amazon S3 itself.') }}</p>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Bucket') }} *</legend>
                                <input name="settings[bucket]" value="{{ $value('bucket') }}" class="input input-bordered w-full font-mono" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Region') }}</legend>
                                <input name="settings[region]" value="{{ $value('region') }}" class="input input-bordered w-full font-mono" placeholder="us-east-1" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Access key') }} *</legend>
                                <input name="settings[key]" value="" class="input input-bordered w-full font-mono" autocomplete="off" autocapitalize="off" spellcheck="false"
                                       placeholder="{{ ($secrets['key'] ?? false) ? __('kept — type to change') : '' }}">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Secret key') }} *</legend>
                                <input type="password" name="settings[secret]" value="" class="input input-bordered w-full font-mono" autocomplete="new-password"
                                       placeholder="{{ ($secrets['secret'] ?? false) ? __('kept — type to change') : '' }}">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Folder in the bucket') }}</legend>
                                <input name="settings[root]" value="{{ $value('root') }}" class="input input-bordered w-full font-mono" placeholder="backups" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <label class="flex items-center gap-3 cursor-pointer self-end pb-2">
                                <input type="checkbox" name="settings[path_style]" value="1" class="toggle toggle-sm toggle-success" @checked($value('path_style', false))>
                                <span class="text-sm">{{ __('Path-style addresses (MinIO, RustFS)') }}</span>
                            </label>
                        </div>
                        <p class="text-xs opacity-60">{{ __('The keys are kept encrypted with the shop\'s APP_KEY. A key that may only write to this bucket is the safer one.') }}</p>
                    </fieldset>
                </section>

                @php $kept = fn (string $key) => ($secrets[$key] ?? false) ? __('kept — type to change') : ''; @endphp

                <section class="card bg-base-100 border border-base-300" x-show="driver === 'sftp'" x-cloak x-data="{ auth: @js($value('auth', 'password')) }">
                    <fieldset class="card-body gap-3" :disabled="driver !== 'sftp'">
                        <h3 class="font-semibold">{{ __('SFTP server') }}</h3>
                        <p class="text-sm opacity-70">{{ __('Files over SSH: a VPS, a storage box, a NAS. The safest of the server kinds.') }}</p>
                        <div class="grid sm:grid-cols-[minmax(0,1fr)_8rem] gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Server') }} *</legend>
                                <input name="settings[host]" value="{{ $value('host') }}" class="input input-bordered w-full font-mono" placeholder="backup.example.com" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Port') }}</legend>
                                <input type="number" name="settings[port]" value="{{ $value('port', 22) }}" min="1" max="65535" class="input input-bordered w-full font-mono">
                            </fieldset>
                        </div>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Login') }} *</legend>
                                <input name="settings[username]" value="{{ $value('username') }}" class="input input-bordered w-full font-mono" autocomplete="off" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sign in with') }}</legend>
                                <select name="settings[auth]" class="select select-bordered w-full" x-model="auth">
                                    <option value="password">{{ __('A password') }}</option>
                                    <option value="key">{{ __('A private key') }}</option>
                                </select>
                            </fieldset>
                            <fieldset class="fieldset p-0 sm:col-span-2" x-show="auth === 'password'" :disabled="auth !== 'password'"><legend class="fieldset-legend">{{ __('Password') }} *</legend>
                                <input type="password" name="settings[password]" value="" class="input input-bordered w-full font-mono" autocomplete="new-password" placeholder="{{ $kept('password') }}">
                            </fieldset>
                            <fieldset class="fieldset p-0 sm:col-span-2" x-show="auth === 'key'" x-cloak :disabled="auth !== 'key'"><legend class="fieldset-legend">{{ __('Private key') }} *</legend>
                                <textarea name="settings[private_key]" rows="4" class="textarea textarea-bordered w-full font-mono text-xs" autocomplete="off" spellcheck="false"
                                          placeholder="{{ $kept('private_key') ?: '-----BEGIN OPENSSH PRIVATE KEY-----' }}"></textarea>
                                <p class="label whitespace-normal">{{ __('A key made for backups alone, its public half in the server\'s authorized_keys. OpenSSH, PEM or PuTTY.') }}</p>
                            </fieldset>
                            <fieldset class="fieldset p-0 sm:col-span-2" x-show="auth === 'key'" x-cloak :disabled="auth !== 'key'"><legend class="fieldset-legend">{{ __('Key passphrase') }}</legend>
                                <input type="password" name="settings[passphrase]" value="" class="input input-bordered w-full font-mono" autocomplete="new-password" placeholder="{{ $kept('passphrase') }}">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Folder on the server') }}</legend>
                                <input name="settings[root]" value="{{ $value('root') }}" class="input input-bordered w-full font-mono" placeholder="backups" autocapitalize="off" spellcheck="false">
                                <p class="label">{{ __('Inside the folder the login starts in; made when needed.') }}</p>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Server key') }}</legend>
                                <input name="settings[fingerprint]" value="{{ $value('fingerprint') }}" class="input input-bordered w-full font-mono text-xs" placeholder="SHA256:…" autocapitalize="off" spellcheck="false">
                                <p class="label whitespace-normal">{{ __('Empty: "Save and check" shows the key the server has, for you to confirm. Any other key afterwards is refused.') }}</p>
                            </fieldset>
                        </div>
                    </fieldset>
                </section>

                <section class="card bg-base-100 border border-base-300" x-show="driver === 'ftp'" x-cloak>
                    <fieldset class="card-body gap-3" :disabled="driver !== 'ftp'">
                        <h3 class="font-semibold">{{ __('FTP server') }}</h3>
                        <div class="grid sm:grid-cols-[minmax(0,1fr)_8rem] gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Server') }} *</legend>
                                <input name="settings[host]" value="{{ $value('host') }}" class="input input-bordered w-full font-mono" placeholder="ftp.example.com" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Port') }}</legend>
                                <input type="number" name="settings[port]" value="{{ $value('port', 21) }}" min="1" max="65535" class="input input-bordered w-full font-mono">
                            </fieldset>
                        </div>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Login') }} *</legend>
                                <input name="settings[username]" value="{{ $value('username') }}" class="input input-bordered w-full font-mono" autocomplete="off" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Password') }} *</legend>
                                <input type="password" name="settings[password]" value="" class="input input-bordered w-full font-mono" autocomplete="new-password" placeholder="{{ $kept('password') }}">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Folder on the server') }}</legend>
                                <input name="settings[root]" value="{{ $value('root') }}" class="input input-bordered w-full font-mono" placeholder="backups" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <div class="flex flex-col gap-2 self-end pb-1">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" name="settings[ssl]" value="1" class="toggle toggle-sm toggle-success" @checked($value('ssl', true))>
                                    <span class="text-sm">{{ __('Encrypted (FTPS, AUTH TLS)') }}</span>
                                </label>
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" name="settings[passive]" value="1" class="toggle toggle-sm toggle-success" @checked($value('passive', true))>
                                    <span class="text-sm">{{ __('Passive mode') }}</span>
                                </label>
                            </div>
                        </div>
                        <p class="text-xs opacity-60">{{ __('Without encryption the password and every customer\'s data cross the network as they are. Take SFTP if the server has it. FTP cannot go through a proxy.') }}</p>
                    </fieldset>
                </section>

                <section class="card bg-base-100 border border-base-300" x-show="driver === 'webdav'" x-cloak>
                    <fieldset class="card-body gap-3" :disabled="driver !== 'webdav'">
                        <h3 class="font-semibold">{{ __('WebDAV server') }}</h3>
                        <p class="text-sm opacity-70">{{ __('Nextcloud, ownCloud, Yandex Disk, a NAS (Synology, QNAP) and others.') }}</p>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0 sm:col-span-2"><legend class="fieldset-legend">{{ __('Address') }} *</legend>
                                <input name="settings[url]" value="{{ $value('url') }}" class="input input-bordered w-full font-mono" placeholder="https://cloud.example.com/remote.php/dav/files/shop" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Login') }}</legend>
                                <input name="settings[username]" value="{{ $value('username') }}" class="input input-bordered w-full font-mono" autocomplete="off" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Password') }}</legend>
                                <input type="password" name="settings[password]" value="" class="input input-bordered w-full font-mono" autocomplete="new-password" placeholder="{{ $kept('password') }}">
                                <p class="label whitespace-normal">{{ __('An app password where the service has them.') }}</p>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Folder on the server') }}</legend>
                                <input name="settings[root]" value="{{ $value('root') }}" class="input input-bordered w-full font-mono" placeholder="backups" autocapitalize="off" spellcheck="false">
                            </fieldset>
                        </div>
                    </fieldset>
                </section>

                {{-- The kinds modules add (`core.backup.destinations`): the card
                     is core's, the fields inside it the module's own view. --}}
                @foreach ($extensions as $extensionDriver => $extension)
                    <section class="card bg-base-100 border border-base-300" x-show="driver === @js($extensionDriver)" x-cloak>
                        <fieldset class="card-body gap-3" :disabled="driver !== @js($extensionDriver)">
                            <h3 class="font-semibold">{{ __($extension->label()) }}</h3>
                            @include($extension->form(), ['destination' => $destination, 'value' => $value, 'kept' => $kept])
                        </fieldset>
                    </section>
                @endforeach

                <section class="card bg-base-100 border border-base-300" x-show="@js($proxied).includes(driver)" x-cloak
                         x-data="{ proxy: @js($value('proxy_type', 'none')) }">
                    <fieldset class="card-body gap-3" :disabled="! @js($proxied).includes(driver)">
                        <h3 class="font-semibold">{{ __('Proxy') }}</h3>
                        <div class="grid sm:grid-cols-[12rem_minmax(0,1fr)_8rem] gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Through') }}</legend>
                                <select name="settings[proxy_type]" class="select select-bordered w-full" x-model="proxy">
                                    <option value="none">{{ __('Directly') }}</option>
                                    <option value="http">{{ __('HTTP proxy') }}</option>
                                    <option value="socks5">SOCKS5</option>
                                </select>
                            </fieldset>
                            <fieldset class="fieldset p-0" x-show="proxy !== 'none'" :disabled="proxy === 'none'"><legend class="fieldset-legend">{{ __('Proxy address') }} *</legend>
                                <input name="settings[proxy_host]" value="{{ $value('proxy_host') }}" class="input input-bordered w-full font-mono" placeholder="proxy.example.com" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <fieldset class="fieldset p-0" x-show="proxy !== 'none'" :disabled="proxy === 'none'"><legend class="fieldset-legend">{{ __('Port') }}</legend>
                                <input type="number" name="settings[proxy_port]" value="{{ $value('proxy_port') }}" min="1" max="65535" class="input input-bordered w-full font-mono" :placeholder="proxy === 'socks5' ? '1080' : '3128'">
                            </fieldset>
                            <fieldset class="fieldset p-0 sm:col-start-2" x-show="proxy !== 'none'" :disabled="proxy === 'none'"><legend class="fieldset-legend">{{ __('Proxy login') }}</legend>
                                <input name="settings[proxy_username]" value="{{ $value('proxy_username') }}" class="input input-bordered w-full font-mono" autocomplete="off" autocapitalize="off" spellcheck="false">
                            </fieldset>
                            <fieldset class="fieldset p-0 sm:col-span-1" x-show="proxy !== 'none'" :disabled="proxy === 'none'"><legend class="fieldset-legend">{{ __('Proxy password') }}</legend>
                                <input type="password" name="settings[proxy_password]" value="" class="input input-bordered w-full font-mono" autocomplete="new-password" placeholder="{{ $kept('proxy_password') }}">
                            </fieldset>
                        </div>
                        <p class="text-xs opacity-60" x-show="proxy !== 'none'">{{ __('For a storage reachable only from another network. The proxy sees where the backup goes, not what is in it: the connection to the storage stays encrypted.') }}</p>
                    </fieldset>
                </section>
            @endunless
        </div>

        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Folder for the backups') }}</h3>
                    <fieldset class="fieldset p-0">
                        <input name="path_template" value="{{ old('path_template', $destination->path_template) }}" maxlength="200" class="input input-bordered w-full font-mono text-sm" placeholder="{plan}/{Y}" autocapitalize="off" spellcheck="false">
                        <p class="label whitespace-normal">{{ __('Inside the storage, made when needed. {shop}, {plan}, {Y}, {m}, {d}. Empty — straight in the storage.') }}</p>
                    </fieldset>
                </div>
            </section>
        </div>
    </form>
@endsection
