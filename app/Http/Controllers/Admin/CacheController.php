<?php

namespace App\Http\Controllers\Admin;

use App\Core\Performance\CacheSetup;
use App\Core\Support\EnvFile;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Admin → Cache and sessions: where each lives (a folder, the database,
 * Redis), a check that writes and reads back, and the button that clears the
 * cache. Measuring what is slow is the speed module's screen; this one is
 * core's, because a shop without that module still has a cache.
 *
 * A choice is saved only after it was tried: every store a save would put in
 * force is written to and read back first, with the Redis connection AS
 * TYPED — so a wrong password is a red line on this screen, not a shop that
 * has quietly fallen back to the database. Moving sessions hands the current
 * one to the new place in the same request, so the person pressing Save stays
 * signed in; everybody else starts a new session, which the form says before
 * it is pressed.
 */
class CacheController extends Controller
{
    public function index(CacheSetup $setup, EnvFile $env)
    {
        return view('admin.cache.index', [
            'current' => $setup->current(),
            'redis' => $setup->redis(),
            'redisClient' => $setup->redisClientAvailable(),
            'outage' => $setup->lastOutage(),
            'entries' => $setup->entries(),
            'writable' => $env->writable(),
            'cached' => app()->configurationIsCached(),
            'probes' => session('probes'),
            'lines' => session('lines'),
        ]);
    }

    public function update(Request $request, CacheSetup $setup, EnvFile $env)
    {
        $data = $request->validate([
            'cache' => ['required', Rule::in(CacheSetup::CHOICES)],
            'sessions' => ['required', Rule::in(CacheSetup::CHOICES)],
            'redis_host' => ['nullable', 'string', 'max:255'],
            'redis_port' => ['nullable', 'integer', 'between:1,65535'],
            'redis_password' => ['nullable', 'string', 'max:500'],
            'redis_clear_password' => ['nullable', 'boolean'],
            'action' => ['required', Rule::in(['check', 'save'])],
        ]);

        $redis = [
            'host' => $data['redis_host'] ?? null,
            'port' => $data['redis_port'] ?? null,
            'password' => $data['redis_password'] ?? null,
            'clear_password' => (bool) ($data['redis_clear_password'] ?? false),
        ];
        $typedRedis = [...$redis, 'password' => $redis['clear_password'] ? null : $redis['password']];

        $probes = [];
        foreach (array_unique([$data['cache'], $data['sessions']]) as $choice) {
            $probes[$choice] = $setup->probe($choice, $choice === 'redis' ? $typedRedis : null);
        }

        $back = redirect()->route('admin.cache')->withInput($request->except('redis_password'))->with('probes', $probes);

        if ($data['action'] === 'check') {
            return $back;
        }

        if (collect($probes)->contains(fn ($probe) => ! $probe['ok'])) {
            return $back->with('error', __('Nothing was saved: a place you chose did not answer.'));
        }

        $values = $setup->envFor($data['cache'], $data['sessions'], $redis);

        if (! $env->writable()) {
            return $back->with('lines', $values)->with('error', __('The .env file cannot be written — put these lines into it by hand.'));
        }

        $before = $setup->current();

        try {
            $setup->save($values);
        } catch (Throwable $e) {
            return $back->with('lines', $values)->with('error', $e->getMessage());
        }

        if ($before['sessions'] !== $data['sessions']) {
            $this->carrySession($request, $values);
        }

        return redirect()->route('admin.cache')->with('status', __('Saved. The next page is served with the new settings.'));
    }

    public function clear(Request $request, CacheSetup $setup)
    {
        $what = $request->validate(['what' => ['required', Rule::in(['cache', 'views'])]])['what'];

        try {
            if ($what === 'cache') {
                $setup->flush();
                $message = __('The cache is cleared.');
            } else {
                Artisan::call('view:clear');
                $message = __('Compiled templates are cleared; each is compiled again when first shown.');
            }
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $message);
    }

    /**
     * Writes THIS session to where sessions now live, so the person who moved
     * them is not signed out by their own Save. The request's session store is
     * saved at the end of the request as usual — only its handler changes.
     *
     * @param  array<string, string>  $values  what was written to .env
     */
    protected function carrySession(Request $request, array $values): void
    {
        foreach (['cache', 'session', 'default'] as $connection) {
            foreach (['REDIS_HOST' => 'host', 'REDIS_PORT' => 'port', 'REDIS_PASSWORD' => 'password'] as $key => $field) {
                if (array_key_exists($key, $values)) {
                    config(["database.redis.{$connection}.{$field}" => $values[$key] === 'null' ? null : $values[$key]]);
                }
            }
        }

        config(['session.driver' => $values['SESSION_DRIVER']]);

        if (app()->resolved('redis')) {
            foreach (['cache', 'session', 'default'] as $connection) {
                app('redis')->purge($connection);
            }
        }

        try {
            $request->session()->setHandler(app('session')->driver($values['SESSION_DRIVER'])->getHandler());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
