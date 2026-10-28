<?php

namespace Torgash\Exim;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Stock\StockMoves;
use Torgash\Exim\Import\Columns;
use Torgash\Exim\Import\ImportFile;
use Torgash\Exim\Import\FormulaException;
use Torgash\Exim\Import\ImportBatch;
use Torgash\Exim\Renderers\CsvRenderer;

/**
 * Rows in, records out. A row is field key => cell (`Import\Columns::row()`
 * makes one from a file line and the admin's mapping); the fields are the
 * entity's schema (SchemaRegistry), so the importer itself knows no entity.
 *
 * Options an import carries (the mapping screen sets them, a preset keeps
 * them; fields read the ones they understand from `$ctx['options']`):
 *   create_new         false — rows that match nothing are skipped (the usual
 *                      "skip creating new products": a price list must not
 *                      invent the goods it has no names for)
 *   category_delimiter "///" — between the levels of a category path
 *   images_mode        empty | missing | replace — what a row's photo links do
 *   images_base        prepended to an image cell that is not a link
 *   formulas           column index => formula (`Import\Formula`), applied
 *                      to the cell before any field reads it
 */
class ImportRunner
{
    public const DEFAULT_OPTIONS = [
        'create_new' => true,
        'category_delimiter' => '///',
        'images_mode' => 'empty',
        'images_base' => '',
        'formulas' => [],
    ];

    public function __construct(protected SchemaRegistry $schema) {}

    /**
     * The entity's import columns, by key: the ones that write something.
     * An export-only column (a computed price) is left out, or a file's
     * "Price" would map onto it and import nothing.
     *
     * @return array<string, array>
     */
    public function fields(string $entity): array
    {
        return collect($this->schema->fieldsFor($entity))
            ->filter(fn (array $field) => ($field['set'] ?? null) || ($field['after'] ?? null))
            ->keyBy('key')
            ->all();
    }

    /**
     * What the import WOULD do: every row matched (nothing is written), the
     * first `$show` kept for the screen.
     *
     * A row that matches nothing may not be able to make a new record — the
     * entity's owner says why in its match (`refused`: a product without a
     * name). It is a skip, and `refused` says which lines and why.
     *
     * @param  list<string>  $mapping
     * @return array{fieldDefs: array, rows: list<array>, counts: array{create: int, update: int, skip: int}, fallbacks: int, total: int, problems: list<string>, refused: list<string>}
     */
    public function preview(ImportFile $file, string $entity, array $mapping, array $options = [], int $show = 100): array
    {
        $options += self::DEFAULT_OPTIONS;
        $fieldDefs = $this->fields($entity);
        $header = $file->header();
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0];
        $fallbacks = 0;
        $total = 0;
        $rows = [];
        $formulas = Columns::formulas((array) $options['formulas']);
        $problems = [];
        $refused = [];

        foreach ($file->rows() as $line => $cells) {
            $row = Columns::row($header, $mapping, $cells, $fieldDefs, $formulas, function (string $column, FormulaException $e) use (&$problems, $line) {
                if (count($problems) < 20) {
                    $problems[] = __('Line :line: column ":column" not imported — :problem', ['line' => $line, 'column' => $column, 'problem' => $e->describe()]);
                }
            });

            if ($row === []) {
                continue;
            }

            $total++;
            $match = hook_filter("exim.match.{$entity}", null, ['row' => $row]);
            $exists = $match && $match['model']->exists;
            $refusal = ! $exists && $options['create_new'] ? ($match['refused'] ?? null) : null;
            $action = $exists ? 'update' : ($options['create_new'] && ! $refusal ? 'create' : 'skip');
            $counts[$action]++;

            if ($refusal && count($refused) < 20) {
                $refused[] = __('Line :line: skipped — :reason', ['line' => $line, 'reason' => __($refusal)]);
            }

            if (($match['matchedBy'] ?? null) === 'name-fallback') {
                $fallbacks++;
            }

            if (count($rows) < $show) {
                $rows[] = ['line' => $line, 'row' => $row, 'action' => $action, 'matchedBy' => $match['matchedBy'] ?? null];
            }
        }

        return ['fieldDefs' => $fieldDefs, 'rows' => $rows, 'counts' => $counts, 'fallbacks' => $fallbacks, 'total' => $total, 'problems' => $problems, 'refused' => $refused];
    }

    /**
     * A file read with the columns guessed, no screen in between — our own
     * export coming back, where every header is ours.
     *
     * @return list<array{row: array}>
     */
    public function rowsOf(string $path, string $entity): array
    {
        $file = ImportFile::read($path);
        $mapping = Columns::guess($file->header(), array_values($this->fields($entity)));

        return iterator_to_array($this->mappedRows($file, $entity, $mapping), false);
    }

    /**
     * Every row of the file, mapped — what the job hands to commit(). A
     * formula that failed on a line rides along as a note for the task.
     *
     * @param  array<int, string>  $formulas  column index => formula
     */
    public function mappedRows(ImportFile $file, string $entity, array $mapping, array $formulas = []): \Generator
    {
        $fieldDefs = $this->fields($entity);
        $header = $file->header();
        $formulas = Columns::formulas($formulas);

        foreach ($file->rows() as $line => $cells) {
            $notes = [];
            $row = Columns::row($header, $mapping, $cells, $fieldDefs, $formulas, function (string $column, FormulaException $e) use (&$notes) {
                // The reason in English too: it is translated for whoever reads the task.
                $notes[] = ['Line :line: column ":column" not imported — :problem', ['column' => $column, 'problem' => ['message' => $e->getMessage(), 'params' => $e->params]]];
            });

            if ($row !== []) {
                yield $line => ['row' => $row, 'notes' => $notes];
            }
        }
    }

    /**
     * Undoes `CsvRenderer::guard()`: an apostrophe the export put in front of
     * what a spreadsheet would read as a formula. Only there — an apostrophe
     * before anything else is the shop's own text.
     */
    public static function unguard(string $value): string
    {
        return strlen($value) > 1 && $value[0] === "'" && in_array($value[1], CsvRenderer::FORMULA_STARTS, true)
            && ! is_numeric(substr($value, 1))
            ? substr($value, 1)
            : $value;
    }

    /**
     * @param  iterable<array{row: array}>  $rows  keyed by the file's line, or a plain list
     * @param  ?callable  $onRow  called with the number of rows done so far
     * @return array{created: int, updated: int, skipped: int, batch: ImportBatch}
     */
    public function commit(string $entity, iterable $rows, ?callable $onRow = null, array $options = []): array
    {
        $options += self::DEFAULT_OPTIONS;
        $fieldDefs = $this->fields($entity);
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $batch = new ImportBatch;
        // What the listeners are handed: the rows, which they read to tell
        // which records the file was about. Collected on the way, since a
        // file's rows arrive one at a time now.
        $seen = [];

        // Every stock number an import writes is the import's doing (PLAN §42).
        // A closure, not `fn () =>`: an arrow function captures by VALUE, so
        // the counts written by reference inside never reached this method —
        // every import reported "0 created, 0 updated".
        app(StockMoves::class)->because(['type' => 'import', 'entity' => $entity], function () use ($entity, $rows, $fieldDefs, $options, $batch, &$counts, &$seen, $onRow) {
            DB::transaction(function () use ($entity, $rows, $fieldDefs, $options, $batch, &$counts, &$seen, $onRow) {
                $n = 0;

                foreach ($rows as $line => $entry) {
                    $batch->line = is_int($line) ? $line : ++$n;
                    $row = $entry['row'];

                    foreach ($entry['notes'] ?? [] as [$message, $params]) {
                        $batch->note($message, $params);
                    }
                    $match = hook_filter("exim.match.{$entity}", null, ['row' => $row]);
                    $model = $match['model'];
                    $isNew = ! $model->exists;

                    if ($isNew && ! $options['create_new']) {
                        $counts['skipped']++;
                        $onRow && $onRow(array_sum($counts));

                        continue;
                    }

                    // Nothing to make a record of (a product without a name):
                    // this row is left out and said so, not allowed to stop
                    // the file at the database and take every other row with it.
                    if ($isNew && ($match['refused'] ?? null)) {
                        $batch->note('Line :line: skipped — :reason', ['reason' => ['message' => $match['refused'], 'params' => []]]);
                        $counts['skipped']++;
                        $onRow && $onRow(array_sum($counts));

                        continue;
                    }

                    $after = [];

                    foreach ($row as $rowKey => $value) {
                        [$key, $column] = Columns::split((string) $rowKey, $fieldDefs);
                        $field = $fieldDefs[$key] ?? null;

                        if (! $field) {
                            continue;
                        }

                        $ctx = ['column' => $column, 'row' => $row, 'options' => $options, 'batch' => $batch];

                        if ($field['set'] ?? null) {
                            ($field['set'])($model, $value, $ctx);
                        }
                        if ($field['after'] ?? null) {
                            $after[] = [$field['after'], $value, $ctx];
                        }
                    }

                    // The entity's owner fills in what a file may leave out — a
                    // new product's address from its name — after the cells and
                    // before the row is written.
                    hook_action("exim.saving.{$entity}", ['model' => $model, 'row' => $row, 'batch' => $batch, 'options' => $options]);

                    $model->save();

                    // What lives in another table and needs the row's id.
                    foreach ($after as [$callback, $value, $ctx]) {
                        $callback($model, $value, $ctx);
                    }
                    $counts[$isNew ? 'created' : 'updated']++;
                    $seen[] = ['row' => $row];

                    if ($onRow) {
                        $onRow(array_sum($counts));
                    }
                }
            });
        });

        // After the transaction, and once for the whole file: an entity whose
        // owner has to do something AFTER a set of rows landed — recount an
        // average, warm a cache, fetch the photos — must not do it per row,
        // because an import of ten thousand reviews would recount one product
        // ten thousand times.
        hook_action('exim.imported', ['entity' => $entity, 'rows' => $seen, 'counts' => $counts, 'batch' => $batch, 'options' => $options]);

        return $counts + ['batch' => $batch];
    }
}
