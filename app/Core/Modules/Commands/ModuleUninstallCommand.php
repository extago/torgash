<?php

namespace App\Core\Modules\Commands;

use App\Core\Modules\ModuleManager;
use Illuminate\Console\Command;

class ModuleUninstallCommand extends Command
{
    protected $signature = 'module:uninstall {id}';

    protected $description = 'Uninstall a module: roll back its migrations, remove its permissions and settings';

    public function handle(ModuleManager $modules): int
    {
        if (! $this->confirm("Uninstall [{$this->argument('id')}]? Its tables and settings will be removed.", true)) {
            return self::FAILURE;
        }

        $modules->uninstall($this->argument('id'));
        $this->info("Module [{$this->argument('id')}] uninstalled without traces.");

        return self::SUCCESS;
    }
}
