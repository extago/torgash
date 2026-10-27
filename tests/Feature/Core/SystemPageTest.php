<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Backups;
use App\Core\Settings\CoreSettings;
use App\Core\Settings\SettingsManager;
use App\Core\System\SystemFacts;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Admin → System (PLAN §45): what the server is, said honestly — including
 * that the scheduler or the queue is not running — and backups that hold the
 * database and a manifest, are pruned to the shop's number, and are only ever
 * reachable by their own name.
 */
class SystemPageTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_the_summary_says_what_the_server_is_and_what_is_wrong(): void
    {
        Cache::forget(SystemFacts::HEARTBEAT);

        $this->actingAs($this->admin)->get('http://localhost/admin/system')->assertOk()
            ->assertSee(config('torgash.version'))
            ->assertSee('PostgreSQL')
            ->assertSee(__('Cyrillic search works'))
            ->assertSee(__('The scheduler is not running: nightly jobs (backups, clean-ups, exchange rates) wait for a cron entry calling "artisan schedule:run" every minute.'));

        // A pulse a minute ago: the scheduler runs.
        Cache::forever(SystemFacts::HEARTBEAT, time() - 60);
        $this->assertNotContains(
            __('The scheduler is not running: nightly jobs (backups, clean-ups, exchange rates) wait for a cron entry calling "artisan schedule:run" every minute.'),
            app(SystemFacts::class)->warnings(),
        );
    }

    public function test_a_backup_holds_the_database_and_is_pruned_to_the_shops_number(): void
    {
        if (! Backups::toolVersion('pg_dump')) {
            $this->markTestSkipped('pg_dump is not available on this machine.');
        }

        Storage::fake('local');
        app(SettingsManager::class)->set(CoreSettings::ID, 'backup_disk', 'local');
        app(SettingsManager::class)->set(CoreSettings::ID, 'backup_keep', 1);

        $backups = app(Backups::class);

        Carbon::setTestNow('2026-10-01 03:40:00');
        $first = $backups->create(withFiles: false);
        Carbon::setTestNow('2026-10-02 03:40:00');
        $second = $backups->create(withFiles: false);
        Carbon::setTestNow();

        // Only the newest is kept.
        $this->assertSame([$second], $backups->all()->pluck('name')->all());
        $this->assertFalse($backups->exists($first));

        $zipPath = Storage::disk('local')->path(Backups::DIR.'/'.$second);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $this->assertNotFalse($zip->locateName('database.dump'));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame(['database'], $manifest['contents']);
        $this->assertSame(config('torgash.version'), $manifest['engine']);

        // The screen lists it; it downloads and deletes by its own name only.
        $this->actingAs($this->admin)->get('http://localhost/admin/system/backups')->assertOk()->assertSee($second);
        $this->get('http://localhost/admin/system/backups/'.$second)->assertOk()->assertDownload($second);
        $this->get('http://localhost/admin/system/backups/..%2F..%2F.env')->assertNotFound();
        $this->get('http://localhost/admin/system/backups/secrets.zip')->assertNotFound();

        $this->delete('http://localhost/admin/system/backups/'.$second)->assertRedirect();
        $this->assertTrue($backups->all()->isEmpty());
    }

    /**
     * "The backup is being made" used to stay on screen until the page was
     * reloaded by hand (found on the first live shop, 2026-10-22). While one
     * runs, the list asks for itself; once it is done the answer carries no
     * trigger and the asking stops — and the stale notice goes with it.
     */
    public function test_the_list_watches_a_running_backup_and_stops_when_it_is_done(): void
    {
        $task = \App\Models\BackgroundTask::create(['name' => 'Make a backup', 'status' => \App\Models\BackgroundTask::RUNNING]);

        $this->actingAs($this->admin)->get('http://localhost/admin/system/backups')->assertOk()
            ->assertSee('hx-trigger="every 3s"', false)
            ->assertSee('hx-select-oob="#backups-status"', false);

        $task->update(['status' => 'done']);

        $this->get('http://localhost/admin/system/backups')->assertOk()
            ->assertDontSee('hx-trigger="every 3s"', false)
            ->assertSee('id="backups-list"', false);
    }

    public function test_the_backup_settings_are_saved(): void
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/system/backups/settings', [
            'disk' => 'private', 'schedule' => 'weekly', 'keep' => 3, 'files' => 1,
        ])->assertRedirect();

        $this->assertSame('private', setting('core', 'backup_disk'));
        $this->assertSame('weekly', setting('core', 'backup_schedule'));
        $this->assertSame(3, setting('core', 'backup_keep'));
        $this->assertFalse((bool) setting('core', 'backup_code'));
    }

    public function test_a_customer_cannot_reach_it(): void
    {
        $customer = User::create(['name' => 'Shopper', 'email' => 'shopper-system@example.test', 'password' => bcrypt('x')]);

        $this->actingAs($customer)->get('http://localhost/admin/system')->assertForbidden();
    }
}
