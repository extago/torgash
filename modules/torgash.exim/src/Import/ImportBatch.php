<?php

namespace Torgash\Exim\Import;

/**
 * What one import carries from row to row, handed to every field as
 * `$ctx['batch']`:
 *
 *  - `remember()` — a memo for the run ("the category at A///B is #12"), so a
 *    file of three hundred phones in four categories looks them up four times;
 *  - `add()` / `items()` — work a row asks for that must wait until the rows
 *    are COMMITTED (photos: downloading three thousand inside the
 *    transaction held it open for an hour and a half), read by whoever
 *    listens to `exim.imported`;
 *  - `note()` — what the admin should know afterwards (a category created, a
 *    cell that could not be read), shown with the finished task.
 *
 * One object per run, never a singleton: two imports must not share a memo.
 */
class ImportBatch
{
    /** How many notes are kept — the first ones say what went wrong; the next thousand say it again. */
    public const MAX_NOTES = 50;

    protected array $memo = [];

    /** @var array<string, list<mixed>> */
    protected array $items = [];

    /** @var list<array{message: string, params: array}> */
    protected array $notes = [];

    protected int $noteCount = 0;

    /** The row being imported, numbered as the file's data rows (1 = the line after the header). */
    public int $line = 0;

    public function remember(string $key, callable $make): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $make();
        }

        return $this->memo[$key];
    }

    public function forget(string $key): void
    {
        unset($this->memo[$key]);
    }

    public function add(string $kind, mixed $item): void
    {
        $this->items[$kind][] = $item;
    }

    /** @return list<mixed> */
    public function items(string $kind): array
    {
        return $this->items[$kind] ?? [];
    }

    /** An untranslated English sentence and its placeholders; `:line` is filled in. */
    public function note(string $message, array $params = []): void
    {
        $this->noteCount++;

        if (count($this->notes) < self::MAX_NOTES) {
            $this->notes[] = ['message' => $message, 'params' => $params + ['line' => $this->line]];
        }
    }

    /** @return list<array{message: string, params: array}> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function noteCount(): int
    {
        return $this->noteCount;
    }
}
