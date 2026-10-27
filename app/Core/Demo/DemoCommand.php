<?php

namespace App\Core\Demo;

use Illuminate\Console\Command;

/**
 * `torgash:demo install|remove|status` (PLAN §55). Install refuses a shop
 * that already has a demo (remove it first) — unless `--part` names the parts
 * to add, for a module installed after the demo; remove deletes only what
 * the pack created.
 */
class DemoCommand extends Command
{
    protected $signature = 'torgash:demo {action : install, remove or status}
        {--part=* : install only these parts (see status), also into a shop that has the demo}';

    protected $description = 'Install the demo shop, remove it, or say whether it is there';

    public function handle(DemoData $demo): int
    {
        return match ($this->argument('action')) {
            'install' => $this->install($demo),
            'remove' => $this->remove($demo),
            'status' => $this->status($demo),
            default => $this->wrong(),
        };
    }

    protected function install(DemoData $demo): int
    {
        $only = $this->option('part') ?: null;

        if ($only !== null && ($unknown = array_diff($only, array_keys($demo->parts())))) {
            $this->error('No such part: '.implode(', ', $unknown).'.');

            return self::FAILURE;
        }

        if ($only === null && $demo->installed()) {
            $this->error('The demo is already installed — "torgash:demo remove" first.');

            return self::FAILURE;
        }

        $parts = $demo->install(fn (string $line) => $this->line($line), $only);
        $this->info('Demo installed: '.implode(', ', $parts).' ('.$demo->count().' records).');

        return self::SUCCESS;
    }

    protected function remove(DemoData $demo): int
    {
        $result = $demo->remove();
        $this->info("Demo removed: {$result['removed']} records.");

        if ($result['failed'] > 0) {
            $this->warn("{$result['failed']} could not be removed — see the log.");
        }

        return self::SUCCESS;
    }

    protected function status(DemoData $demo): int
    {
        $this->line($demo->installed() ? 'Demo installed: '.$demo->count().' records.' : 'No demo data.');
        $this->line('Parts: '.(implode(', ', array_keys($demo->parts())) ?: 'none'));

        return self::SUCCESS;
    }

    protected function wrong(): int
    {
        $this->error('Action: install, remove or status.');

        return self::FAILURE;
    }
}
