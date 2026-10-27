<?php

namespace Torgash\Cdek\Jobs;

use App\Core\Tasks\TrackedJob;
use Torgash\Cdek\Sync\PointSync;

/**
 * Every pickup point and locker of the shop's countries, in one paged pass —
 * so the picker never waits for the carrier, and "the nearest town with a
 * point" can be answered for any village.
 *
 * Nova Poshta has to be walked town by town; CDEK answers for the whole
 * country, so this is one run rather than a run continued night after night.
 */
class SyncPoints extends TrackedJob
{
    public function label(): string
    {
        return 'Sync CDEK pickup points';
    }

    public function module(): ?string
    {
        return 'torgash.cdek';
    }

    protected function run(): void
    {
        $this->message('Asking CDEK for its pickup points');

        $result = app(PointSync::class)->all(fn (int $seen) => $this->progress($seen));

        $this->result($result, ':points pickup points in :cities cities', $result);
    }
}
