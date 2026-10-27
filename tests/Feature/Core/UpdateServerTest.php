<?php

namespace Tests\Feature\Core;

use App\Core\Admin\AdminNotifications;
use App\Core\Updates\InstallUpdate;
use App\Core\Updates\UpdateServer;
use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The shop asking the update delivery service (PLAN §45.8): what is newer is
 * remembered and offered on the System page, a server that cannot be reached
 * says so, and a download that is not what the server announced is thrown
 * away before the Updater ever opens it.
 */
class UpdateServerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['torgash.version' => '0.1.0', 'torgash.updates.server' => 'https://uds.test/uds/torgash/']);
    }

    protected function offering(string $version = '0.2.0', string $sha = 'abc'): void
    {
        Http::fake(['uds.test/*' => Http::response([
            'product' => 'torgash', 'current' => '0.1.0', 'latest' => $version,
            'updates' => [['version' => $version, 'notes' => 'Faster checkout', 'size' => 3, 'sha256' => $sha,
                'download' => 'https://uds.test/uds/torgash/?action=download&product=torgash&version='.$version]],
        ])]);
    }

    public function test_a_newer_version_is_remembered_and_offered_on_the_system_page(): void
    {
        $this->offering();

        $found = app(UpdateServer::class)->check();

        $this->assertSame('0.2.0', $found['latest']);
        $this->assertSame('0.2.0', app(UpdateServer::class)->offer()['version']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'action=check') && str_contains($request->url(), 'version=0.1.0'));

        $this->actingAs(User::role('admin')->firstOrFail());
        $this->get('http://localhost/admin/system')->assertOk()
            ->assertSee('Torgash 0.2.0')->assertSee('Faster checkout')->assertSee(route('admin.system.updates.install'), false);
    }

    public function test_an_offer_older_than_this_code_is_not_offered(): void
    {
        $this->offering('0.2.0');
        app(UpdateServer::class)->check();

        config(['torgash.version' => '0.2.0']);
        $this->assertNull(app(UpdateServer::class)->offer());
    }

    public function test_a_server_that_cannot_be_reached_says_so(): void
    {
        Http::fake(['uds.test/*' => Http::response('down', 503)]);

        $found = app(UpdateServer::class)->check();

        $this->assertArrayHasKey('error', $found);
        $this->assertNull(app(UpdateServer::class)->offer());
    }

    public function test_a_download_that_is_not_what_was_announced_is_thrown_away(): void
    {
        $this->offering('0.2.0', str_repeat('0', 64));
        app(UpdateServer::class)->check();

        try {
            app(UpdateServer::class)->download(app(UpdateServer::class)->offer());
            $this->fail('A download with the wrong sum was kept.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
        }

        $this->assertFileDoesNotExist(storage_path('app/update/downloads/torgash-0.2.0.zip'));
    }

    public function test_install_is_refused_when_nothing_newer_is_on_offer(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->post('http://localhost/admin/system/updates/install')->assertNotFound();
    }

    public function test_the_bell_says_a_newer_version_waits_and_stops_once_it_is_installed(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $this->offering();
        app(UpdateServer::class)->check();

        $rows = collect(app(AdminNotifications::class)->all())->keyBy('key');
        $this->assertSame(1, $rows['core_update']['count'] ?? null);
        $this->assertStringContainsString('0.2.0', $rows['core_update']['label']);

        config(['torgash.version' => '0.2.0']);
        $this->assertArrayNotHasKey('core_update', collect(app(AdminNotifications::class)->all())->keyBy('key')->all());
    }

    public function test_the_owner_points_the_shop_at_another_server_and_back(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $this->offering();
        app(UpdateServer::class)->check();
        Http::fake(['mirror.test/*' => Http::response(['product' => 'torgash', 'latest' => '0.1.0', 'updates' => []])]);

        $this->post('http://localhost/admin/system/updates/server', ['address' => 'https://mirror.test/uds/torgash'])->assertSessionHasNoErrors();

        $server = app(UpdateServer::class);
        $this->assertSame('https://mirror.test/uds/torgash/', $server->url());
        $this->assertNull($server->last(), 'what the old server said is forgotten');
        $server->check();
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://mirror.test/uds/torgash/?action=check'));
        $this->get('http://localhost/admin/system')->assertOk()->assertSee('https://mirror.test/uds/torgash/');

        $this->post('http://localhost/admin/system/updates/server', ['address' => ''])->assertSessionHasNoErrors();
        $this->assertSame('https://uds.test/uds/torgash/', $server->url());
    }

    public function test_an_address_that_is_not_one_is_refused(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->post('http://localhost/admin/system/updates/server', ['address' => 'file:///etc/passwd'])->assertSessionHasErrors('address');
        $this->assertSame('https://uds.test/uds/torgash/', app(UpdateServer::class)->url());
    }

    public function test_the_install_a_rollback_brought_back_from_its_backup_installs_nothing(): void
    {
        // The rollback restored the database the install job had backed up:
        // its task says "running", its job waits to be handed out again.
        $storage = storage_path('framework/testing/rolled-back-'.bin2hex(random_bytes(4)));
        $this->app->useStoragePath($storage);
        @mkdir($storage.'/app/update/0.2.0', 0755, true);
        file_put_contents($storage.'/app/update/0.2.0/plan.json', json_encode(['from' => '0.1.0', 'to' => '0.2.0', 'state' => 'rolled back']));

        try {
            $this->offering();
            app(UpdateServer::class)->check();
            $ghost = new InstallUpdate;
            $task = BackgroundTask::create(['name' => $ghost->label(), 'status' => BackgroundTask::RUNNING]);
            $task->forceFill(['created_at' => now()->subMinutes(10)])->save();
            $ghost->taskId = $task->id;

            $ghost->handle();

            $this->assertSame('Not installed: the update was rolled back', $task->fresh()->message);
            Http::assertSentCount(1); // the check, no download

            // The System page writes such a row off, so Install is not held back by it.
            $task->refresh()->forceFill(['status' => BackgroundTask::RUNNING])->save();
            InstallUpdate::settleAfterRollback();
            $this->assertSame(BackgroundTask::CANCELLED, $task->fresh()->status);

            // One started after the rollback is the owner asking again.
            $fresh = BackgroundTask::create(['name' => $ghost->label(), 'status' => BackgroundTask::QUEUED]);
            InstallUpdate::settleAfterRollback();
            $this->assertSame(BackgroundTask::QUEUED, $fresh->fresh()->status);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($storage);
        }
    }

    public function test_the_scheduler_asks_once_a_day(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => $event->description === 'core:update-check');

        $this->assertCount(1, $events);
        $this->assertMatchesRegularExpression('/^\d+ 4 \* \* \*$/', $events->first()->expression);
    }
}
