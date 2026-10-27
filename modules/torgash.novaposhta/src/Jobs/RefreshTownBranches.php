<?php

namespace Torgash\NovaPoshta\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Torgash\NovaPoshta\Sync\WarehouseSync;

/**
 * One town's branches, re-fetched out of the customer's way.
 *
 * A stale town used to be refreshed inside the request that noticed it, which
 * was fine when the alternative was an empty picker. It is not fine now that
 * the table is filled: Kyiv is sixteen pages, so one unlucky shopper a day
 * would have watched checkout think for half a minute to learn what the next
 * shopper gets for free. So the request serves what it has and queues this.
 *
 * Not a TrackedJob: nobody is watching a single town go by, and it would put a
 * row on the background tasks screen for every town every day.
 */
class RefreshTownBranches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $settlementRef) {}

    public function handle(WarehouseSync $sync): void
    {
        // Paced: this is background work, and being kind to the key here is
        // what keeps the checkout quotes sharing it from being refused.
        $sync->paced()->refresh($this->settlementRef);
    }
}
