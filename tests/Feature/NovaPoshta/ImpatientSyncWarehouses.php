<?php

namespace Tests\Feature\NovaPoshta;

use Torgash\NovaPoshta\Jobs\SyncWarehouses;
use Torgash\NovaPoshta\Sync\WarehouseSync;

/**
 * The bulk branch run without its two waits: the pace it keeps for the
 * carrier's sake and the backoff between retries. Both are measured against the
 * live API and belong in production — but a test that honoured them would spend
 * half a minute asleep to prove something about retries.
 *
 * It lives in its own file, not beside the test that uses it: PHPUnit loads a
 * test file before the application boots, and module classes are only
 * autoloadable after that, so a subclass declared inline would be a fatal error
 * at collection time. Composer loads this one lazily, when it is first named.
 */
class ImpatientSyncWarehouses extends SyncWarehouses
{
    protected function sync(): WarehouseSync
    {
        return app(WarehouseSync::class);
    }

    protected function pauseAfterRefusal(): int
    {
        return 0;
    }
}
