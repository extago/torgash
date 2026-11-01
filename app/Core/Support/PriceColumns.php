<?php

namespace App\Core\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every stored price per unit, wherever it lives — so a change of
 * `price_places` can move them all at once (PLAN §65).
 *
 * The columns are declared by whoever owns the table, through
 * `core.price.columns`, because core may not know a module's tables:
 *
 *     hook_listen('core.price.columns', fn (array $columns) => [...$columns, [
 *         'name'    => 'Option prices',          // English, translated by lang files
 *         'table'   => 'm_options_choices',
 *         'columns' => ['amount', 'amount_original'],
 *         'where'   => fn (Builder $q) => $q->where('kind', 'fixed'),   // optional
 *         'example' => 'code',                   // optional: what names a row in a refusal
 *     ]]);
 *
 * A price kept inside a jsonb document (an order's lines) is declared with
 * its own two callbacks instead of a table: `lossy(int $divisor): int` and
 * `rescale(int $multiply, int $divide): void`.
 *
 * More places multiply exactly. Fewer places are only ever taken when no
 * price would lose a digit — `lossy()` answers that question before the
 * setting is written, and the settings screen refuses otherwise.
 */
class PriceColumns
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $declared = hook_filter('core.price.columns', []);

        return array_values(array_filter(
            is_array($declared) ? $declared : [],
            fn ($spec) => is_array($spec) && (isset($spec['rescale']) || (isset($spec['table'], $spec['columns']) && Schema::hasTable($spec['table']))),
        ));
    }

    /**
     * What going from `$from` places to `$to` would cost: for each declared
     * store, how many rows hold a price with digits that would be lost, and a
     * few names to look for. Empty when nothing is lost — always, when the
     * places grow.
     *
     * @return list<array{name: string, count: int, examples: list<string>}>
     */
    public function lossy(int $from, int $to): array
    {
        if ($to >= $from) {
            return [];
        }

        $divisor = 10 ** ($from - $to);
        $found = [];

        foreach ($this->all() as $spec) {
            if (isset($spec['lossy'])) {
                $count = (int) ($spec['lossy'])($divisor);
                $examples = [];
            } else {
                $query = $this->lossyRows($spec, $divisor);
                $count = (clone $query)->count();
                $examples = isset($spec['example']) && $count > 0
                    ? (clone $query)->orderBy($spec['example'])->limit(5)->pluck($spec['example'])->map(fn ($v) => (string) $v)->all()
                    : [];
            }

            if ($count > 0) {
                $found[] = ['name' => (string) ($spec['name'] ?? $spec['table'] ?? ''), 'count' => $count, 'examples' => $examples];
            }
        }

        return $found;
    }

    /**
     * Move every declared price from `$from` places to `$to`, in one
     * transaction: either all of them are in the new places or none is.
     * Refuses (throws) a lossy move — the settings screen asks `lossy()`
     * first, and this is the guard for any other door.
     */
    public function rescale(int $from, int $to): void
    {
        if ($from === $to) {
            return;
        }

        if ($lost = $this->lossy($from, $to)) {
            throw new \RuntimeException('Prices would lose digits: '.collect($lost)->map(fn ($l) => $l['name'].' ('.$l['count'].')')->implode(', '));
        }

        $multiply = $to > $from ? 10 ** ($to - $from) : 1;
        $divide = $to < $from ? 10 ** ($from - $to) : 1;

        DB::transaction(function () use ($multiply, $divide) {
            foreach ($this->all() as $spec) {
                if (isset($spec['rescale'])) {
                    ($spec['rescale'])($multiply, $divide);

                    continue;
                }

                $query = DB::table($spec['table']);

                if (isset($spec['where'])) {
                    ($spec['where'])($query);
                }

                $set = [];

                foreach ($spec['columns'] as $column) {
                    $set[$column] = $multiply > 1
                        ? DB::raw("{$column} * {$multiply}")
                        : DB::raw("round({$column}::numeric / {$divide})");
                }

                $query->update($set);
            }
        });
    }

    /**
     * A declaration for prices kept inside a jsonb LIST of lines — an order's
     * items, a return's lines: `$keys` of every line are prices per unit, and
     * so are `$nested` keys of the lists inside a line (an option's `amount`).
     * Everything else in the line (`line_total`, `qty`) is left as it is.
     *
     *     PriceColumns::jsonLines('Order lines', 'm_checkout_orders', 'items', ['unit_price'], ['options' => ['amount']])
     *
     * @param  list<string>  $keys
     * @param  array<string, list<string>>  $nested
     * @return array<string, mixed>
     */
    public static function jsonLines(string $name, string $table, string $column, array $keys, array $nested = []): array
    {
        $walk = function (mixed $lines, \Closure $move) use ($keys, $nested): mixed {
            if (! is_array($lines)) {
                return $lines;
            }

            foreach ($lines as $i => $line) {
                if (! is_array($line)) {
                    continue;
                }

                foreach ($keys as $key) {
                    if (isset($line[$key]) && is_numeric($line[$key])) {
                        $line[$key] = $move((int) $line[$key]);
                    }
                }

                foreach ($nested as $list => $listKeys) {
                    foreach ((array) ($line[$list] ?? []) as $j => $item) {
                        foreach ($listKeys as $key) {
                            if (is_array($item) && isset($item[$key]) && is_numeric($item[$key])) {
                                $line[$list][$j][$key] = $move((int) $item[$key]);
                            }
                        }
                    }
                }

                $lines[$i] = $line;
            }

            return $lines;
        };

        $rows = fn () => DB::table($table)->whereNotNull($column)->select(['id', $column])->orderBy('id');

        return [
            'name' => $name,
            'lossy' => function (int $divisor) use ($rows, $walk, $column) {
                $count = 0;

                $rows()->chunk(500, function ($chunk) use (&$count, $walk, $column, $divisor) {
                    foreach ($chunk as $row) {
                        $lost = false;
                        $walk(json_decode((string) $row->{$column}, true), function (int $v) use (&$lost, $divisor) {
                            $lost = $lost || $v % $divisor !== 0;

                            return $v;
                        });
                        $count += $lost ? 1 : 0;
                    }
                });

                return $count;
            },
            'rescale' => function (int $multiply, int $divide) use ($rows, $walk, $table, $column) {
                $rows()->chunk(500, function ($chunk) use ($walk, $table, $column, $multiply, $divide) {
                    foreach ($chunk as $row) {
                        $lines = $walk(json_decode((string) $row->{$column}, true), fn (int $v) => intdiv($v * $multiply, $divide));
                        DB::table($table)->where('id', $row->id)->update([$column => json_encode($lines, JSON_UNESCAPED_UNICODE)]);
                    }
                });
            },
        ];
    }

    /** @param array<string, mixed> $spec */
    protected function lossyRows(array $spec, int $divisor): Builder
    {
        $query = DB::table($spec['table']);

        if (isset($spec['where'])) {
            ($spec['where'])($query);
        }

        return $query->where(function (Builder $q) use ($spec, $divisor) {
            foreach ($spec['columns'] as $column) {
                $q->orWhereRaw("{$column} % {$divisor} <> 0");
            }
        });
    }
}
