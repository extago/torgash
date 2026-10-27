<?php

namespace Torgash\Exim\Http;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\Models\EximProfile;

class FeedController extends Controller
{
    /** A feed is for the fetcher it was given to: never indexed, never kept by a shared cache. */
    protected const HEADERS = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store'];

    public function show(Request $request, EximProfile $profile, ExportRunner $runner)
    {
        if (! $profile->enabled || $profile->type !== 'export') {
            abort(404);
        }

        // People's data by address alone is refused, whatever the address:
        // the owner gets told on the profile's form why it does not answer.
        if ($profile->feedNeedsLogin()) {
            return response("This feed holds customers' data. Set a login and password for it in the export profile.", 403, self::HEADERS);
        }

        // Deliberately NOT Laravel's auth.basic middleware — that checks the
        // `users` guard, not this profile's own stored credentials.
        if ($profile->requiresBasicAuth()) {
            $user = $request->getUser();
            $pass = $request->getPassword();

            if ($user !== $profile->basic_auth_user || $pass === null || ! $profile->checkBasicAuthPass($pass)) {
                return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="Feed"'] + self::HEADERS);
            }
        }

        $contentType = $runner->rendererFor($profile->format)->contentType();
        $current = $runner->current($profile);

        // A file that rebuilds itself — by its schedule, by a change in its
        // data — is handed out as it stands: a fetch costs a file read,
        // however often a marketplace or a stray bot asks.
        if ($current && $profile->rebuildsItself()) {
            return $this->file($current, $contentType);
        }

        // On request — or the very first file of one that rebuilds itself:
        // built now. Fetchers arriving together wait for ONE build and are
        // all handed it, rather than each starting their own; a build that
        // keeps them waiting too long leaves them the last file there is.
        try {
            $path = $runner->run($profile, wait: 60, unlessBuiltSince: now()->startOfSecond());
        } catch (LockTimeoutException) {
            return $current
                ? $this->file($current, $contentType)
                : response('The file is being built. Try again in a minute.', 503, ['Retry-After' => '60'] + self::HEADERS);
        }

        return $this->file($path, $contentType);
    }

    protected function file(string $path, string $contentType)
    {
        return Storage::disk('local')->response($path, null, ['Content-Type' => $contentType] + self::HEADERS);
    }
}
