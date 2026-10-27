<?php

namespace App\Core\Updates;

use Illuminate\Console\Command;
use Throwable;

/** `torgash:update <zip>` — install a release package. */
class UpdateCommand extends Command
{
    protected $signature = 'torgash:update {package? : A release .zip; without it, the newest one the update server offers}';

    protected $description = 'Update Torgash from a signed release package (backup, maintenance, swap, migrations; rolls back by itself on failure)';

    public function handle(): int
    {
        try {
            $package = (string) $this->argument('package');
            if ($package === '') {
                $server = app(UpdateServer::class);
                $found = $server->check();
                if (isset($found['error'])) {
                    $this->error('The update server could not be asked: '.$found['error']);

                    return self::FAILURE;
                }
                if (! $offer = $server->offer()) {
                    $this->info('Torgash '.config('torgash.version').' is the newest.');

                    return self::SUCCESS;
                }
                $this->line('  Downloading '.$offer['version']);
                $package = $server->download($offer);
            }

            $done = (new Updater)->apply($package, fn ($m) => $this->line('  '.$m));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Torgash {$done['to']} is installed (was {$done['from']}).");
        $this->line('The way back: php artisan torgash:update-rollback  (or, if artisan itself fails: php storage/app/update/rollback.php <plan>)');

        return self::SUCCESS;
    }
}
