<?php

namespace Torgash\Files;

use App\Core\Modules\ModuleLifecycle;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Torgash\Files\Models\Folder;
use Torgash\Files\Models\StoredFile;

/**
 * The file storage: a folder of the shop's own pictures and files, each with
 * a permanent public link (PLAN §69). Nothing else in the engine reads these
 * tables — a link is pasted where it is needed, as text — so the module adds
 * a screen and nothing to any page.
 */
class FilesServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    public function register(): void
    {
        $this->app->singleton(Library::class);
    }

    public function boot(): void
    {
        // No page draws these rows: a link pasted into a block is the block's text.
        page_cache()->unseen(['m_files_folders', 'm_files_files']);

        // The bytes are on the public disk, not a media row's: declared, so
        // copying the shop to another storage and the storage screen see them.
        hook_listen('core.files.directories', fn (array $directories) => [...$directories, [
            'disk' => StoredFile::DISK,
            'directory' => StoredFile::DIRECTORY,
            'label' => 'File manager',
        ]]);

        Route::middleware(['web', 'auth', 'can:files.manage'])
            ->prefix('admin/files')
            ->name('admin.files.')
            ->group(dirname(__DIR__).'/routes-admin.php');
    }

    public function install(): void {}

    /** The files on the public disk, which dropping the tables would leave behind. */
    public function uninstall(): void
    {
        if (Schema::hasTable('m_files_files')) {
            StoredFile::query()->delete();
        }

        if (Schema::hasTable('m_files_folders')) {
            Folder::query()->delete();
        }

        Storage::disk(StoredFile::DISK)->deleteDirectory(StoredFile::DIRECTORY);
    }
}
