<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Rolled back per test rather than migrated per test: building this schema
     * means running every module's install as well (see TestSchema), which is
     * seconds, not milliseconds.
     */
    use DatabaseTransactions;

    /**
     * Runs before the traits above open their transaction — the database may
     * not exist yet at this point, and creating it inside one is impossible.
     *
     * When the schema was built just now, the application booted a moment ago
     * against a database that had nothing in it: storefront routes are
     * registered from the storefronts table at boot, so every storefront URL
     * would 404 for the whole first run. Booting it again costs one run and
     * saves the next person that puzzle.
     */
    protected function setUpTraits()
    {
        if (TestSchema::ensure()) {
            $this->refreshApplication();
        }

        $this->isolateFiles();
        $this->offlineDns();

        return parent::setUpTraits();
    }

    /**
     * A test never asks the real DNS: every host a faked request names
     * resolves to one public address, except `*.internal`, which stands for
     * somebody's private network (see OutboundUrl).
     */
    protected function offlineDns(): void
    {
        $this->app->instance(\App\Core\Http\HostResolver::class, new class extends \App\Core\Http\HostResolver
        {
            public function resolve(string $host): array
            {
                return str_ends_with($host, '.internal') ? ['10.0.0.5'] : ['93.184.216.34'];
            }
        });
    }

    /**
     * The files a test writes go to a folder of the SUITE's, never the shop's:
     * the test database is a different one, so its media ids collide with the
     * real ones, and a photo uploaded by a test landed inside a real product's
     * folder (found: 80 files in a folder that owns 4). Every disk that holds
     * the shop's or the engine's files is pointed under storage/framework/
     * testing, which a run may fill and anybody may empty.
     */
    protected function isolateFiles(): void
    {
        $root = storage_path('framework/testing/disks');

        foreach (['public', 'private', 'local', 'files-local-public', 'files-local-private'] as $disk) {
            $folder = $root.DIRECTORY_SEPARATOR.str_replace('files-local-', '', $disk);
            config(["filesystems.disks.$disk.driver" => 'local', "filesystems.disks.$disk.root" => $folder]);
            \Illuminate\Support\Facades\Storage::forgetDisk($disk);
        }
    }
}
