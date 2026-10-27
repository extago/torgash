<?php

namespace Torgash\Exim\Console;

use Illuminate\Console\Command;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\Rebuilds;

/**
 * The scheduler starts due builds on the queue by itself (Rebuilds, every
 * minute). This builds them HERE, in the console — to see one happen, or on
 * a server whose queue is not running.
 */
class RunScheduledCommand extends Command
{
    protected $signature = 'exim:run-scheduled';

    protected $description = 'Build every export file that is due — by its schedule or by a change in its data — right here.';

    public function handle(Rebuilds $rebuilds, ExportRunner $runner): int
    {
        $due = $rebuilds->due();

        foreach ($due as [$profile, $reason]) {
            $this->info("Building profile #{$profile->id} ({$profile->entity}, {$reason})...");
            $runner->run($profile);
        }

        $this->info('Done — '.count($due).' file(s) built.');

        return self::SUCCESS;
    }
}
