<?php

namespace Tests\Feature\Core;

use App\Core\Tasks\Tasks;
use App\Core\Tasks\TrackedJob;
use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A task whose worker died stops claiming to run (and stops blocking its own
 * nightly restart), a queued one can be cancelled off the queue, and a running
 * one stops itself at its next step when asked.
 */
class TasksScreenTest extends TestCase
{
    protected function staleRow(string $name = 'Stale test task'): BackgroundTask
    {
        $task = BackgroundTask::create(['name' => $name, 'module' => 'tests', 'status' => BackgroundTask::RUNNING, 'started_at' => now()->subDays(7)]);
        DB::table('background_tasks')->where('id', $task->id)->update(['updated_at' => now()->subDays(7)]);

        return $task->fresh();
    }

    public function test_a_task_whose_worker_died_is_recognised_and_does_not_block_start_if_idle(): void
    {
        Queue::fake();
        $stale = $this->staleRow(TasksScreenProbe::LABEL);

        $this->assertTrue($stale->isStale());
        $this->assertSame('failed', $stale->tab());

        $started = TasksScreenProbe::startIfIdle();

        $this->assertNotNull($started);
        $this->assertSame(BackgroundTask::CANCELLED, $stale->fresh()->status);
    }

    public function test_a_queued_task_is_cancelled_off_the_queue(): void
    {
        config(['queue.default' => 'database']);
        $task = TasksScreenProbe::start();
        $this->assertSame(1, DB::table('jobs')->where('payload', 'like', '%TasksScreenProbe%')->count());

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/tasks/'.$task->id.'/cancel')
            ->assertRedirect();

        $this->assertSame(BackgroundTask::CANCELLED, $task->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->where('payload', 'like', '%TasksScreenProbe%')->count());
    }

    public function test_a_running_task_stops_itself_when_asked(): void
    {
        Queue::fake();
        $task = TasksScreenProbe::start();
        $task->update(['cancel_requested_at' => now()]);

        $job = new TasksScreenProbe;
        $job->taskId = $task->id;
        $job->handle();

        $task->refresh();
        $this->assertSame(BackgroundTask::CANCELLED, $task->status);
        $this->assertSame(0, TasksScreenProbe::$steps);
    }

    public function test_the_screen_and_its_tabs_render(): void
    {
        $this->staleRow();
        $admin = User::role('admin')->firstOrFail();

        foreach (['all', 'active', 'done', 'failed', 'jobs'] as $tab) {
            $this->actingAs($admin)->get('http://localhost/admin/tasks?tab='.$tab)->assertOk();
        }

        $this->assertGreaterThanOrEqual(1, app(Tasks::class)->staleCount());
    }
}

class TasksScreenProbe extends TrackedJob
{
    public const LABEL = 'Tasks screen probe';

    public static int $steps = 0;

    public function label(): string
    {
        return self::LABEL;
    }

    public function module(): ?string
    {
        return 'tests';
    }

    protected function run(): void
    {
        self::$steps++;
    }
}
