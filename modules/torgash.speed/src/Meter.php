<?php

namespace Torgash\Speed;

use App\Core\Hooks\HookBus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides whether THIS request is measured, and owns its Profile while it is.
 *
 * A registered singleton that outlives requests on purpose — it is the switch
 * the listeners (bound once, at boot) look at — so everything about the
 * current request lives in `$profile`. A request is ARMED when it begins
 * (at boot for the first one, on the container rebinding `request` for any
 * later one — the moment `PerRequest` uses) and DECIDED at the route match,
 * the first moment the settings can be read.
 *
 * Unmeasured requests cost one setting read and one null check per query and
 * per hook call — never a Profile, never a write.
 */
class Meter
{
    protected ?Profile $profile = null;

    protected ?Attribution $attribution = null;

    protected bool $recording = false;

    /**
     * A request is under way — measured or not — until it terminates. The
     * container gets the `request` rebound more than once inside ONE request
     * (middleware replaces the instance), and starting over there would
     * measure a page from its middle, with the engine's start cut off.
     */
    protected bool $active = false;

    /**
     * When the request began, held until it can be decided whether to measure
     * it — which is at the route match: at provider boot the settings are not
     * readable yet, so deciding there decided "off" for every request.
     *
     * @var array{0: Request, 1: int, 2: float}|null
     */
    protected ?array $pending = null;

    public function __construct(protected HookBus $hooks) {}

    public function profile(): ?Profile
    {
        return $this->recording ? null : $this->profile;
    }

    /**
     * A new request begins. `$startNs` is when it began — the process start
     * for the first request (so booting the engine is counted), now for any
     * later one in a long-lived process. Nothing is decided yet — see $pending.
     */
    public function arm(Request $request, int $startNs, float $bootMs = 0): void
    {
        if ($this->active) {
            return;
        }

        $this->end();
        $this->active = true;
        $this->pending = [$request, $startNs, $bootMs];
    }

    /** Decide whether the armed request is measured, and start if it is. */
    protected function decide(): void
    {
        if ($this->pending === null) {
            return;
        }

        [$request, $startNs, $bootMs] = $this->pending;
        $this->pending = null;
        $this->begin($request, $startNs, $bootMs);
    }

    public function begin(Request $request, int $startNs, float $bootMs = 0): void
    {
        $this->end();

        if (! (bool) setting('torgash.speed', 'enabled')) {
            return;
        }

        // A random sample in a test run is a flaky test — QueryBudgetTest
        // counts queries, and one measured request in five would add to them.
        // A test that is about measuring says so.
        if (app()->runningUnitTests() && ! config('torgash.speed.measure_in_tests')) {
            return;
        }

        $requested = $this->requestedBy($request->ip());
        $percent = max(0, min(100, (int) setting('torgash.speed', 'sample_percent')));

        if (! $requested && ($percent === 0 || random_int(1, 100) > $percent)) {
            return;
        }

        $this->attribution ??= new Attribution;
        $this->profile = new Profile(
            startNs: $startNs,
            requested: $requested,
            attribution: $this->attribution,
            repeatThreshold: max(2, (int) setting('torgash.speed', 'repeat_threshold')),
        );
        $this->profile->bootMs = $bootMs;

        if ($bootMs > 0) {
            $this->profile->span('boot', 'Engine start', 0, $bootMs, 'core');
        }

        $this->hooks->observe($this->profile);
    }

    public function route(?string $name, ?string $uri, ?string $action): void
    {
        $this->decide();

        if (! $this->profile) {
            return;
        }

        $this->profile->route = $name ?: ($uri !== null ? '/'.ltrim($uri, '/') : null);
        $this->profile->owner = ($this->attribution ??= new Attribution)->ofAction($action);
    }

    /** The response is built: stop the clock. */
    public function handled(int $status): void
    {
        if (! $this->profile) {
            return;
        }

        $this->profile->endNs ??= hrtime(true);
        $this->profile->status = $status;
        $this->hooks->observe(null);
    }

    /**
     * After the response has gone. Never throws — a measurement that cannot be
     * written is not worth a failed request, and its own queries are not
     * measured (`recording` hides the profile from the listeners).
     */
    public function finish(Request $request): void
    {
        $profile = $this->profile;

        $this->active = false;
        $this->pending = null;

        if (! $profile || $profile->endNs === null) {
            $this->end();

            return;
        }

        $this->recording = true;
        $this->hooks->observe(null);

        try {
            app(Recorder::class)->record(
                $profile,
                self::zone($request->path()),
                '/'.ltrim($request->path(), '/'),
                max(50, (int) setting('torgash.speed', 'trace_threshold_ms')),
            );
        } catch (Throwable $e) {
            Log::warning('torgash.speed could not record a measurement: '.$e->getMessage());
        } finally {
            $this->recording = false;
            $this->end();
        }
    }

    public function end(): void
    {
        if ($this->profile) {
            $this->hooks->observe(null);
        }

        $this->profile = null;
    }

    /** Storefront, admin or API — three different audiences and budgets. */
    public static function zone(string $path): string
    {
        $path = ltrim($path, '/');

        return match (true) {
            $path === 'admin' || str_starts_with($path, 'admin/') => 'admin',
            str_starts_with($path, 'api/') => 'api',
            default => 'storefront',
        };
    }

    // ------------------------------------------------------ "trace my pages"

    /**
     * Measure — and keep the timeline of — every request from this address
     * for a while, whatever the sample says. Keyed by address because it has
     * to be decided before a session exists; anybody else behind the same
     * address is measured too for those minutes, which costs them nothing.
     *
     * Kept in a small FILE, not the cache: the cache here is the database,
     * and asking it on every request is a query per page to answer a
     * question that is nearly always "no". With nothing being traced the
     * file does not exist, and the whole check is one `is_file()`.
     */
    public function request(string $ip, int $minutes = 10): void
    {
        $all = $this->requestedAll();
        $all[$ip] = now()->addMinutes($minutes)->timestamp;

        $this->writeRequested($all);
    }

    public function requestedUntil(?string $ip): ?int
    {
        $until = $ip ? ($this->requestedAll()[$ip] ?? null) : null;

        return $until ? (int) $until : null;
    }

    public function stopRequest(?string $ip): void
    {
        $all = $this->requestedAll();
        unset($all[$ip]);

        $this->writeRequested($all);
    }

    public static function requestedFile(): string
    {
        return storage_path('framework/torgash-speed-trace.json');
    }

    protected function requestedBy(?string $ip): bool
    {
        return $this->requestedUntil($ip) !== null;
    }

    /** @return array<string, int> address => until, the expired ones dropped */
    protected function requestedAll(): array
    {
        $file = self::requestedFile();

        if (! is_file($file)) {
            return [];
        }

        $all = json_decode((string) @file_get_contents($file), true);

        return is_array($all) ? array_filter($all, fn ($until) => (int) $until > time()) : [];
    }

    /** @param  array<string, int>  $all */
    protected function writeRequested(array $all): void
    {
        $file = self::requestedFile();

        if ($all === []) {
            @unlink($file);

            return;
        }

        file_put_contents($file, json_encode($all), LOCK_EX);
    }
}
