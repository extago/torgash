<?php

namespace Torgash\Search\Console;

use Illuminate\Console\Command;
use Torgash\Search\Indexer;

class ReindexCommand extends Command
{
    protected $signature = 'search:reindex {--product=* : Only these product ids}';

    protected $description = 'Rebuild the search documents of the catalogue (or of the given products)';

    public function handle(Indexer $indexer): int
    {
        $ids = array_map('intval', (array) $this->option('product'));

        $done = $ids !== []
            ? $indexer->products($ids)
            : $indexer->all(fn (int $n) => $this->output->write("\r{$n}"));

        $this->newLine();
        $this->info("{$done} products indexed.");

        return self::SUCCESS;
    }
}
