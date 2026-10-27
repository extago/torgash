<?php

namespace Torgash\Search\Jobs;

use App\Core\Tasks\TrackedJob;
use Torgash\Search\Indexer;

/**
 * Rebuilds search documents in the background — the whole catalogue, or a
 * large set of products a single change touched.
 */
class Reindex extends TrackedJob
{
    /** @param  list<int>|null  $ids  null = every product */
    public function __construct(public ?array $ids = null) {}

    public function label(): string
    {
        return 'Rebuild the search index';
    }

    public function module(): ?string
    {
        return 'torgash.search';
    }

    protected function run(): void
    {
        $indexer = app(Indexer::class);

        if ($this->ids !== null) {
            $this->total(count($this->ids));
            $done = $indexer->products($this->ids);
        } else {
            $this->total(\Torgash\Catalog\Models\Product::withTrashed()->count());
            $done = $indexer->all(fn (int $n) => $this->progress($n));
        }

        $this->result(['documents' => $done], ':count products indexed', ['count' => $done]);
    }
}
