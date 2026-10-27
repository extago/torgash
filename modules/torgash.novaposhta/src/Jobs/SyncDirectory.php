<?php

namespace Torgash\NovaPoshta\Jobs;

use App\Core\Tasks\TrackedJob;
use Torgash\NovaPoshta\Sync\DirectorySync;

/**
 * Pulls the carrier's settlement and city lists. ~38 000 rows over ~80
 * requests — a minute of work that no admin should be watching a spinner for,
 * and that a PHP time limit would cut in half.
 */
class SyncDirectory extends TrackedJob
{
    public function label(): string
    {
        return 'Sync the Nova Poshta directory';
    }

    public function module(): ?string
    {
        return 'torgash.novaposhta';
    }

    protected function run(): void
    {
        // Known from the live API (26 770 + 11 156) and only used to draw the
        // bar; the real counts come back in the result.
        $this->total(38000);
        $this->message('Asking Nova Poshta for its directory');

        $result = app(DirectorySync::class)->run(fn (int $seen) => $this->progress($seen));

        $this->result(
            $result,
            ':settlements settlements and :cities cities, :linked of them addressable by courier',
            $result,
        );
    }
}
