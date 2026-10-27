<?php

namespace App\Core\System;

use Illuminate\Console\Command;

/** `torgash:refresh` — what runs after every change of code (PLAN §45.4), by hand: after files were copied over by FTP, say. */
class RefreshCommand extends Command
{
    protected $signature = 'torgash:refresh';

    protected $description = 'After code changed on disk: drop the code caches, restart queue workers, rebuild the stylesheet';

    public function handle(CodeChanged $code): int
    {
        config(['torgash.refresh_on_change' => true]);

        foreach ($code->refresh() as $step) {
            $this->line('  done: '.$step);
        }

        $last = app(\App\Core\Assets\StyleBuilder::class)->last();
        if (! empty($last['error'])) {
            $this->warn('The stylesheet was not built: '.$last['error']);
        }

        return self::SUCCESS;
    }
}
