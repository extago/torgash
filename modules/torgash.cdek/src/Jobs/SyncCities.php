<?php

namespace Torgash\Cdek\Jobs;

use App\Core\Tasks\TrackedJob;
use Torgash\Cdek\Sync\CitySync;

/**
 * Pulls the carrier's city directory — ~140 000 rows over ~28 requests for
 * Russia, half a minute that no admin should watch a spinner for.
 */
class SyncCities extends TrackedJob
{
    public function label(): string
    {
        return 'Sync the CDEK city directory';
    }

    public function module(): ?string
    {
        return 'torgash.cdek';
    }

    protected function run(): void
    {
        // Measured on the test server (Russia); only draws the bar.
        $this->total(140000);
        $this->message('Asking CDEK for its city directory');

        $result = app(CitySync::class)->run(fn (int $seen) => $this->progress($seen));

        $this->result($result, ':cities cities', ['cities' => $result['cities']]);
    }
}
