<?php

namespace Torgash\Media\Jobs;

use App\Models\BackgroundTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Torgash\Media\ImageProcessor;

/**
 * Photos by link for many records at once — an import's (PLAN §58) — as ONE
 * background task worked by several LANES in parallel.
 *
 * Measured on the live shop: shrinking a 13-megapixel original takes 0.84 s and
 * its three thumbnails 0.56 s, so 3 105 photos in one job were an hour and a
 * half, longer than any job may run. Here the work is split into lanes, each a
 * chain of short jobs: a lane does a few records, then queues its own next
 * step. That gives
 *  - parallelism as wide as the queue's workers (one worker: the lanes take
 *    turns; four: four photos are cut at once);
 *  - a queue that is never flooded — at most LANES of these wait at a time,
 *    so an order's confirmation letter queued meanwhile is not behind three
 *    thousand photos;
 *  - no job anywhere near its time limit.
 *
 * The task's row is shared by the lanes: each adds what it did under a row
 * lock (`result`: images, failed, skipped, lanes still going, the first
 * errors), and the last lane to finish marks it done. Stopping the task
 * (Background tasks → Stop) ends every lane at its next step.
 *
 * A link already in the gallery is never fetched twice: the media row keeps
 * the address it came from (`source_url`). What a row's links do with the
 * photos already there is the import's `mode`:
 *   empty    only a record with no photos gets them (the default — a second
 *            run of the same file does nothing);
 *   missing  the links not in the gallery yet are added;
 *   replace  the gallery becomes the file's links, in the file's order —
 *            photos it does not name are removed, but only once at least one
 *            of its links arrived (a dead supplier site empties nothing).
 */
class FetchImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** How many chains run at once — more than a small server's workers is harmless, they take turns. */
    public const LANES = 4;

    /** Records per step: a few seconds of work, then the queue gets a say. */
    public const PER_STEP = 5;

    /** Errors kept for the admin to read. */
    public const MAX_ERRORS = 30;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  list<array{type: string, id: int, collection?: string, urls: list<string>, mode?: string}>  $items
     */
    public function __construct(public int $taskId, public array $items) {}

    /**
     * Creates the task and its lanes.
     *
     * @param  list<array{type: string, id: int, collection?: string, urls: list<string>, mode?: string}>  $items
     */
    public static function start(array $items, ?int $userId = null): ?BackgroundTask
    {
        $items = array_values(array_filter($items, fn ($item) => ! empty($item['urls'])));

        if ($items === []) {
            return null;
        }

        $lanes = array_values(array_filter(array_map(
            fn (int $lane) => array_values(array_filter($items, fn ($item, $i) => $i % self::LANES === $lane, ARRAY_FILTER_USE_BOTH)),
            range(0, self::LANES - 1),
        )));

        $task = BackgroundTask::create([
            'name' => 'Import images',
            'module' => 'torgash.media',
            'status' => BackgroundTask::QUEUED,
            'user_id' => $userId ?? auth()->id(),
            'total' => array_sum(array_map(fn ($item) => count($item['urls']), $items)),
            'progress' => 0,
            'result' => ['images' => 0, 'failed' => 0, 'skipped' => 0, 'lanes' => count($lanes), 'products' => count($items), 'errors' => []],
            'message' => 'Photos for :count records',
            'message_params' => ['count' => count($items)],
        ]);

        foreach ($lanes as $lane) {
            dispatch(new self($task->id, $lane));
        }

        return $task;
    }

    public function handle(ImageProcessor $images): void
    {
        $task = BackgroundTask::find($this->taskId);

        if (! $task || in_array($task->status, BackgroundTask::FINISHED, true)) {
            return;
        }

        if ($task->cancel_requested_at !== null) {
            $this->finishLane(cancelled: true);

            return;
        }

        if ($task->status === BackgroundTask::QUEUED) {
            BackgroundTask::whereKey($this->taskId)->where('status', BackgroundTask::QUEUED)
                ->update(['status' => BackgroundTask::RUNNING, 'started_at' => now()]);
        }

        $step = array_slice($this->items, 0, self::PER_STEP);
        $rest = array_slice($this->items, self::PER_STEP);

        foreach ($step as $item) {
            // One broken record costs its own photos, never the rest of the lane.
            try {
                $outcome = $this->fetch($images, $item);
            } catch (Throwable $e) {
                $count = count((array) ($item['urls'] ?? []));
                $outcome = ['done' => $count, 'images' => 0, 'failed' => $count, 'skipped' => 0, 'errors' => [Str::limit($e->getMessage(), 300)]];
            }

            $this->report($outcome);
        }

        if ($rest !== []) {
            dispatch(new self($this->taskId, $rest));

            return;
        }

        $this->finishLane();
    }

    /** The worker gave up on this step (a time-out): the lane ends, the task does not hang. */
    public function failed(?Throwable $e): void
    {
        $this->report(['done' => 0, 'images' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => [Str::limit($e?->getMessage() ?? 'Job failed', 300)]]);
        $this->finishLane();
    }

    /**
     * One record's links.
     *
     * @return array{done: int, images: int, failed: int, skipped: int, errors: list<string>}
     */
    protected function fetch(ImageProcessor $images, array $item): array
    {
        $urls = array_values(array_unique(array_filter(array_map('trim', (array) ($item['urls'] ?? [])))));
        $out = ['done' => count($urls), 'images' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
        $collection = (string) ($item['collection'] ?? 'gallery');
        $mode = (string) ($item['mode'] ?? 'empty');

        $class = Relation::getMorphedModel((string) $item['type']) ?? (string) $item['type'];
        $model = class_exists($class) ? $class::query()->find($item['id']) : null;

        if (! $model instanceof HasMedia) {
            $out['skipped'] = count($urls);

            return $out;
        }

        $existing = $model->getMedia($collection);

        if ($mode === 'empty' && $existing->isNotEmpty()) {
            $out['skipped'] = count($urls);

            return $out;
        }

        $bySource = $existing->filter(fn (Media $m) => $m->getCustomProperty('source_url'))
            ->keyBy(fn (Media $m) => (string) $m->getCustomProperty('source_url'));
        // A link to one of its OWN photos — the shop's export coming back
        // from a spreadsheet — is that photo, not a new one to download.
        foreach ($existing as $own) {
            $bySource->put(url($own->getUrl()), $own);
        }
        $order = [];

        foreach ($urls as $url) {
            if ($bySource->has($url)) {
                $order[] = $bySource[$url]->id;
                $out['skipped']++;

                continue;
            }

            try {
                $media = $images->addFromUrl($model, $url, $collection);
                $media->setCustomProperty('source_url', $url)->save();
                $order[] = $media->id;
                $out['images']++;
            } catch (Throwable $e) {
                $out['failed']++;
                $out['errors'][] = Str::limit($url, 120).': '.Str::limit($e->getMessage(), 200);
            }
        }

        if ($mode === 'replace' && $order !== []) {
            foreach ($existing as $old) {
                if (! in_array($old->id, $order, true)) {
                    $old->delete();
                }
            }

            // The file's order: its first link is the main photo.
            Media::setNewOrder($order);
        }

        return $out;
    }

    /** Adds a step's numbers to the shared row, under a lock: the lanes write at the same moment. */
    protected function report(array $step): void
    {
        DB::transaction(function () use ($step) {
            $task = BackgroundTask::whereKey($this->taskId)->lockForUpdate()->first();

            if (! $task) {
                return;
            }

            $result = (array) $task->result;

            foreach (['images', 'failed', 'skipped'] as $key) {
                $result[$key] = (int) ($result[$key] ?? 0) + (int) ($step[$key] ?? 0);
            }

            $result['errors'] = array_slice([...(array) ($result['errors'] ?? []), ...$step['errors']], 0, self::MAX_ERRORS);

            $task->forceFill([
                'progress' => min((int) $task->total, (int) $task->progress + (int) $step['done']),
                'result' => $result,
            ])->save();
        });
    }

    /** One lane less; the last one closes the task. */
    protected function finishLane(bool $cancelled = false): void
    {
        DB::transaction(function () use ($cancelled) {
            $task = BackgroundTask::whereKey($this->taskId)->lockForUpdate()->first();

            if (! $task || in_array($task->status, BackgroundTask::FINISHED, true)) {
                return;
            }

            $result = (array) $task->result;
            $result['lanes'] = max(0, (int) ($result['lanes'] ?? 1) - 1);

            // The errors as the task screen reads its notes.
            $result['notes'] = array_map(fn ($error) => ['message' => ':error', 'params' => ['error' => $error]], (array) ($result['errors'] ?? []));
            $result['note_count'] = (int) ($result['failed'] ?? 0);

            $attributes = ['result' => $result];

            if ($result['lanes'] === 0) {
                $attributes += $cancelled || $task->cancel_requested_at !== null
                    ? ['status' => BackgroundTask::CANCELLED, 'finished_at' => now(), 'message' => 'Stopped on request', 'message_params' => null]
                    : [
                        'status' => BackgroundTask::DONE,
                        'finished_at' => now(),
                        'progress' => $task->total,
                        'message' => ':images photos added, :skipped already there, :failed could not be fetched',
                        'message_params' => ['images' => $result['images'] ?? 0, 'skipped' => $result['skipped'] ?? 0, 'failed' => $result['failed'] ?? 0],
                    ];
            }

            $task->forceFill($attributes)->save();
        });
    }
}
