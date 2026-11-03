<?php

namespace App\Http\Controllers\Admin;

use App\Core\Backups\Destinations;
use App\Core\Backups\HostKeyNotConfirmed;
use App\Core\Backups\Names;
use App\Core\Backups\SftpConnection;
use App\Http\Controllers\Controller;
use App\Models\BackupDestination;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin → System → Backup storages (PLAN §67.1): where backups are kept.
 * Passwords and keys are never printed back — a blank field keeps them.
 */
class BackupDestinationsController extends Controller
{
    public function index()
    {
        return view('admin.system.backup-destinations.index', [
            'destinations' => BackupDestination::query()->withCount('plans')->orderByDesc('system')->orderBy('id')->get(),
            'drivers' => app(Destinations::class)->drivers(),
            'extensions' => app(Destinations::class)->extensions(),
        ]);
    }

    public function create()
    {
        return $this->form(new BackupDestination(['driver' => 'folder', 'settings' => [], 'path_template' => '{plan}']));
    }

    public function edit(BackupDestination $destination)
    {
        return $this->form($destination);
    }

    public function store(Request $request)
    {
        $destination = new BackupDestination;
        $this->fill($request, $destination);
        $destination->save();

        return $this->saved($request, $destination);
    }

    public function update(Request $request, BackupDestination $destination)
    {
        $this->fill($request, $destination);
        $destination->save();

        return $this->saved($request, $destination);
    }

    public function destroy(BackupDestination $destination)
    {
        if ($destination->system) {
            return back()->withErrors(['destination' => __('The server\'s own folder cannot be deleted: updates keep their backup in it.')]);
        }

        if ($destination->plans()->exists()) {
            return back()->withErrors(['destination' => __('Backup plans keep their backups in ":storage" — take it out of them first.', ['storage' => $destination->name])]);
        }

        $destination->delete();

        return redirect()->route('admin.system.backup-destinations.index')
            ->with('status', __('Storage deleted. The backups already in it stay where they are.'));
    }

    /** Write, read back, delete a small file. */
    public function test(BackupDestination $destination)
    {
        [$key, $message] = $this->testResult($destination, false);

        return match ($key) {
            'test_error' => back()->withErrors(['test' => $message]),
            // The key is confirmed on the storage's own screen.
            'host_key' => redirect()->route('admin.system.backup-destinations.edit', $destination)->with($key, $message),
            default => back()->with($key, $message),
        };
    }

    /**
     * The owner compared the SFTP server's key with the one the hosting gives
     * and trusts it. The fingerprint comes back from the form the check drew,
     * and is trusted only if the server STILL shows it.
     */
    public function trust(Request $request, Destinations $destinations, BackupDestination $destination)
    {
        abort_unless($destination->driver === 'sftp', 404);

        $fingerprint = SftpConnection::normalise((string) $request->input('fingerprint'));
        $back = redirect()->route('admin.system.backup-destinations.edit', $destination);

        try {
            $seen = $destinations->hostKey($destination);
        } catch (\Throwable $e) {
            return $back->with('test_error', __('":storage" does not work: :error', ['storage' => $destination->name, 'error' => $e->getMessage()]));
        }

        if (! hash_equals($seen, $fingerprint)) {
            return $back->with('test_error', __('The server now shows another key (:key). Nothing was trusted — check again.', ['key' => $seen]));
        }

        $destination->settings = ['fingerprint' => $seen] + (array) $destination->settings;
        $destination->save();

        return $back->with(...$this->testResult($destination, false));
    }

    protected function form(BackupDestination $destination)
    {
        $destinations = app(Destinations::class);

        return view('admin.system.backup-destinations.form', [
            'destination' => $destination,
            'drivers' => $destinations->drivers(),
            // The kinds modules add, each drawing its own fields inside a card of the form.
            'extensions' => $destinations->extensions(),
            'proxied' => $destinations->proxied(),
            'secrets' => $destination->exists ? array_map(fn ($v) => $v !== '', $destination->secrets()) : [],
        ]);
    }

    protected function fill(Request $request, BackupDestination $destination): void
    {
        $destinations = app(Destinations::class);

        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'driver' => [$destination->system ? 'nullable' : 'required', Rule::in(array_keys($destinations->drivers()))],
            'path_template' => ['nullable', 'string', 'max:200'],
        ]);

        // The server's own folder stays what it is: only its name and folder template move.
        $driver = $destination->system ? $destination->driver : (string) $request->input('driver');
        $errors = [];

        if (! $destination->system) {
            [$settings, $secrets, $errors] = $destinations->fromInput($driver, (array) $request->input('settings', []), $destination);
            $destination->driver = $driver;
            $destination->settings = $settings;
            $destination->putSecrets($secrets);
        }

        $template = trim((string) $request->input('path_template', ''));
        if ($problem = Names::folderError($template)) {
            $errors['path_template'] = __($problem);
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $destination->name = trim((string) $request->input('name'));
        $destination->path_template = $template;
    }

    protected function saved(Request $request, BackupDestination $destination)
    {
        if ($request->input('after_save') === 'test') {
            return redirect()->route('admin.system.backup-destinations.edit', $destination)
                ->with(...$this->testResult($destination));
        }

        return $request->input('after_save') === 'close'
            ? redirect()->route('admin.system.backup-destinations.index')->with('status', __('Storage saved.'))
            : redirect()->route('admin.system.backup-destinations.edit', $destination)->with('status', __('Storage saved.'));
    }

    /**
     * [flash key, message]: `status`, `test_error`, or `host_key` — an SFTP
     * server met for the first time, its key in the session for the screen
     * to show beside a "Trust this key" button.
     *
     * @return array{0: string, 1: string}
     */
    protected function testResult(BackupDestination $destination, bool $saved = true): array
    {
        try {
            $error = app(Destinations::class)->test($destination);
        } catch (HostKeyNotConfirmed $e) {
            session()->flash('host_key_fingerprint', $e->fingerprint);

            return ['host_key', __('The server ":host" is met for the first time. Its key is shown below: compare it with the one your hosting gives, then trust it.', ['host' => $destination->setting('host')])];
        }

        $name = ['storage' => $destination->name];

        if ($error === null) {
            return ['status', $saved
                ? __('Saved. ":storage" works: a file was written, read back and deleted.', $name)
                : __('":storage" works: a file was written, read back and deleted.', $name)];
        }

        return ['test_error', $saved
            ? __('Saved, but ":storage" does not work: :error', $name + ['error' => $error])
            : __('":storage" does not work: :error', $name + ['error' => $error])];
    }
}
