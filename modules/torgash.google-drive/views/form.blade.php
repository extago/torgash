{{--
    The Google Drive kind's fields, drawn by core inside the storage form's
    card (App\Core\Backups\Destination::form()). Inside the storage's own
    <form>: the connect and disconnect buttons send it elsewhere with
    formaction — a <form> of their own here would be nested and dropped.
    `$destination`, `$value`, `$kept` come from core's form.
--}}
@php
    [$appId, $appSecret] = \Torgash\GoogleDrive\GoogleDriveDestination::application();
    $account = (string) $destination->setting('account', '');
    $connected = $destination->exists && $destination->driver === \Torgash\GoogleDrive\GoogleDriveServiceProvider::DRIVER && $account !== '';
    $connectedAt = $connected && $destination->setting('connected_at') ? \Illuminate\Support\Carbon::parse($destination->setting('connected_at')) : null;
@endphp

<p class="text-sm opacity-70">{{ __('A folder in the Google Drive of the account you connect. The shop sees only the files it has put there itself.') }}</p>

@if ($appId === '' || $appSecret === '')
    <div class="alert alert-warning text-sm">
        {{ __('First the shop\'s Google Cloud application: its client ID and secret go into the module\'s settings.') }}
        <a href="{{ route('admin.settings.edit', \Torgash\GoogleDrive\GoogleDriveServiceProvider::ID) }}" class="link">{{ __('Settings → Google Drive') }}</a>
    </div>
@endif

<div class="grid sm:grid-cols-2 gap-4">
    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Folder in the Drive') }}</legend>
        <input name="settings[root]" value="{{ $value('root', 'Torgash backups') }}" class="input input-bordered w-full font-mono" placeholder="Torgash backups" autocapitalize="off" spellcheck="false">
        <p class="label whitespace-normal">{{ __('Under "My Drive", made when needed. A path with slashes makes folders inside folders.') }}</p>
    </fieldset>

    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Google account') }}</legend>
        @if ($connected)
            <div class="flex flex-wrap items-center gap-2">
                <span class="badge badge-success badge-sm">{{ __('connected') }}</span>
                <span class="font-mono text-sm break-all">{{ $account }}</span>
            </div>
            @if ($connectedAt)
                <p class="label whitespace-normal">{{ __('Connected :when.', ['when' => $connectedAt->translatedFormat('d.m.Y H:i')]) }}</p>
            @endif
            <div class="flex flex-wrap gap-2 mt-1">
                <button type="submit" formaction="{{ route('admin.google-drive.connect', $destination) }}" formmethod="post" formnovalidate class="btn btn-outline btn-sm">
                    {{ __('Connect another account') }}
                </button>
                <button type="submit" formaction="{{ route('admin.google-drive.disconnect', $destination) }}" formmethod="post" formnovalidate class="btn btn-ghost btn-sm text-error">
                    {{ __('Disconnect') }}
                </button>
            </div>
        @elseif ($destination->exists && $destination->driver === \Torgash\GoogleDrive\GoogleDriveServiceProvider::DRIVER)
            <div><span class="badge badge-ghost badge-sm">{{ __('not connected') }}</span></div>
            <div class="mt-1">
                <button type="submit" formaction="{{ route('admin.google-drive.connect', $destination) }}" formmethod="post" formnovalidate class="btn btn-primary btn-sm" @disabled($appId === '' || $appSecret === '')>
                    <x-icon name="log-in" size="sm" /> {{ __('Connect a Google account') }}
                </button>
            </div>
            <p class="label whitespace-normal">{{ __('Google asks whose Drive it is and whether the shop may keep files there; then you are brought back here.') }}</p>
        @else
            <p class="label whitespace-normal">{{ __('Save the storage first; the button to connect a Google account appears then.') }}</p>
        @endif
    </fieldset>
</div>

<p class="text-xs opacity-60">{{ __('The key Google hands over is kept encrypted with the shop\'s APP_KEY. What goes into the Drive is the backup as the plan makes it — with a password if the plan has one.') }}</p>
