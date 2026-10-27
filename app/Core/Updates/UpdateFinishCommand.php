<?php

namespace App\Core\Updates;

use Illuminate\Console\Command;
use Throwable;

/** `torgash:update-finish <plan>` — the new code's half of an update; started by torgash:update in its own process. */
class UpdateFinishCommand extends Command
{
    protected $signature = 'torgash:update-finish {plan}';

    protected $description = 'Finish an update on the new code (internal: torgash:update runs it)';

    protected $hidden = true;

    public function handle(): int
    {
        try {
            (new Updater)->finish((string) $this->argument('plan'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
