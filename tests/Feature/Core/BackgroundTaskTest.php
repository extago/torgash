<?php

namespace Tests\Feature\Core;

use App\Core\Tasks\TrackedJob;
use App\Models\BackgroundTask;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The tracking half of the queue: a task must be visible from the moment it is
 * started, must survive its own failure with a readable reason, and must not
 * hand the admin a message in the wrong language.
 */
class BackgroundTaskTest extends TestCase
{
    public function test_starting_a_task_records_it_before_the_worker_touches_it(): void
    {
        Queue::fake();

        $task = ProbeJob::start();

        $this->assertSame(BackgroundTask::QUEUED, $task->fresh()->status);
        $this->assertSame('Probe', $task->name);
        Queue::assertPushed(ProbeJob::class);
    }

    public function test_a_finished_task_reports_progress_and_a_result(): void
    {
        $task = ProbeJob::start();

        $this->assertSame(BackgroundTask::DONE, $task->fresh()->status);
        $this->assertSame(3, $task->fresh()->progress);
        $this->assertSame(3, $task->fresh()->total);
        $this->assertSame(['handled' => 3], $task->fresh()->result);
        $this->assertNotNull($task->fresh()->finished_at);
    }

    public function test_a_failing_task_keeps_the_reason_and_how_far_it_got(): void
    {
        try {
            FailingProbeJob::start();
        } catch (RuntimeException) {
            // The sync queue rethrows; a worker would record it in failed_jobs.
        }

        $task = BackgroundTask::latest('id')->first();

        $this->assertSame(BackgroundTask::FAILED, $task->status);
        $this->assertStringContainsString('probe failure', $task->error);
        $this->assertSame(2, $task->progress, 'the progress made before the failure was lost');
    }

    /**
     * The worker runs in the application's default locale, so a message
     * translated at write time would reach a Russian admin in English. Stored
     * as key + params, it can be rendered in whatever language is reading.
     */
    public function test_the_message_is_translated_when_it_is_read_not_when_it_is_written(): void
    {
        // A translation registered for this test only — the point is the
        // mechanism, not any particular string in the app's language files.
        app('translator')->addLines(['*.handled :count items' => 'обработано элементов: :count'], 'ru');

        $task = ProbeJob::start()->fresh();

        $this->assertSame('handled :count items', $task->message, 'the message was stored pre-translated');
        $this->assertSame(['count' => 3], $task->message_params);

        app()->setLocale('en');
        $this->assertSame('handled 3 items', $task->messageText());

        app()->setLocale('ru');
        $this->assertSame('обработано элементов: 3', $task->messageText());
    }

    public function test_percent_is_null_until_the_job_announces_a_total(): void
    {
        $task = new BackgroundTask(['status' => BackgroundTask::RUNNING, 'progress' => 5]);

        $this->assertNull($task->percent());

        $task->total = 20;
        $this->assertSame(25, $task->percent());
    }
}

class ProbeJob extends TrackedJob
{
    public function label(): string
    {
        return 'Probe';
    }

    protected function run(): void
    {
        $this->total(3);
        $this->progress(3);
        $this->result(['handled' => 3], 'handled :count items', ['count' => 3]);
    }
}

class FailingProbeJob extends TrackedJob
{
    public function label(): string
    {
        return 'Failing probe';
    }

    protected function run(): void
    {
        $this->total(4);
        $this->progress(2);

        throw new RuntimeException('probe failure');
    }
}
