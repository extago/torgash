<?php

namespace Torgash\Catalog\Console;

use Illuminate\Console\Command;
use Torgash\Catalog\Pricing\PriceHistory;

/**
 * The hourly sweep, by hand: the first run after installing seeds the history
 * (every product's price as of now), and a shop with no cron can run it from
 * its own scheduler. See Pricing\PriceHistory.
 */
class PriceHistoryCommand extends Command
{
    protected $signature = 'catalog:price-history {--prune : Also drop what no window can reach any more}';

    protected $description = 'Record every product\'s public price where it moved (the hourly sweep)';

    public function handle(PriceHistory $history): int
    {
        $this->info(trans_choice(':count price moved|:count prices moved', $moved = $history->sweep(), ['count' => $moved]));

        if ($this->option('prune')) {
            $this->info('Pruned: '.$history->prune());
        }

        return self::SUCCESS;
    }
}
