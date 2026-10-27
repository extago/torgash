<?php

namespace App\Http\Controllers\Admin;

use App\Core\Tasks\Tasks;
use App\Http\Controllers\Controller;
use App\Models\BackgroundTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The window into work that no longer happens inside a request: what was
 * started, how far it got, and what the error was when it did not finish.
 *
 * Failed queue jobs are listed alongside, because not everything worth seeing
 * is a tracked task — a queued order email that could not reach the mail server
 * leaves no task row, only a failed_jobs entry, and without this screen nobody
 * would ever learn the customer never got their confirmation.
 */
class TasksController extends Controller
{
    /** Tabs over the task rows, in the order drawn. */
    public const TABS = ['all', 'active', 'done', 'failed', 'jobs'];

    public function index(Request $request)
    {
        return view('admin.tasks.index', $this->data($request));
    }

    /** Poll target: the same list, rendered on its own for HTMX to swap in. */
    public function rows(Request $request)
    {
        return view('admin.tasks.partials.list', $this->data($request));
    }

    public function clear()
    {
        BackgroundTask::whereIn('status', BackgroundTask::FINISHED)->delete();

        return back()->with('status', __('Finished tasks cleared.'));
    }

    public function cancel(BackgroundTask $task)
    {
        $now = app(Tasks::class)->cancel($task, auth()->user()?->name);

        return back()->with('status', $now
            ? __('Task cancelled.')
            : __('Asked to stop — it will stop at its next step.'));
    }

    public function retry(string $uuid)
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('status', __('Job pushed back onto the queue.'));
    }

    public function forget(string $uuid)
    {
        DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        return back()->with('status', __('Failed job deleted.'));
    }

    public function retryAll()
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('status', __('Every failed job pushed back onto the queue.'));
    }

    public function forgetAll()
    {
        DB::table('failed_jobs')->delete();

        return back()->with('status', __('Failed jobs deleted.'));
    }

    protected function data(Request $request): array
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'all';
        $tasks = app(Tasks::class);

        // The newest two hundred, sorted into tabs here rather than in SQL: which
        // tab a running row belongs to depends on whether it went stale, and
        // that is a question for the model (BackgroundTask::tab()).
        $recent = BackgroundTask::with('user')->latest('id')->limit(200)->get();
        $counts = ['all' => $recent->count()] + $recent->countBy(fn (BackgroundTask $t) => $t->tab())->all();

        $failed = DB::table('failed_jobs')->orderByDesc('id')->limit(50)->get()
            ->map(function ($row) {
                $payload = json_decode($row->payload, true);
                $class = $payload['displayName'] ?? $row->uuid;

                // "OrderPlaced" → "Order placed": the list is read by a shop
                // owner, the class name stays underneath for whoever has to
                // find it in the code. Every letter is one class now
                // (EventMail), so a letter is named by its EVENT instead.
                $title = Str::ucfirst(Str::lower(Str::headline(class_basename($class))));
                if ($class === \App\Core\Mail\EventMail::class
                    && preg_match('/s:5:"event";s:\d+:"([^"]+)"/', (string) ($payload['data']['command'] ?? ''), $m)) {
                    $title = __('Letter: :name', ['name' => __(\App\Core\Mail\MailEvents::find($m[1])['label'] ?? $m[1])]);
                    $class .= ' ('.$m[1].')';
                }

                return (object) [
                    'uuid' => $row->uuid,
                    'class' => $class,
                    'title' => $title,
                    'failed_at' => \Illuminate\Support\Carbon::parse($row->failed_at),
                    'exception' => $row->exception,
                    // The first line is the message and the class that threw
                    // it; the rest is a stack trace nobody reads from a list.
                    'summary' => strtok($row->exception, "\n"),
                ];
            });

        return [
            'tab' => $tab,
            'counts' => $counts,
            'tasks' => $tab === 'all' ? $recent->take(50) : $recent->filter(fn ($t) => $t->tab() === $tab)->take(50),
            'failed' => $failed,
            'failedTotal' => $tasks->failedJobs(),
            'pending' => $tasks->pending(),
            'polling' => $recent->contains(fn ($t) => $t->isActive() && ! $t->isStale()),
            // Asked of the queue itself, not of the tracked rows: an order
            // email waiting for a worker is as much a sign as an import is.
            'workerSilent' => $tasks->workerSilent(),
        ];
    }
}
