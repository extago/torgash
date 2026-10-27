<?php

namespace Torgash\Search;

use Illuminate\Support\Facades\Log;
use Throwable;
use Torgash\Search\Jobs\Reindex;

/**
 * Products whose document is out of date, collected while a request (or a
 * queued job, or a console command) runs and rebuilt ONCE when it ends.
 *
 * Saving one product writes the product and then each of its
 * characteristics — ten saves for one edit — and an import writes thousands.
 * Rebuilding on each save would do the same work ten times and, worse, do it
 * before the characteristics were written. So a save only marks the product;
 * `flush()` runs on `terminating()` and after every queued job — the same
 * shape as checkout's `OrderChanges`. A set too large for the end of a request
 * (a characteristic renamed on five thousand products) goes to the queue.
 */
class Pending
{
    public const INLINE = 300;

    /** @var array<int, true> */
    protected array $ids = [];

    protected bool $everything = false;

    /** @param  iterable<int>|int  $productIds */
    public function mark(iterable|int $productIds): void
    {
        foreach (is_int($productIds) ? [$productIds] : $productIds as $id) {
            $this->ids[(int) $id] = true;
        }

        // Under tests a product is created BETWEEN requests, so its document
        // would be written at the end of the next request — inside the page a
        // query-budget test is counting, and after the search a test is
        // making. Written at once there; the batching itself is tested with
        // `torgash.search.batch_in_tests` set, through a real request.
        if (app()->runningUnitTests() && ! config('torgash.search.batch_in_tests')) {
            $this->flush();
        }
    }

    /** A change that touches every document — a setting about which fields count. */
    public function markEverything(): void
    {
        $this->everything = true;
    }

    public function flush(): void
    {
        $ids = array_keys($this->ids);
        $everything = $this->everything;
        $this->ids = [];
        $this->everything = false;

        if (! $everything && $ids === []) {
            return;
        }

        try {
            if ($everything) {
                Reindex::startIfIdle();
            } elseif (count($ids) > self::INLINE) {
                Reindex::start($ids);
            } else {
                app(Indexer::class)->products($ids);
            }
        } catch (Throwable $e) {
            // The page has been answered; a document one save out of date is
            // what the Search screen's "rebuild" button exists for.
            Log::warning('torgash.search could not rebuild documents: '.$e->getMessage());
        }
    }
}
