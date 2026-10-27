<?php

namespace Torgash\Guard\Jobs;

use App\Core\Tasks\TrackedJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Torgash\Guard\Audit\Revert;
use Torgash\Guard\Models\AuditEntry;

/**
 * "Put back everything this background task did" (PLAN §56): every entry the
 * task wrote, newest first, through the same `Revert` as the single-entry
 * button — so the same refusals hold (only changes, only content, only values
 * kept whole, only fields nobody has touched since) and the revert is itself
 * in the trail, as a task of the person who pressed the button.
 *
 * A UNIQUE column is the one thing one-by-one cannot do: a renumbering hands
 * SKU-000001 from one product to another, and neither can take its old value
 * back while the other still holds it. Such entries wait to the end; then
 * the values they hold are parked (`~~` in front, as `Renumber` does), every
 * waiting entry is tried again, and what is still parked goes back. Swaps
 * among the task's own rows untangle; a value somebody ELSE holds now stays
 * where it is and is reported. That last step runs in one transaction — if a
 * value cannot even be put back where it was, none of the step happened.
 */
class RevertTask extends TrackedJob
{
    public const PARKED = '~~';

    /** Entries read at a time. */
    protected const PAGE = 500;

    /**
     * @param  array<string, int>  $counts
     * @param  list<int>  $blocked  entries held up by a unique value, for the end
     */
    public function __construct(
        public int $auditTaskId,
        public ?int $before = null,
        public array $counts = [],
        public array $blocked = [],
        public int $done = 0,
    ) {
        $this->counts += ['reverted' => 0, 'changed_since' => 0, 'kept_cut' => 0, 'gone' => 0, 'taken' => 0, 'not_revertible' => 0];
    }

    public function label(): string
    {
        return 'Put back what a background task did';
    }

    public function module(): ?string
    {
        return 'torgash.guard';
    }

    protected function run(): void
    {
        $revert = app(Revert::class);

        if ($this->before === null) {
            $this->total(AuditEntry::where('task_id', $this->auditTaskId)->count());
            $this->message('Putting back what task #:id did', ['id' => $this->auditTaskId]);
        }

        while (($page = $this->page())->isNotEmpty()) {
            foreach ($page as $entry) {
                $this->before = $entry->id;
                $this->done++;

                if (! $revert->offered($entry)) {
                    $this->counts['not_revertible']++;

                    continue;
                }

                try {
                    $this->tally(DB::transaction(fn () => $revert->apply($entry)));
                } catch (UniqueConstraintViolationException) {
                    $this->blocked[] = $entry->id;
                }
            }

            $this->progress($this->done);

            if ($this->timeIsUp()) {
                $this->continueWith(new self($this->auditTaskId, $this->before, $this->counts, $this->blocked, $this->done));

                return;
            }
        }

        $this->untangle($revert);

        $this->result(
            $this->counts,
            'Put back: :reverted. Changed again since: :changed_since. Value taken by another record: :taken. Not a change that can be put back: :not_revertible.',
            $this->counts,
        );
    }

    /** The next entries of the task, newest first. */
    protected function page()
    {
        return AuditEntry::where('task_id', $this->auditTaskId)
            ->when($this->before, fn ($q, int $id) => $q->where('id', '<', $id))
            ->orderByDesc('id')->limit(self::PAGE)->get();
    }

    /** @param array{reverted: list<string>, skipped: array<string, string>, gone?: bool} $result */
    protected function tally(array $result): void
    {
        $why = match (true) {
            $result['reverted'] !== [] => 'reverted',
            (bool) ($result['gone'] ?? false) => 'gone',
            in_array('changed_since', $result['skipped'], true) => 'changed_since',
            in_array('kept_cut', $result['skipped'], true) => 'kept_cut',
            default => 'not_revertible',
        };

        $this->counts[$why]++;
    }

    /** The entries a unique value held up, tried again with those values parked. */
    protected function untangle(Revert $revert): void
    {
        if ($this->blocked === []) {
            return;
        }

        $entries = AuditEntry::whereIn('id', $this->blocked)->orderByDesc('id')->get();
        $results = [];

        try {
            DB::transaction(function () use ($entries, $revert, &$results) {
                $parked = [];

                foreach ($entries as $entry) {
                    $parked[$entry->id] = $this->park($entry);
                }

                foreach ($entries as $entry) {
                    try {
                        $results[$entry->id] = DB::transaction(fn () => $revert->apply($entry, $parked[$entry->id]));
                    } catch (UniqueConstraintViolationException) {
                        $results[$entry->id] = null;
                    }
                }

                foreach ($entries as $entry) {
                    $this->unpark($entry, $parked[$entry->id]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            $results = [];
        }

        foreach ($entries as $entry) {
            ($results[$entry->id] ?? null) === null ? $this->counts['taken']++ : $this->tally($results[$entry->id]);
        }
    }

    /**
     * Moves aside the text values this entry's row still holds from the
     * change, so another row of the task can take them back.
     *
     * @return array<string, string> field => what it really holds
     */
    protected function park(AuditEntry $entry): array
    {
        $model = $this->model($entry);

        if (! $model) {
            return [];
        }

        $parked = [];

        foreach ($entry->changes as $key => $sides) {
            $now = $model->getAttributes()[$key] ?? null;

            if (is_string($now) && $now !== '' && is_array($sides) && (string) ($sides['to'] ?? '') === $now) {
                $parked[(string) $key] = $now;
            }
        }

        if ($parked !== []) {
            $model->newQuery()->whereKey($model->getKey())->update(
                array_map(fn (string $value) => self::PARKED.$value, $parked),
            );
        }

        return $parked;
    }

    /**
     * Puts back every parked value its revert did not replace. One that
     * cannot go back (somebody took it meanwhile) undoes the whole step.
     *
     * @param  array<string, string>  $parked
     */
    protected function unpark(AuditEntry $entry, array $parked): void
    {
        $model = $parked === [] ? null : $this->model($entry);

        if (! $model) {
            return;
        }

        $restore = [];

        foreach ($parked as $key => $value) {
            if (($model->getAttributes()[$key] ?? null) === self::PARKED.$value) {
                $restore[$key] = $value;
            }
        }

        if ($restore !== []) {
            $model->newQuery()->whereKey($model->getKey())->update($restore);
        }
    }

    protected function model(AuditEntry $entry): ?Model
    {
        $class = $entry->entity_type;

        return is_string($class) && class_exists($class) && is_subclass_of($class, Model::class)
            ? $class::query()->find($entry->entity_id)
            : null;
    }
}
