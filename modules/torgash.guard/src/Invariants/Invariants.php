<?php

namespace Torgash\Guard\Invariants;

use App\Core\Modules\ModuleManager;
use Throwable;
use Torgash\Guard\Models\CheckState;
use Torgash\Guard\Models\Finding;

/**
 * Statements about a shop that must be true, and the machinery that goes and
 * looks.
 *
 * PLAN §25.2/3, and the argument for it is the promotions review of
 * 2026-09-07: every bug found there had left DATA saying so — an order totalling
 * −20.00, a stock of −1, a manager's price in a public feed. Nobody was
 * looking. This is the looking.
 *
 * **A statement in words, not a query with a name.** Each invariant is a
 * sentence the shop's owner can read — "an order's total is its lines, less the
 * discount, plus delivery and taxes" — with a second sentence saying what to do
 * when it is false. That is the whole difference between this and "anomaly
 * detection": every rule here is explicable, and every failure has an obvious
 * next move. A rule nobody can act on is a rule that gets ignored, and then so
 * are the ones next to it.
 *
 * **The module that owns the data states what is true about it.** This class is
 * only the engine — the registry, the running, the storing, the screen and the
 * schedule. Checkout says what must be true of an order, warehouses of a stock
 * row, promotions of a counter. Answering `guard.invariants` costs a module
 * nothing when this module is not installed (nobody ever fires the hook), and it
 * is the only arrangement where a module somebody else writes can be watched at
 * all — which is the point, since a third-party module breaks invariants first
 * and cannot be covered by our tests.
 *
 * The exceptions this module ships itself are about CORE's own data, which has
 * no module to speak for it.
 *
 * **Every check is bounded.** The engine hands each one a limit and each one
 * respects it, because this runs over the whole shop on a timer. A check that
 * cannot be a single query bounds its own scan and says so in its own sentence —
 * an invariant that must read every order in PHP is a report, not a watch.
 *
 * A declaration:
 *
 *     'checkout.order_total_negative' => [
 *         'label' => 'No order has a negative total',   // English, translated by lang files
 *         'explain' => 'Open the order and …',
 *         'severity' => 'error',           // error | warning
 *         'manual' => false,               // true: only ever run by its own button
 *         'check' => fn (int $limit) => iterable of violations,
 *     ]
 *
 * A violation: `['id' => …, 'title' => …, 'detail' => …, 'url' => …]`, where
 * title and detail are either a plain value (an order number) or a translation
 * key with its parameters: `['Stored :stored, should be :expected', [...]]`.
 * Never a finished sentence — see text().
 */
class Invariants
{
    /** What a check is asked for at most, when the shop has said nothing. */
    public const DEFAULT_LIMIT = 50;

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $declared = hook_filter('guard.invariants', []);

        return array_map(fn (array $one) => $one + [
            'label' => 'Unnamed check',
            'explain' => [],
            'severity' => 'error',
            // A check nobody may run unasked. One exists so far: the uploads
            // self-test asks the shop for a page over HTTP, and a server with a
            // single FastCGI worker would be putting a question to itself that
            // it cannot answer until it times out.
            'manual' => false,
            'check' => fn () => [],
        ], $declared);
    }

    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The statements bucketed by whoever made them, for a screen that now has
     * more than thirty of them.
     *
     * The bucket is the part of the key before the dot, which is not a
     * convention invented here: a statement is named `<owner>.<sentence>`
     * precisely because the owner of the data is the one who states it, so the
     * key already says who to go and ask. The label is that module's own name
     * where a module of that name is installed (`torgash.shipping` →
     * "Delivery"), and the bare word otherwise — `core` has no module, and a
     * third-party module free to name its keys as it likes still gets a heading
     * rather than being dropped.
     *
     * @return array<string, array{label: string, checks: array<string, array<string, mixed>>}>
     */
    public function grouped(): array
    {
        $modules = app(ModuleManager::class)->all();
        $groups = [];

        foreach ($this->all() as $key => $check) {
            $owner = str_contains($key, '.') ? strstr($key, '.', true) : 'other';

            // Through __(): a manifest's name is one English string, and the
            // module translates its own name in its own lang file — which is
            // the only place that ownership belongs.
            $groups[$owner]['label'] ??= __($modules['torgash.'.$owner]->name ?? ucfirst($owner));
            $groups[$owner]['checks'][$key] = $check;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Run one check, or all of them.
     *
     * @return int the number of things found to be wrong
     */
    public function run(?string $key = null): int
    {
        // Asked for by name, anything runs — including the manual ones, which
        // is what their own button does. Asked for wholesale, they are left
        // alone.
        $checks = $key === null
            ? array_filter($this->all(), fn (array $one) => ! $one['manual'])
            : array_filter($this->all(), fn ($k) => $k === $key, ARRAY_FILTER_USE_KEY);

        $found = 0;

        foreach ($checks as $name => $check) {
            $found += $this->runOne($name, $check);
        }

        return $found;
    }

    protected function runOne(string $key, array $check): int
    {
        $limit = max(1, (int) setting('torgash.guard', 'invariants_limit', self::DEFAULT_LIMIT));
        $started = microtime(true);

        try {
            $violations = $this->collect($check['check'], $limit);
        } catch (Throwable $e) {
            // Recorded, not swallowed — and the existing findings are left
            // alone. A check that threw knows nothing, including whether what
            // it reported last night has been put right since.
            CheckState::updateOrCreate(['key' => $key], [
                'last_run_at' => now(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'error' => mb_substr($e->getMessage(), 0, 250),
            ]);

            return 0;
        }

        $truncated = count($violations) >= $limit;
        $seen = [];

        foreach ($violations as $violation) {
            $id = (string) ($violation['id'] ?? 'shop');
            $seen[] = $id;

            [$title, $titleParams] = $this->text($violation['title'] ?? $id);
            [$detail, $detailParams] = $this->text($violation['detail'] ?? null);

            $finding = Finding::firstOrNew(['key' => $key, 'entity_id' => $id]);
            $finding->title = mb_substr((string) $title, 0, 250);
            $finding->title_params = $titleParams;
            $finding->detail = $detail === null ? null : mb_substr((string) $detail, 0, 250);
            $finding->detail_params = $detailParams;
            $finding->url = $violation['url'] ?? null;
            $finding->first_seen_at ??= now();
            $finding->last_seen_at = now();
            $finding->save();
        }

        // Whatever is no longer wrong stops being on the list, because that is
        // what a STATE means — nobody has to tick it off. But only when we
        // actually saw everything: a run that stopped at its limit does not
        // know what is beyond it, and deleting on that basis would clear
        // problems it never looked at.
        if (! $truncated) {
            Finding::where('key', $key)->whereNotIn('entity_id', $seen ?: ['—'])->delete();
        }

        CheckState::updateOrCreate(['key' => $key], [
            'last_run_at' => now(),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'violations' => count($violations),
            'truncated' => $truncated,
            'error' => null,
        ]);

        return count($violations);
    }

    /**
     * A check says what is wrong as a translation KEY plus its parameters —
     * `['Stored :stored, should be :expected', [...]]` — and never as a
     * finished sentence, because the nightly run happens in the application's
     * default language while the person reading the screen may be in another
     * one. That is the same rule the background tasks already live by.
     *
     * A plain string is a value rather than a sentence (an order number, an
     * sku): stored as-is, with no parameters, and `__()` hands it straight
     * back for want of a translation.
     *
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    protected function text(mixed $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        if (is_array($value)) {
            return [(string) ($value[0] ?? ''), (array) ($value[1] ?? [])];
        }

        return [(string) $value, null];
    }

    /**
     * A check may return an array, a generator or a query result; the limit is
     * enforced HERE as well as by the check, so a module that forgets it cannot
     * fill the table.
     */
    protected function collect(callable $check, int $limit): array
    {
        $out = [];

        foreach ($check($limit) as $violation) {
            $out[] = $violation;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Findings by invariant key, for the screen. */
    public function findings(): array
    {
        return Finding::orderBy('key')->orderByDesc('last_seen_at')->get()->groupBy('key')->all();
    }

    /** @return array<string, CheckState> */
    public function states(): array
    {
        return CheckState::all()->keyBy('key')->all();
    }

    /**
     * A declared label (English, or an older {en, ru} map) in the reader's language — the
     * same fall-through the rest of the engine uses: this locale, then English,
     * then nothing rather than a stray key.
     *
     * @param  array<string, string>|string  $text
     */
    public static function localized(array|string $text, string $fallback = ''): string
    {
        // A plain string is the declared English sentence and goes through the
        // lang files like every other declared word (ADR-005) — returning it
        // as-is printed English on a Russian screen.
        return label_text($text, null, $fallback);
    }
}
