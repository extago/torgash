<?php

namespace Tests\Feature\Core;

use App\Core\Tasks\TrackedJob;
use App\Models\BackgroundTask;
use App\Models\User;
use Tests\TestCase;

/**
 * A bulk job hands over to a continuation before the queue's timeout kills
 * it mid-item ("Rebuild thumbnails" on the live shop, TimeoutExceededException);
 * the parts share one tracking row and it is done only after the last.
 */
class TaskContinuationTest extends TestCase
{
    public function test_parts_share_one_row_which_is_done_only_after_the_last(): void
    {
        ChunkedTestJob::$seen = [];
        $task = ChunkedTestJob::start();

        $this->assertSame([1, 2, 3, 4, 5], ChunkedTestJob::$seen);
        $this->assertSame(5, ChunkedTestJob::$parts, 'one item per part: budget 0');
        $task->refresh();
        $this->assertSame(BackgroundTask::DONE, $task->status);
        $this->assertSame(5, (int) $task->progress);
        $this->assertSame(1, BackgroundTask::where('name', 'Chunked test job')->count());
    }

    public function test_the_tasks_list_reloads_only_by_its_poll_never_on_a_click(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        // An hx-get with no trigger fires on CLICK for a div: every click in
        // the list reloaded it and closed the error that had just opened.
        $this->get('http://localhost/admin/tasks?tab=jobs')->assertOk()
            ->assertDontSee('hx-get="'.route('admin.tasks.rows', ['tab' => 'jobs']).'"', false);
    }
}

class ChunkedTestJob extends TrackedJob
{
    public static array $seen = [];

    public static int $parts = 0;

    public int $budget = 0;

    public function __construct(public int $after = 0) {}

    public function label(): string
    {
        return 'Chunked test job';
    }

    protected function run(): void
    {
        self::$parts = $this->after === 0 ? 1 : self::$parts + 1;
        $this->total(5);

        foreach (range($this->after + 1, 5) as $item) {
            self::$seen[] = $item;
            $this->write(['progress' => $item]);

            if ($item < 5 && $this->timeIsUp()) {
                $this->continueWith(new static($item));

                return;
            }
        }
    }
}
