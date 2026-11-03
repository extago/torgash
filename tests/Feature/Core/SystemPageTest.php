<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Backups;
use App\Core\Backups\Names;
use App\Core\System\SystemFacts;
use App\Models\BackupDestination;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Admin → System (PLAN §45): what the server is, said honestly — including
 * that the scheduler or the queue is not running — and backups made by plans
 * (PLAN §67) that hold the database and a manifest, are pruned to each
 * storage's number, and survive a storage that fails.
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

    public function test_a_plan_makes_a_backup_with_the_database_and_keeps_its_number(): void
    {
        if (! Backups::toolVersion('pg_dump')) {
            $this->markTestSkipped('pg_dump is not available on this machine.');
        }

        Storage::fake('local');
        $backups = app(Backups::class);
        $server = $backups->systemDestination();
        $plan = BackupPlan::create(['name' => 'Database', 'code' => 'db', 'contents' => ['database'], 'schedule' => [], 'name_template' => '{shop}-{plan}-{date}-{time}']);
        $plan->destinations()->attach($server->id, ['keep' => json_encode(['last' => 1])]);

        Carbon::setTestNow('2026-10-01 03:40:00');
        $first = $backups->runNow($plan);
        Carbon::setTestNow('2026-10-02 03:40:00');
        $second = $backups->runNow($plan);
        Carbon::setTestNow();

        $this->assertSame(BackupRun::DONE, $second->status, (string) $second->error);
        $this->assertSame(Names::shop().'-db-2026-10-02-034000.zip', $second->name);

        // Only the newest is kept — the older one is gone from the disk and the journal.
        $this->assertFalse(BackupRun::whereKey($first->id)->exists());
        $this->assertFalse(Storage::disk('local')->exists(Backups::DIR.'/'.$first->name));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path(Backups::DIR.'/'.$second->name)));
        $this->assertNotFalse($zip->locateName('database.dump'));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame(['database'], $manifest['contents']);
        $this->assertSame(config('torgash.version'), $manifest['engine']);
        $this->assertSame([$second->name], $backups->available()->where('name', $second->name)->pluck('name')->all());

        // The screen lists it; it downloads by its copy and deletes everywhere.
        $copy = $second->storedCopies()->firstOrFail();
        $this->actingAs($this->admin)->get('http://localhost/admin/system/backups')->assertOk()->assertSee($second->name);
        $this->get('http://localhost/admin/system/backups/copies/'.$copy->id)->assertOk()->assertDownload($second->name);
        $this->get('http://localhost/admin/system/backups/copies/999999')->assertNotFound();

        $this->delete('http://localhost/admin/system/backups/runs/'.$second->id)->assertRedirect();
        $this->assertFalse(BackupRun::whereKey($second->id)->exists());
        $this->assertFalse(Storage::disk('local')->exists(Backups::DIR.'/'.$second->name));
    }

    /** A storage that does not take the file: the run says where, the other copy is kept. */
    public function test_a_storage_that_fails_makes_the_run_partial_not_lost(): void
    {
        if (! Backups::toolVersion('pg_dump')) {
            $this->markTestSkipped('pg_dump is not available on this machine.');
        }

        Storage::fake('local');
        $backups = app(Backups::class);
        // A FILE where the folder should be: nothing can be written under it.
        $notAFolder = tempnam(sys_get_temp_dir(), 'bk');
        $broken = new BackupDestination(['name' => 'Broken folder', 'driver' => 'folder', 'settings' => ['path' => $notAFolder]]);
        $broken->save();

        $plan = BackupPlan::create(['name' => 'Twice', 'code' => 'twice', 'contents' => ['database'], 'schedule' => [], 'name_template' => '{plan}-{date}-{time}']);
        $plan->destinations()->attach([$backups->systemDestination()->id => ['keep' => '{}'], $broken->id => ['keep' => '{}']]);

        $run = $backups->runNow($plan);

        $this->assertSame(BackupRun::PARTIAL, $run->status, (string) $run->error);
        $this->assertStringContainsString('Broken folder', (string) $run->error);
        $this->assertSame(1, $run->storedCopies()->count());
        $this->assertTrue(Storage::disk('local')->exists(Backups::DIR.'/'.$run->name));
    }

    public function test_the_button_queues_a_run_of_the_plan_and_not_a_second_one(): void
    {
        $plan = BackupPlan::create(['name' => 'Queued', 'code' => 'queued', 'contents' => ['database'], 'schedule' => [], 'name_template' => '{plan}-{date}-{time}']);
        // A run already waiting: the button adds none.
        $plan->runs()->create(['reason' => 'schedule', 'status' => BackupRun::QUEUED]);

        $this->actingAs($this->admin)->post('http://localhost/admin/system/backups', ['plan' => $plan->id])->assertRedirect();

        $this->assertSame(1, $plan->runs()->count());
    }

    /** The settings of §45 became the plan "Main" on the server's folder. */
    public function test_the_old_settings_are_a_plan(): void
    {
        $main = BackupPlan::where('code', 'main')->firstOrFail();

        $this->assertSame('torgash-{date}-{time}', $main->name_template);
        $this->assertTrue($main->has('database'));
        $this->assertTrue($main->destinations()->where('system', true)->exists());
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

    public function test_a_customer_cannot_reach_it(): void
    {
        $customer = User::create(['name' => 'Shopper', 'email' => 'shopper-system@example.test', 'password' => bcrypt('x')]);

        $this->actingAs($customer)->get('http://localhost/admin/system')->assertForbidden();
    }
}
