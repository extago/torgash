<?php

namespace App\Core\PageCache;

use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;
use WeakMap;

/**
 * What throws kept pages away (PLAN §61): every statement that writes.
 *
 * Not a list of "product saved", "menu saved", "setting saved" events that
 * a module could forget to announce — the statement itself. A write to a
 * table nobody declared invisible on pages throws every copy away; the
 * tables that are (orders, baskets, logs, sessions) are declared by their
 * owners through `PageCache::unseen()`, and an UPDATE touching only the
 * columns declared invisible (a product's view counter) throws nothing.
 * Throwing everything away is a counter bumped (PageCache::flush), so
 * erring that way costs only the next pages' drawing.
 *
 * Applied once the transaction COMMITS — before it, another request would
 * draw the page from the rows as they still are and keep it — and the
 * same for what modules throw away by tag (`PageCache::discard`). What a
 * transaction pended is its own: rolled back (a savepoint too), it goes
 * with the transaction — this object lives as long as the worker, and a
 * reason left behind would turn the next tag into everything.
 */
class Writes
{
    /** @var WeakMap<DatabaseTransactionRecord, object{all: array<string, true>, tags: array<string, true>}> what each open transaction throws away once it commits */
    protected WeakMap $waiting;

    protected bool $applying = false;

    public function __construct(protected PageCache $cache)
    {
        $this->waiting = new WeakMap;
    }

    public function saw(QueryExecuted $query): void
    {
        if ($this->applying) {
            return;
        }

        $write = $this->parse($query->sql);

        if ($write === null || ! $this->cache->enabled()) {
            return;
        }

        [$verb, $table, $columns] = $write;

        if ($table !== null) {
            $unseen = $this->cache->unseenIn($table);

            if ($unseen === true) {
                return;
            }

            if (is_array($unseen) && $columns !== null && array_diff($columns, $unseen) === []) {
                return;
            }
        }

        $this->pend($verb.($table !== null ? ' '.$table : ''), []);
    }

    /**
     * Throws away now — or when the transaction in progress commits.
     *
     * @param  ?string  $why  why everything goes; null for the tags alone
     * @param  list<string>  $tags
     */
    public function pend(?string $why, array $tags): void
    {
        $transaction = $this->transaction();

        if ($transaction === null) {
            $this->apply($why === null ? [] : [$why], $tags);

            return;
        }

        if (! isset($this->waiting[$transaction])) {
            $pending = (object) ['all' => [], 'tags' => []];
            $this->waiting[$transaction] = $pending;
            $transaction->addCallback(fn () => $this->apply(array_keys($pending->all), array_keys($pending->tags)));
        }

        $pending = $this->waiting[$transaction];

        if ($why !== null && count($pending->all) < 5) {
            $pending->all[$why] = true;
        }

        foreach ($tags as $tag) {
            $pending->tags[$tag] = true;
        }
    }

    /**
     * @param  list<string>  $why  why everything goes — empty: the tags alone
     * @param  list<string>  $tags
     */
    protected function apply(array $why, array $tags): void
    {
        if ($this->applying || ($why === [] && $tags === [])) {
            return;
        }

        $this->applying = true;

        try {
            if ($why !== []) {
                $this->cache->flush(implode('; ', $why));
            } else {
                $this->cache->forgetTagged($tags);
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            $this->applying = false;
        }
    }

    /** The transaction a callback would wait for — none outside one (or in a test's own). */
    protected function transaction(): ?DatabaseTransactionRecord
    {
        if (! app()->bound('db.transactions')) {
            return null;
        }

        return app('db.transactions')->callbackApplicableTransactions()->last();
    }

    /**
     * The statement as [verb, table, columns] — columns only for an UPDATE
     * whose SET list could be read — or null when it writes nothing.
     *
     * @return array{0: string, 1: ?string, 2: ?list<string>}|null
     */
    public function parse(string $sql): ?array
    {
        $sql = ltrim($sql);
        $head = strtolower(substr($sql, 0, 8));

        // Most statements are reads: decided on the first word.
        if (str_starts_with($head, 'select') || str_starts_with($head, 'savepoin') || str_starts_with($head, 'release')
            || str_starts_with($head, 'set ') || str_starts_with($head, 'show') || str_starts_with($head, 'rollback')) {
            return null;
        }

        if (preg_match('/^(alter|create|drop|comment|grant|revoke|reindex|cluster)\b/i', $sql, $m)) {
            return ['schema '.strtolower($m[1]), null, null];
        }

        $name = '(?:"?[a-z0-9_]+"?\.)?"?([a-z0-9_]+)"?';

        if (preg_match('/^(?:with\b.*?\)\s*)?(insert\s+into|update(?:\s+only)?|delete\s+from(?:\s+only)?|truncate(?:\s+table)?(?:\s+only)?|merge\s+into)\s+'.$name.'/is', $sql, $m)) {
            $verb = strtolower(strtok($m[1], " \t\n"));
            $table = strtolower($m[2]);

            return [$verb, $table, $verb === 'update' ? $this->setColumns($sql) : null];
        }

        if (preg_match('/^with\b/i', $sql)) {
            // A CTE: a write if any of its parts writes.
            return preg_match('/\b(insert\s+into|update|delete\s+from)\s+'.$name.'/i', $sql, $m)
                ? [strtolower(strtok($m[1], " \t\n")), strtolower($m[2]), null]
                : null;
        }

        if (preg_match('/^(insert|update|delete|truncate|merge|copy|do|call|lock)\b/i', $sql, $m)) {
            return [strtolower($m[1]), null, null];
        }

        return null;
    }

    /**
     * The columns an UPDATE's SET list names, or null when it cannot be told
     * — then the whole table counts as written.
     *
     * @return list<string>|null
     */
    protected function setColumns(string $sql): ?array
    {
        if (! preg_match('/\bset\s+(.*?)(?:\s+from\s|\s+where\s|\s+returning\s|$)/is', $sql, $m)) {
            return null;
        }

        preg_match_all('/(?:^|,)\s*"?([a-z0-9_]+)"?\s*=/i', $m[1], $columns);

        return $columns[1] === [] ? null : array_values(array_unique(array_map('strtolower', $columns[1])));
    }
}
