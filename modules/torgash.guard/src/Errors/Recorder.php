<?php

namespace Torgash\Guard\Errors;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turning what the application logged into a row a shop owner will actually
 * see.
 *
 * Listening to `MessageLogged` rather than patching `bootstrap/app.php` is what
 * keeps this a MODULE: uninstall it and nothing in core has to be undone. It
 * also catches more than an exception handler would — a `Log::error()` somebody
 * wrote by hand in a job is exactly the kind of thing that otherwise dies in a
 * file nobody opens.
 *
 * Three rules, and every one of them is about not making a bad moment worse:
 *
 * 1. **It never throws.** Everything is wrapped, and a failure here is
 *    swallowed rather than reported — reporting it would log, which would
 *    arrive back here.
 * 2. **It cannot re-enter.** The flag is not belt-and-braces: the ordinary
 *    reason this fails is that the DATABASE is unavailable, and the write's own
 *    failure is logged, which would call this again, which would try the same
 *    write. That is an infinite loop on the one path where the shop is already
 *    in trouble.
 * 3. **It counts atomically.** One row per group with a counter means two
 *    requests failing in the same instant would race — `updateOrCreate` loses
 *    an increment or duplicates the row. Postgres' ON CONFLICT does it in one
 *    statement, which is why there is raw SQL here and nowhere else in the
 *    module.
 */
class Recorder
{
    /** Levels worth a row, in order — anything below the configured one is dropped. */
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    protected bool $recording = false;

    public function handle(MessageLogged $event): void
    {
        if ($this->recording) {
            return;
        }

        $this->recording = true;

        try {
            $this->record($event);
        } catch (Throwable) {
            // Deliberately silent. See rule 1: there is nowhere to report this
            // that does not lead back here.
        } finally {
            $this->recording = false;
        }
    }

    protected function record(MessageLogged $event): void
    {
        if (! self::enabled() || ! $this->levelCounts($event->level)) {
            return;
        }

        $exception = ($event->context['exception'] ?? null) instanceof Throwable
            ? $event->context['exception']
            : null;

        $message = trim((string) $event->message);

        if ($message === '' && ! $exception) {
            return;
        }

        if ($this->isIgnored($message, $exception)) {
            return;
        }

        $now = now();
        $origin = $exception ? Origin::of($exception) : null;

        $row = [
            'fingerprint' => Fingerprint::of($event->level, $message, $exception),
            'level' => $event->level,
            // The real failure's class — a template error is not a
            // "ViewException", it is whatever the template hit.
            'class' => $origin['class'] ?? null,
            // Trimmed: a message can be a whole SQL statement with bindings,
            // and the column is what a list prints.
            'message' => mb_substr($exception ? $exception->getMessage() : $message, 0, 2000),
            // Where in the shop's own code, not the framework line it was
            // thrown from (that one is kept in the context as `thrown_at`).
            'file' => $origin['file'] ?? null,
            'line' => $origin['line'] ?? null,
            'count' => 1,
            'context' => json_encode($this->context($event, $exception), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // One statement, so a hundred simultaneous failures are a hundred
        // increments rather than a race. `resolved_at` is cleared on purpose: a
        // problem somebody marked as dealt with that has just happened again is
        // not dealt with, and a list that keeps it hidden is a list that lies.
        DB::statement(
            'insert into m_guard_errors ('.implode(', ', array_keys($row)).')'
            .' values ('.implode(', ', array_fill(0, count($row), '?')).')'
            .' on conflict (fingerprint) do update set'
            .'   count = m_guard_errors.count + 1,'
            .'   last_seen_at = excluded.last_seen_at,'
            .'   updated_at = excluded.updated_at,'
            .'   message = excluded.message,'
            .'   context = excluded.context,'
            .'   resolved_at = null',
            array_values($row),
        );
    }

    /**
     * A sample of THIS occurrence — the last one wins, because "what was
     * happening the most recent time" is the question somebody debugging asks.
     *
     * The exception object itself is never stored: it holds models, closures and
     * a whole container, and json_encode over that is either a crash or a
     * megabyte. Only the parts a person reads.
     *
     * @return array<string, mixed>
     */
    protected function context(MessageLogged $event, ?Throwable $exception): array
    {
        $request = request();

        return array_filter([
            'url' => $request?->fullUrl(),
            'method' => $request?->method(),
            // Who was signed in, by id — not the name, which is a second copy
            // of something that can be renamed or deleted.
            'user_id' => auth()->hasUser() ? auth()->id() : null,
            'ip' => $request?->ip(),
            'thrown_at' => $exception ? $exception->getFile().':'.$exception->getLine() : null,
            'trace' => $exception ? $this->trace($exception) : null,
            // Whatever the caller passed, minus the exception — a hand-written
            // Log::warning() usually carries the useful half in here.
            'extra' => $this->extra($event->context),
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    /** The first frames, as strings. Enough to find it; not the whole stack. */
    protected function trace(Throwable $exception): array
    {
        return collect($exception->getTrace())
            ->take(12)
            ->map(fn (array $frame) => trim(sprintf(
                '%s:%s %s%s%s()',
                $frame['file'] ?? '?',
                $frame['line'] ?? '?',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '?',
            )))
            ->all();
    }

    /**
     * The caller's own context, reduced to what can safely be stored: scalars
     * only. An object in there is a model, a request or a container, and
     * following it is how a log row becomes a memory dump.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function extra(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if ($key === 'exception' || is_object($value) || is_array($value)) {
                continue;
            }

            $out[(string) $key] = is_scalar($value) || $value === null
                ? mb_substr((string) $value, 0, 500)
                : null;
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    /**
     * Noise the shop has told us to drop, matched anywhere in the message or in
     * the exception's class. One per line, because a settings textarea is what
     * an owner can edit and a regular expression is not.
     */
    protected function isIgnored(string $message, ?Throwable $exception): bool
    {
        $haystack = $message.' '.($exception ? $exception::class : '');

        foreach (preg_split('/\R+/', (string) setting('torgash.guard', 'errors_ignore', '')) ?: [] as $needle) {
            $needle = trim($needle);

            if ($needle !== '' && mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function levelCounts(string $level): bool
    {
        $minimum = (string) setting('torgash.guard', 'errors_level', 'error');

        $at = array_search($level, self::LEVELS, true);
        $floor = array_search($minimum, self::LEVELS, true);

        // An unknown level is recorded rather than dropped: a channel nobody
        // here has heard of is exactly the kind of thing worth seeing once.
        return $at === false || $floor === false || $at >= $floor;
    }

    public static function enabled(): bool
    {
        return (bool) setting('torgash.guard', 'errors_enabled', true);
    }
}
