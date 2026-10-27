<?php

namespace App\Core\Modules\Commands;

use App\Core\Modules\ModuleManager;
use Illuminate\Console\Command;

class ModuleEnableCommand extends Command
{
    protected $signature = 'module:enable {id}';

    protected $description = 'Enable an installed module';

    public function handle(ModuleManager $modules): int
    {
        $modules->setEnabled($this->argument('id'), true);
        $this->info("Module [{$this->argument('id')}] enabled.");

        return self::SUCCESS;
    }
}
