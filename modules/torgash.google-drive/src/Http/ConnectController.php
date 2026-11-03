<?php

namespace Torgash\GoogleDrive\Http;

use App\Core\Backups\Destinations;
use App\Core\Backups\Proxy;
use App\Http\Controllers\Controller;
use App\Models\BackupDestination;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;
use Torgash\GoogleDrive\DriveClient;
use Torgash\GoogleDrive\GoogleDriveDestination;
use Torgash\GoogleDrive\GoogleDriveServiceProvider;

/**
 * Connecting a Google account to a storage: the owner is sent to Google's
 * consent screen and comes back with a code, which is swapped for a
 * refresh token kept in the storage's encrypted secrets. `state` is a
 * random word kept in the session, so a callback nobody here started is
 * refused; `prompt=consent` + `access_type=offline` make Google hand a
 * refresh token every time, not only the first (without it a storage
 * connected a second time would get none and fail an hour later).
 */
class ConnectController extends Controller
{
    public const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    public function connect(Request $request, BackupDestination $destination)
    {
        abort_unless($destination->driver === GoogleDriveServiceProvider::DRIVER, 404);

        [$id, $secret] = GoogleDriveDestination::application();
        if ($id === '' || $secret === '') {
            return $this->back($destination)->with('test_error', __('The Google client ID and secret are not set: Settings → Google Drive.'));
        }

        $state = Str::random(40);
        $request->session()->put('google_drive.connect', ['state' => $state, 'destination' => $destination->id]);

        return redirect()->away(self::AUTH.'?'.http_build_query([
            'client_id' => $id,
            'redirect_uri' => route('admin.google-drive.callback'),
            'response_type' => 'code',
            'scope' => GoogleDriveDestination::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ]));
    }

    public function callback(Request $request)
    {
        $started = (array) $request->session()->pull('google_drive.connect', []);
        $destination = isset($started['destination']) ? BackupDestination::query()->find($started['destination']) : null;

        if (! $destination || ! isset($started['state']) || ! hash_equals($started['state'], (string) $request->query('state', ''))) {
            abort(403, 'This is not the connection that was started here.');
        }

        $back = $this->back($destination);

        if ($request->query('error')) {
            return $back->with('test_error', $request->query('error') === 'access_denied'
                ? __('Google access was not granted — the storage stays unconnected.')
                : __('Google answered: :error', ['error' => (string) $request->query('error')]));
        }

        [$id, $secret] = GoogleDriveDestination::application();
        $proxy = Proxy::from((array) $destination->settings, $destination->secrets());

        try {
            $tokens = DriveClient::exchange($id, $secret, (string) $request->query('code'), route('admin.google-drive.callback'), $proxy);

            if (empty($tokens['refresh_token'])) {
                return $back->with('test_error', __('Google handed no lasting key. Take the application\'s access away in the Google account (Security → Third-party access), then connect again.'));
            }

            $about = (new DriveClient($id, $secret, (string) $tokens['refresh_token'], $proxy))->about();
        } catch (Throwable $e) {
            return $back->with('test_error', __('The Google account could not be connected: :error', ['error' => Destinations::reason($e)]));
        }

        $destination->settings = [
            'account' => (string) ($about['user']['emailAddress'] ?? ''),
            'connected_at' => now()->toIso8601String(),
        ] + (array) $destination->settings;
        $destination->putSecrets(['refresh_token' => (string) $tokens['refresh_token']] + $destination->secrets());
        $destination->save();

        $error = app(Destinations::class)->test($destination);

        return $error === null
            ? $back->with('status', __('Connected as :account. A file was written, read back and deleted.', ['account' => $destination->setting('account')]))
            : $back->with('test_error', __('Connected as :account, but the storage does not work: :error', ['account' => $destination->setting('account'), 'error' => $error]));
    }

    public function disconnect(BackupDestination $destination)
    {
        abort_unless($destination->driver === GoogleDriveServiceProvider::DRIVER, 404);

        if ($token = $destination->secret('refresh_token')) {
            DriveClient::revoke($token, Proxy::from((array) $destination->settings, $destination->secrets()));
        }

        $destination->settings = ['account' => '', 'connected_at' => ''] + (array) $destination->settings;
        $destination->putSecrets(['refresh_token' => ''] + $destination->secrets());
        $destination->save();

        return $this->back($destination)->with('status', __('The Google account is disconnected. The backups already in the Drive stay there.'));
    }

    protected function back(BackupDestination $destination)
    {
        return redirect()->route('admin.system.backup-destinations.edit', $destination);
    }
}
