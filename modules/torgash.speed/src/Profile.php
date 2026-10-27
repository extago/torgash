<?php

namespace Torgash\Speed;

use App\Core\Hooks\HookObserver;

/**
 * Everything measured about ONE request, while it runs.
 *
 * It is the hook bus's observer, and that is where the module attribution
 * comes from: a stack of listener frames, each subtracting its children, gives
 * every module its OWN time — a catalogue listener that fires a hook answered
 * by promotions is not charged for promotions' work. A query or an outside
 * call made while a frame is open is charged to that frame's module as well.
 *
 * Time is `hrtime()` nanoseconds from the request's start; the breakdown is
 * built so its parts are disjoint and add up to the total:
 *   boot + database + outside calls + module hooks (their own time, minus the
 *   database and calls inside them) + the rest (controller, templates, PHP).
 */
class Profile implements HookObserver
{
    public const MAX_SPANS = 400;

    public ?string $route = null;

    public ?string $owner = null;

    public int $status = 0;

    public float $bootMs = 0;

    public ?int $endNs = null;

    public float $dbMs = 0;

    public int $queries = 0;

    public float $httpMs = 0;

    public int $httpCalls = 0;

    /** @var array<string, array{ms: float, db_ms: float, calls: int}> module => own time */
    public array $modules = [];

    /** @var array<string, array{shape: string, count: int, ms: float, max: float, caller: ?string}> */
    public array $queryShapes = [];

    /** @var list<array{0: string, 1: string, 2: float, 3: float, 4: string}> type, label, start ms, ms, module */
    public array $spans = [];

    /** @var list<array{hook: string, module: string, start: int, children: int, db: float, http: float}> */
    protected array $stack = [];

    /** @var array<int, int> spl_object_id(request) => start ns, for outside calls */
    protected array $pendingHttp = [];

    protected float $hooksDbMs = 0;

    protected float $hooksHttpMs = 0;

    protected float $hooksOwnMs = 0;

    public function __construct(
        public readonly int $startNs,
        public readonly bool $requested,
        protected Attribution $attribution,
        protected int $repeatThreshold,
    ) {}

    public function now(): float
    {
        return (hrtime(true) - $this->startNs) / 1e6;
    }

    // ------------------------------------------------------------------ hooks

    public function enter(string $hook, callable $listener): void
    {
        $this->stack[] = [
            'hook' => $hook,
            'module' => $this->attribution->of($listener),
            'start' => hrtime(true),
            'children' => 0,
            'db' => 0.0,
            'http' => 0.0,
        ];
    }

    public function leave(string $hook, callable $listener): void
    {
        $frame = array_pop($this->stack);

        if ($frame === null) {
            return;
        }

        $duration = hrtime(true) - $frame['start'];
        $ownMs = ($duration - $frame['children']) / 1e6;

        if ($this->stack !== []) {
            $this->stack[array_key_last($this->stack)]['children'] += $duration;
        }

        $module = $frame['module'];
        $this->modules[$module] ??= ['ms' => 0.0, 'db_ms' => 0.0, 'calls' => 0];
        $this->modules[$module]['ms'] += $ownMs;
        $this->modules[$module]['db_ms'] += $frame['db'];
        $this->modules[$module]['calls']++;

        $this->hooksOwnMs += $ownMs;

        // Only listeners worth a line on a timeline — a page fires thousands
        // of hooks, nearly all of them free.
        if ($duration >= 500_000) {
            $this->span('hook', $hook, ($frame['start'] - $this->startNs) / 1e6, $duration / 1e6, $module);
        }
    }

    // --------------------------------------------------------------- database

    public function query(string $sql, float $ms): void
    {
        $this->dbMs += $ms;
        $this->queries++;

        $module = $this->chargeToFrame('db', $ms);

        $shape = Sql::shape($sql);
        $key = Sql::fingerprint($shape);
        $entry = $this->queryShapes[$key] ?? ['shape' => $shape, 'count' => 0, 'ms' => 0.0, 'max' => 0.0, 'caller' => null];
        $entry['count']++;
        $entry['ms'] += $ms;
        $entry['max'] = max($entry['max'], $ms);

        // Where it was asked from costs a backtrace, so it is taken only when
        // the answer will be read: the shape repeating (the N+1 suspect,
        // caught on its second run — the first is innocent) or one slow run.
        if ($entry['caller'] === null && ($entry['count'] === 2 || $ms >= 100)) {
            $entry['caller'] = Sql::caller();
        }

        $this->queryShapes[$key] = $entry;
        $this->span('query', $shape, $this->now() - $ms, $ms, $module);
    }

    // ---------------------------------------------------------- outside calls

    public function httpStart(object $request): void
    {
        $this->pendingHttp[spl_object_id($request)] = hrtime(true);
    }

    public function httpEnd(object $request, string $label): void
    {
        $start = $this->pendingHttp[spl_object_id($request)] ?? null;
        unset($this->pendingHttp[spl_object_id($request)]);

        if ($start === null) {
            return;
        }

        $ms = (hrtime(true) - $start) / 1e6;
        $this->httpMs += $ms;
        $this->httpCalls++;
        $module = $this->chargeToFrame('http', $ms);
        $this->span('http', $label, ($start - $this->startNs) / 1e6, $ms, $module);
    }

    // ---------------------------------------------------------------- results

    public function totalMs(): float
    {
        return (($this->endNs ?? hrtime(true)) - $this->startNs) / 1e6;
    }

    /**
     * The disjoint parts of the total. Database and outside calls are counted
     * once wherever they happened; module hooks are their own time with those
     * taken out; the rest is everything nobody above claimed.
     *
     * @return array{boot: float, db: float, http: float, hooks: float, rest: float}
     */
    public function breakdown(): array
    {
        $hooks = max(0.0, $this->hooksOwnMs - $this->hooksDbMs - $this->hooksHttpMs);
        $total = $this->totalMs();

        return [
            'boot' => $this->bootMs,
            'db' => $this->dbMs,
            'http' => $this->httpMs,
            'hooks' => $hooks,
            'rest' => max(0.0, $total - $this->bootMs - $this->dbMs - $this->httpMs - $hooks),
        ];
    }

    /** Shapes asked at least `repeat_threshold` times in this one request. */
    public function repeated(): array
    {
        return array_filter($this->queryShapes, fn ($q) => $q['count'] >= $this->repeatThreshold);
    }

    // ---------------------------------------------------------------- helpers

    /** Charge time to the open listener, and say whose it was. */
    protected function chargeToFrame(string $kind, float $ms): string
    {
        if ($this->stack === []) {
            return $this->owner ?? 'core';
        }

        $top = array_key_last($this->stack);
        $this->stack[$top][$kind] += $ms;

        // Whatever the frames above it, the time is inside a hook exactly once.
        if ($kind === 'db') {
            $this->hooksDbMs += $ms;
        } else {
            $this->hooksHttpMs += $ms;
        }

        return $this->stack[$top]['module'];
    }

    public function span(string $type, string $label, float $start, float $ms, string $module): void
    {
        if (count($this->spans) < self::MAX_SPANS) {
            $this->spans[] = [$type, mb_substr($label, 0, 300), round(max(0, $start), 3), round($ms, 3), $module];
        }
    }
}
