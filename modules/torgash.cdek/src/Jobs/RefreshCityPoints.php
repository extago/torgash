<?php

namespace Torgash\Cdek\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Torgash\Cdek\Sync\PointSync;

/**
 * One city's points, re-fetched out of the customer's way. Not a TrackedJob:
 * nobody watches a single city go by.
 */
class RefreshCityPoints implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $cityCode) {}

    public function handle(PointSync $sync): void
    {
        $sync->refresh($this->cityCode);
    }
}
