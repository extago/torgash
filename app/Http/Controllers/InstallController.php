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
        ]);
    }

    /** The server and the database at the typed address, as the list the page shows. */
    public function check(Request $request)
    {
        $this->guard();
        $this->locale($request);

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

            return view('install.done', [
                'answers' => $answers,
                'password' => ($answers['owner']['made_up'] ?? false) ? $answers['owner']['password'] : null,
            ]);
        }

        return $this->progress($answers, $index + 1);
    }

    protected function progress(array $answers, int $index, ?string $error = null)
    {
        $steps = (new Installer)->steps($answers);

        return view('install.progress', [
            'steps' => $steps,
            'index' => $index,
            'error' => $error,
            'key' => (string) request()->input('key'),
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
