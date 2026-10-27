<?php

namespace App\Core\Modules\Commands;

use App\Core\Modules\ModuleManager;
use Illuminate\Console\Command;

class ModuleDisableCommand extends Command
{
    protected $signature = 'module:disable {id}';

    protected $description = 'Disable an installed module without removing its data';

    public function handle(ModuleManager $modules): int
    {
        $modules->setEnabled($this->argument('id'), false);
        $this->info("Module [{$this->argument('id')}] disabled.");

        return self::SUCCESS;
    }
}
