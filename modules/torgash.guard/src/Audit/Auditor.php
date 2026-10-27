<?php

namespace Torgash\Guard\Audit;

use App\Core\Tasks\CurrentTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;
use Torgash\Guard\Models\AuditEntry;

/**
 * The trail: one row for every change made through the admin, with who made it
 * and what it was before.
 *
 * Three decisions hold this together, and each is the answer to a way the
 * obvious version goes wrong.
 *
 * **It listens to ELOQUENT, not to controllers.** A middleware could record
 * "somebody posted to /admin/products/12" for a tenth of the code, and it would
 * answer none of the questions this exists for: which field, from what, to
 * what. Model events also mean a module written by somebody else is audited the
 * day it is installed, without knowing this module exists — which matters more
 * here than anywhere, since a third-party module is exactly the thing whose
 * writes nobody else is watching.
 *
 * **It is armed by the ADMIN, and only there.** Not "always on": the storefront
 * writes carts, sessions and orders on every visit, and an audit that records
 * them is a copy of the shop's traffic nobody will ever read. Not the console
 * either — a queue worker and an artisan command have no actor, and an entry
 * with nobody in it is a log line pretending to be a trail. So: an
 * authenticated request under /admin or /api/v2/admin, which is exactly what
 * "an action in the admin" means, the API's door included (a call there IS a
 * logged-in user, so "who" has a real answer) — and a background task SOMEBODY
 * STARTED there (core's `CurrentTask`): renumbering forty thousand SKUs is
 * that person's act as much as one save is, written with the task beside it.
 *
 * **It audits by DEFAULT, minus an ignore list** — the inverse of the display
 * rules' fail-closed, and for the same underlying reason: this is the safe
 * direction here. A registry of audited models would leave every table nobody
 * remembered to register silently unwatched, and "silently unwatched" is the
 * failure this part of the module exists to end.
 *
 * Like the error recorder beside it, it never throws: a trail that can break
 * the save it describes is worse than no trail.
 */
class Auditor
{
    /** The model events worth a row. `saving`/`saved` would double every one. */
    public const EVENTS = ['created', 'updated', 'deleted', 'restored'];

    /**
     * Tables whose writes are machinery, not decisions. Its own two are here
     * for the obvious reason; the rest change on their own, without anybody
     * having done anything.
     */
    public const IGNORED_TABLES = [
        // Its own four: pressing "Check now" would otherwise write an audit
        // entry per finding, which is the trail recording itself looking.
        'm_guard_audit', 'm_guard_errors', 'm_guard_findings', 'm_guard_checks',
        'background_tasks', 'jobs', 'job_batches', 'failed_jobs',
        'sessions', 'cache', 'cache_locks', 'notifications',
        'password_reset_tokens', 'personal_access_tokens',
    ];

    /**
     * Attributes that change on every save and say nothing by changing.
     * `last_used_at` is here rather than its whole table being ignored: issuing
     * and revoking an API token is worth a row, a call made with one is not.
     */
    public const IGNORED_ATTRIBUTES = [
        'created_at', 'updated_at', 'last_used_at', 'remember_token',
        'generated_conversions',
    ];

    /**
     * Anything whose NAME says it is a credential. The field is recorded, the
     * value never is: an audit trail must not become the second place the
     * gateway's secret is written down — and the first one is at least
     * encrypted.
     *
     * `recovery` was added with the second factor for exactly that reason: a
     * manager turning two-factor authentication off is worth a row, and the
     * eight codes it took away are not something to copy into a table that is
     * kept for six months.
     */
    protected const SECRET = '/pass|secret|token|api_?key|private|credential|signature|recovery/i';

    /** How much of one value is kept. A product's description is not a diff. Public: `Revert` tells a cut value by it. */
    public const MAX_VALUE = 300;

    /** What a credential's value is stored as. Public for the same reason. */
    public const MASK = '••••••';

    protected bool $recording = false;

    /**
     * The request the three counters below belong to. A singleton outlives the
     * request under a persistent worker — and in the test suite, where one
     * application serves many — so "have I already decided this" has to be
     * asked about a REQUEST, never remembered in a plain flag. That is the same
     * trap the project's per-request memos are written against.
     */
    protected ?Request $current = null;

    protected bool $inAdmin = false;

    protected int $written = 0;

    protected bool $capped = false;

    /** The task the counters above belong to while one runs, and who started it. */
    protected ?int $taskId = null;

    protected ?User $taskUser = null;

    /**
     * The wildcard listeners' one entry point.
     *
     * Everything is wrapped: the write happens inside somebody else's save, and
     * a broken audit row must not take an order down with it.
     */
    public function model(string $action, Model $model): void
    {
        if ($this->recording) {
            return;
        }

        $this->recording = true;

        try {
            $this->record($action, $model);
        } catch (Throwable) {
            // Deliberately silent; see the class docblock.
        } finally {
            $this->recording = false;
        }
    }

    protected function record(string $action, Model $model): void
    {
        if (! $this->armed() || in_array($model->getTable(), $this->ignoredTables(), true)) {
            return;
        }

        $changes = $this->diff($action, $model);

        // A save that touched nothing but a timestamp is not a change anybody
        // asked about, and a list full of those is a list nobody reads.
        if ($changes === [] && $action === 'updated') {
            return;
        }

        $this->write([
            'action' => $action,
            'entity_type' => $model::class,
            'entity_id' => (string) $model->getKey(),
            'entity_title' => $this->title($model),
            'changes' => $changes ?: null,
        ]);
    }

    /**
     * The other half of the trail: acts that are not a model write at all.
     *
     * Settings and the module list are the two the engine keeps in plain tables
     * (`DB::table`, no Eloquent, no events), and they are among the most
     * audit-worthy things in the admin — "who turned stock control off", "who
     * uninstalled payments". Core announces both; this listens.
     */
    public function note(string $action, string $type, string $id, ?string $title = null, array $changes = []): void
    {
        if ($this->recording || ! $this->armed()) {
            return;
        }

        $this->recording = true;

        try {
            $this->write([
                'action' => $action,
                'entity_type' => $type,
                'entity_id' => mb_substr($id, 0, 190),
                'entity_title' => $title ? mb_substr($title, 0, 250) : null,
                'changes' => $this->clean($changes) ?: null,
            ]);
        } catch (Throwable) {
            // As above.
        } finally {
            $this->recording = false;
        }
    }

    /**
     * Whether anything should be recorded at all for this request.
     *
     * The path is what says "an action in the admin", and it is also what keeps
     * the console out without a word about the console: an artisan command and
     * a queue worker have a request whose path is `/`, so neither is ever in
     * the admin. The user is asked every time rather than remembered — the
     * first model event of a request can easily fire before the guard has
     * resolved anybody.
     */
    protected function armed(): bool
    {
        if (! self::enabled()) {
            return false;
        }

        if ($task = app(CurrentTask::class)->task()) {
            if ($this->taskId !== $task->id) {
                $this->taskId = $task->id;
                $this->taskUser = $task->user_id ? User::find($task->user_id) : null;
                $this->current = null;
                $this->written = 0;
                $this->capped = false;
            }

            // Nobody started it (the scheduler): no actor, no entry.
            return $this->taskUser !== null && ! $this->capped;
        }

        if ($this->taskId !== null) {
            $this->taskId = null;
            $this->taskUser = null;
            $this->current = null;
        }

        $request = request();

        if (! $request instanceof Request) {
            return false;
        }

        if ($this->current !== $request) {
            $this->current = $request;
            $this->inAdmin = $request->is('admin', 'admin/*', 'api/v2/admin/*');
            $this->written = 0;
            $this->capped = false;
        }

        return $this->inAdmin && ! $this->capped && auth()->hasUser();
    }

    protected function write(array $row): void
    {
        $task = $this->taskId !== null ? app(CurrentTask::class)->task() : null;
        $max = $task ? (int) config('guard.audit.max_per_task', 200000) : (int) config('guard.audit.max_per_request', 200);

        if ($max > 0 && $this->written >= $max) {
            // Said out loud rather than dropped in silence. One admin action
            // CAN legitimately change thousands of rows (a bulk price edit),
            // and the honest record of that is "this is where I stopped", not a
            // trail that quietly has holes in it.
            $this->capped = true;

            $row = [
                'action' => 'truncated',
                'entity_type' => null,
                'entity_id' => null,
                'entity_title' => null,
                'changes' => ['limit' => ['to' => $max]],
            ];
        }

        // A task's rows have no request of their own: the worker's is `/`.
        $request = $task ? null : request();
        $user = $task ? $this->taskUser : auth()->user();

        AuditEntry::create($row + [
            'user_id' => $user?->getKey(),
            // The name as it was, so the trail still reads after the account is
            // renamed or deleted.
            'user_name' => $user?->name,
            // The task's English name, as the tasks list keeps it — read through __().
            'task_id' => $task?->id,
            'task_name' => $task?->name,
            'route' => $request?->route()?->getName(),
            'url' => $request ? mb_substr((string) $request->fullUrl(), 0, 2000) : null,
            'method' => $request?->method(),
            'ip' => $request?->ip(),
            'created_at' => now(),
        ]);

        $this->written++;
    }

    /**
     * What changed, in one shape for every action: `{field: {from, to}}`, with
     * the side that does not exist simply absent.
     *
     * A creation and a deletion are recorded as a bounded snapshot of the row —
     * for a deletion that snapshot is the only surviving copy, which is the
     * single most useful thing this table ever holds. A restore says only that
     * it happened: the row is back and can be read.
     */
    protected function diff(string $action, Model $model): array
    {
        if ($action === 'updated') {
            $out = [];

            foreach (array_keys($model->getChanges()) as $key) {
                if ($this->skipped($key, 'updated')) {
                    continue;
                }

                // Raw on both sides on purpose: getOriginal() applies casts and
                // getChanges() does not, so an array-cast field would come out
                // as an array against a JSON string and read as a change of
                // kind rather than of value.
                $out[$key] = [
                    'from' => $this->value($key, $model->getRawOriginal($key)),
                    'to' => $this->value($key, $model->getAttributes()[$key] ?? null),
                ];
            }

            return $out;
        }

        if (! in_array($action, ['created', 'deleted'], true)) {
            return [];
        }

        $side = $action === 'deleted' ? 'from' : 'to';
        $out = [];

        foreach ($model->getAttributes() as $key => $value) {
            if ($this->skipped($key)) {
                continue;
            }

            $out[$key] = [$side => $this->value($key, $value)];
        }

        return $out;
    }

    /** The same treatment for a diff handed in from outside — a setting's value. */
    protected function clean(array $changes): array
    {
        $out = [];

        foreach ($changes as $key => $sides) {
            $out[$key] = collect((array) $sides)
                ->map(fn ($value) => $this->value((string) $key, $value))
                ->all();
        }

        return $out;
    }

    /**
     * `created_at` is ignored as a TIMESTAMP and kept as a DECISION.
     *
     * It says nothing when a row is created and nothing when a save stamps it,
     * which is why it is on the ignore list at all. But an UPDATE that moves it
     * is somebody typing a date into a form — the catalogue has such a field,
     * because "Newest first" sorts by it — and that is a change to what the
     * shop shows first, made by a person, which is exactly what this trail is
     * for. Recorded on `updated` only, so a created row still does not carry a
     * pointless entry for the second it was made in.
     */
    protected function skipped(string $key, string $action = ''): bool
    {
        if ($key === 'created_at') {
            return $action !== 'updated';
        }

        return in_array($key, self::IGNORED_ATTRIBUTES, true);
    }

    /**
     * The machinery above, the shop's own list, and whatever a module says is
     * ALREADY a log — `guard.audit.ignored_tables`, answered by the owner of
     * the table (an order's history, a rule's runs, an exchange session): a
     * log recorded again in the trail is the same event twice, and it is
     * what split one order save into eight lines.
     *
     * @return array<int, string>
     */
    protected function ignoredTables(): array
    {
        return array_merge(self::IGNORED_TABLES, (array) hook_filter('guard.audit.ignored_tables', []), (array) config('guard.audit.ignore', []));
    }

    /**
     * One value, made safe to store: masked if its name says credential,
     * shortened if it is a description, left alone if it is a number.
     * Public so `Revert` can put a CURRENT value into the same shape and
     * compare it with what the trail says the change left behind.
     */
    public function value(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (preg_match(self::SECRET, $key)) {
            return self::MASK;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        // A credential is not always a column of its own: a payment gateway's
        // whole configuration is one jsonb field called `config`, with the
        // secret inside it. Masking only by the outer name would have written
        // it out in full — which is the one thing this class must never do.
        if (is_array($value)) {
            $value = json_encode($this->mask($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_string($value) && str_starts_with(ltrim($value), '{')) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $value = json_encode($this->mask($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        $value = (string) $value;

        return mb_strlen($value) > self::MAX_VALUE
            ? mb_substr($value, 0, self::MAX_VALUE).'…'
            : $value;
    }

    /** The same rule applied inside a structure, at any depth. */
    protected function mask(array $value): array
    {
        foreach ($value as $key => $inner) {
            if (is_array($inner)) {
                $value[$key] = $this->mask($inner);
            } elseif ($inner !== null && $inner !== '' && preg_match(self::SECRET, (string) $key)) {
                $value[$key] = self::MASK;
            }
        }

        return $value;
    }

    /**
     * What the row was called. Tried in the order a person would say it out
     * loud, and translatable fields are unwrapped — `{"en":"Boots"}` printed
     * back at somebody is not a name.
     */
    protected function title(Model $model): ?string
    {
        $attributes = $model->getAttributes();

        foreach (['order_number', 'number', 'name', 'title', 'sku', 'code', 'slug', 'email', 'label'] as $key) {
            $value = $attributes[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            // A translatable column arrives as its jsonb text — `{"en": …}`,
            // or `[]` when nothing was written in any language, which is no
            // title rather than the two characters "[]".
            if (is_string($value) && preg_match('/^\s*[\[{]/', $value)) {
                $decoded = json_decode($value, true);

                if (is_array($decoded)) {
                    $value = pick_translation($decoded);
                }
            }

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 250);
            }
        }

        return null;
    }

    public static function enabled(): bool
    {
        return (bool) config('guard.audit.enabled', true);
    }
}
