<?php

namespace App\Core\Modules\Commands;

use App\Core\Modules\ModuleManager;
use Illuminate\Console\Command;

class ModuleListCommand extends Command
{
    protected $signature = 'module:list';

    protected $description = 'List all modules found on disk with their status';

    public function handle(ModuleManager $modules): int
    {
        $rows = [];
        foreach ($modules->all() as $manifest) {
            $rows[] = [
                $manifest->id,
                $manifest->version,
                $modules->isInstalled($manifest->id)
                    ? ($modules->isEnabled($manifest->id) ? '<info>enabled</info>' : '<comment>disabled</comment>')
                    : 'not installed',
            ];
        }

        $this->table(['Module', 'Version', 'Status'], $rows);

        return self::SUCCESS;
    }
}
