<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Tasks\TrackedJob;
use App\Models\BackgroundTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The project has one place the framework scheduler is wired (CoreServiceProvider),
 * and modules add their periodic work to it through the `core.schedule` hook —
 * never by patching that file. A cron still has to call `schedule:run`; that is
 * a deployment step, not something this suite can assert.
 */
class ScheduleTest extends TestCase
{
    /** @return array<int, string> the description of every scheduled event */
    protected function scheduledNames(?Schedule $schedule = null): array
    {
        $schedule ??= $this->firedSchedule();

        return array_map(fn ($event) => (string) $event->description, $schedule->events());
    }

    /** A fresh Schedule with every core.schedule listener applied to it. */
    protected function firedSchedule(): Schedule
    {
        $schedule = new Schedule;
        hook_action('core.schedule', ['schedule' => $schedule]);

        return $schedule;
    }

    public function test_core_puts_its_own_upkeep_on_the_resolved_scheduler(): void
    {
        // Resolving the scheduler is what fires CoreServiceProvider's wiring.
        $names = $this->scheduledNames(app(Schedule::class));

        $this->assertContains('core:prune-background-tasks', $names);
    }

    public function test_a_module_extends_the_schedule_through_the_hook(): void
    {
        hook_listen('core.schedule', fn (array $c) => $c['schedule']
            ->call(fn () => null)->hourly()->name('probe:hooked'));

        $this->assertContains('probe:hooked', $this->scheduledNames());
    }

    public function test_nova_poshta_only_schedules_its_syncs_once_a_key_is_set(): void
    {
        $this->assertNotContains('novaposhta:sync-warehouses', $this->scheduledNames());

        app(SettingsManager::class)->set('torgash.novaposhta', 'api_key', 'test-key');

        $names = $this->scheduledNames();
        $this->assertContains('novaposhta:sync-warehouses', $names);
        $this->assertContains('novaposhta:sync-directory', $names);
    }

    public function test_exim_asks_every_minute_which_export_files_are_due(): void
    {
        $event = collect($this->firedSchedule()->events())->first(fn ($event) => $event->description === 'exim:rebuilds');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression, 'a cron expression is kept to the minute');
    }

    public function test_start_if_idle_will_not_stack_a_second_run(): void
    {
        Queue::fake();

        $first = ProbeIdleJob::startIfIdle();
        $this->assertNotNull($first);

        // Still QUEUED (the faked queue never runs it), so a second call backs off.
        $this->assertNull(ProbeIdleJob::startIfIdle());
        $this->assertSame(1, BackgroundTask::where('name', 'Probe idle')->count());

        // Once it is no longer active, another run is allowed.
        $first->update(['status' => BackgroundTask::DONE]);
        $this->assertNotNull(ProbeIdleJob::startIfIdle());
        $this->assertSame(2, BackgroundTask::where('name', 'Probe idle')->count());
    }
}

class ProbeIdleJob extends TrackedJob
{
    public function label(): string
    {
        return 'Probe idle';
    }

    protected function run(): void {}
}
