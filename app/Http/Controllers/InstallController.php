<?php

namespace App\Http\Controllers;

use App\Core\Install\Installer;
use App\Core\Install\ServerCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Throwable;

/**
 * The web installer (PLAN §54) — the same Installer the console drives, one
 * step per request, so no request runs longer than one module's migrations
 * and a host's 30-second limit never cuts an install in half.
 *
 * **Who may install.** Before the install there is no account, no session
 * and no key, and the first person to reach a fresh upload could otherwise
 * point the shop at a database of their own. So the wizard asks for a code
 * it wrote into `storage/app/install-key` — readable by whoever can open the
 * server's files (SSH, the host's file manager, FTP), which is the owner.
 * The code goes with the lock.
 *
 * No session means no language memory either: the language rides in every
 * form (`lang`), picked first from the browser's Accept-Language.
 *
 * `?mode=restore` is the other door (PLAN §67.8, stage 6): a shop raised
 * from a backup — found on the server, typed as a path, or uploaded into
 * `storage/app/restore` (deleted once the shop is back).
 */
class InstallController extends Controller
{
    public const KEY = 'app/install-key';

    public function show(Request $request)
    {
        $this->guard();
        $this->locale($request);

        $keyFile = storage_path(self::KEY);
        if (! is_file($keyFile) && is_writable(dirname($keyFile))) {
            file_put_contents($keyFile, Str::upper(Str::random(8))."\n");
        }

        return view('install.page', [
            'server' => app(ServerCheck::class)->server(),
            'keyPath' => 'storage/app/install-key',
            'url' => $request->getSchemeAndHttpHost().$request->getBasePath(),
            'pending' => Installer::pending() !== null,
            'bundles' => $this->bundles(),
            'mode' => $request->query('mode') === 'restore' ? 'restore' : 'new',
            'found' => $this->foundBackups(),
            'uploadLimit' => $this->uploadLimit(),
        ]);
    }

    /** The server and the database at the typed address, as the list the page shows. */
    public function check(Request $request)
    {
        $this->guard();
        $this->locale($request);

        // The address, the user and the password are the visitor's: without
        // the code this button tried any port of any machine this server can
        // reach, and said how it answered.
        if (! $this->keyMatches($request)) {
            return $this->problem(__('The install code does not match the one in :path.', ['path' => 'storage/app/install-key']));
        }

        $checks = app(ServerCheck::class);

        return view('install.checks', ['rows' => [...$checks->server(), ...$checks->database($this->db($request))]]);
    }

    public function start(Request $request)
    {
        $this->guard();
        $this->locale($request);

        if (! $this->keyMatches($request)) {
            return $this->problem(__('The install code does not match the one in :path.', ['path' => 'storage/app/install-key']));
        }

        $checks = app(ServerCheck::class);
        $rows = [...$checks->server(), ...$checks->database($this->db($request))];
        if (! ServerCheck::passes($rows)) {
            return $this->problem(__('The server or the database is not ready — press "Check" to see what is missing.'));
        }

        if ($request->input('mode') === 'restore') {
            return $this->startRestore($request);
        }

        try {
            $answers = (new Installer)->begin([
                'url' => rtrim((string) $request->input('url'), '/'),
                'name' => trim((string) $request->input('name')),
                'locale' => (string) $request->input('locale'),
                'currency' => strtoupper(trim((string) $request->input('currency'))),
                'country' => strtoupper(trim((string) $request->input('country'))),
                'db' => $this->db($request),
                'modules' => (string) $request->input('modules', 'store'),
                'owner' => [
                    'email' => mb_strtolower(trim((string) $request->input('owner_email'))),
                    'name' => trim((string) $request->input('owner_name')) ?: null,
                    'password' => (string) $request->input('owner_password'),
                ],
                'styles' => true,
                'demo' => $request->boolean('demo'),
            ]);
        } catch (Throwable $e) {
            return $this->problem($e->getMessage());
        }

        return $this->progress($answers, 0);
    }

    /** One step of the install in progress; the page asks for the next until there is none. */
    public function step(Request $request)
    {
        $this->guard();
        $this->locale($request);

        if (! $this->keyMatches($request)) {
            abort(403);
        }

        $answers = Installer::pending() ?? abort(409, 'No install is in progress.');
        $index = max(0, (int) $request->input('step'));
        $installer = new Installer;
        $steps = $installer->steps($answers);

        try {
            $installer->step($answers, $index);
        } catch (Throwable $e) {
            report($e);

            return $this->progress($answers, $index, $e->getMessage());
        }

        if ($index + 1 >= count($steps)) {
            @unlink(storage_path(self::KEY));

            if (! empty($answers['restore'])) {
                return view('install.restored', ['answers' => $answers]);
            }

            return view('install.done', [
                'answers' => $answers,
                'password' => ($answers['owner']['made_up'] ?? false) ? $answers['owner']['password'] : null,
            ]);
        }

        return $this->progress($answers, $index + 1);
    }

    protected function startRestore(Request $request)
    {
        $file = null;
        $uploaded = false;

        if ($upload = $request->file('backup_file')) {
            if (! $upload->isValid()) {
                return $this->problem(__('The backup did not arrive: :error', ['error' => $upload->getErrorMessage()]));
            }
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $upload->getClientOriginalName()) ?: 'backup.zip';
            $file = $upload->move(storage_path(Installer::UPLOADS), Str::lower(Str::random(8)).'-'.$name)->getPathname();
            $uploaded = true;
        } elseif (trim((string) $request->input('backup_path')) !== '') {
            $file = trim((string) $request->input('backup_path'));
        } elseif (array_key_exists((string) $request->input('backup_found'), $this->foundBackups())) {
            $file = base_path((string) $request->input('backup_found'));
        }

        if ($file === null) {
            return $this->problem(__('Choose the backup: found on the server, by its path, or uploaded.'));
        }

        $keyFrom = (string) $request->input('key_from', 'typed');

        try {
            $answers = (new Installer)->begin([
                'url' => rtrim(trim((string) $request->input('url')), '/'),
                'db' => $this->db($request),
                'styles' => true,
                'restore' => [
                    'file' => $file,
                    'password' => (string) $request->input('backup_password'),
                    'app_key' => $keyFrom === 'typed' ? (string) $request->input('app_key') : null,
                    'with_env' => $keyFrom === 'env',
                    'new_key' => $keyFrom === 'new',
                    'files' => $request->boolean('restore_files'),
                    'code' => $request->boolean('restore_code'),
                    'uploaded' => $uploaded,
                    'pg_bin' => trim((string) $request->input('pg_bin')),
                ],
            ]);
        } catch (Throwable $e) {
            if ($uploaded) {
                @unlink($file);
            }

            return $this->problem($e->getMessage());
        }

        return $this->progress($answers, 0);
    }

    /**
     * Backups already on this server: put by FTP into `storage/app/restore`,
     * or left in the backups' own folder by a shop that stood here. Named
     * from the site's own folder: this page is open to anyone until the
     * install, and where the server keeps its files is not theirs to read.
     *
     * @return array<string, string> path from the site's folder => what the list shows
     */
    protected function foundBackups(): array
    {
        $found = [];

        foreach ([Installer::UPLOADS, 'app/private/'.\App\Core\Backups\Backups::DIR] as $dir) {
            foreach (glob(storage_path($dir).'/*') ?: [] as $path) {
                $base = basename($path);
                $backup = is_dir($path) ? is_file($path.'/manifest.json') : (bool) preg_match('/\.(zip|tar|tar\.gz|enc)$/', $base);
                if ($backup) {
                    $found['storage/'.$dir.'/'.$base] = $base.(is_file($path) ? ' · '.number_format(filesize($path) / 1048576, 1).' MB' : '');
                }
            }
        }

        return $found;
    }

    /** What PHP takes in one upload here — the smaller of its two limits. */
    protected function uploadLimit(): string
    {
        $bytes = fn (string $v) => (int) $v * match (strtolower(substr(trim($v), -1))) { 'g' => 1 << 30, 'm' => 1 << 20, 'k' => 1 << 10, default => 1 };
        $limits = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]);

        return $limits ? number_format(min($limits) / 1048576, 0).' MB' : '—';
    }

    protected function progress(array $answers, int $index, ?string $error = null)
    {
        $steps = (new Installer)->steps($answers);

        return view('install.progress', [
            'steps' => $steps,
            'index' => $index,
            'error' => $error,
            'key' => (string) request()->input('key'),
            'restoring' => ! empty($answers['restore']),
        ]);
    }

    /** An answer the form shows above its button instead of replacing itself. */
    protected function problem(string $message)
    {
        return response(view('install.problem', ['message' => $message])->render())
            ->header('HX-Retarget', '#install-problem')
            ->header('HX-Reswap', 'innerHTML');
    }

    protected function keyMatches(Request $request): bool
    {
        $file = storage_path(self::KEY);
        $expected = is_file($file) ? trim((string) file_get_contents($file)) : '';
        $typed = strtoupper(trim((string) $request->input('key')));

        return $expected !== '' && hash_equals($expected, $typed);
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string} */
    protected function db(Request $request): array
    {
        return [
            'host' => trim((string) $request->input('db_host', '127.0.0.1')) ?: '127.0.0.1',
            'port' => (int) ($request->input('db_port') ?: 5432),
            'database' => trim((string) $request->input('db_database', 'torgash')) ?: 'torgash',
            'username' => trim((string) $request->input('db_username')),
            'password' => (string) $request->input('db_password'),
        ];
    }

    /** @return array<string, int> each set and how many modules it brings */
    protected function bundles(): array
    {
        $installer = new Installer;
        $out = [];

        foreach (array_keys((array) config('torgash.bundles')) as $bundle) {
            try {
                $out[$bundle] = count($installer->resolve($bundle));
            } catch (Throwable) {
                // A set naming a module this copy does not have is not offered.
            }
        }

        return $out;
    }

    protected function locale(Request $request): void
    {
        $lang = (string) $request->input('lang', '');
        if (! in_array($lang, Installer::LOCALES, true)) {
            $lang = $request->getPreferredLanguage(Installer::LOCALES) ?: 'en';
        }

        App::setLocale($lang);
    }

    /** Nothing here exists once the shop is installed. */
    protected function guard(): void
    {
        if (Installer::installed()) {
            abort(404);
        }
    }
}
