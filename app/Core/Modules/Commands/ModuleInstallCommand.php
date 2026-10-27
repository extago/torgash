<?php

namespace App\Core\Modules\Commands;

use App\Core\Modules\ModuleManager;
use Illuminate\Console\Command;

class ModuleInstallCommand extends Command
{
    protected $signature = 'module:install {id : Module id, e.g. torgash.hello}';

    protected $description = 'Install a module: run its migrations, register its permissions, enable it';

    public function handle(ModuleManager $modules): int
    {
        $modules->install($this->argument('id'));
        $this->info("Module [{$this->argument('id')}] installed and enabled.");

        return self::SUCCESS;
    }
}
