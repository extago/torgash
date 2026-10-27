<?php

namespace App\Core\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * The demo shop (PLAN §55): installed from `database/demo/`, removed again
 * without touching anything the owner has added since.
 *
 * Core owns only the bookkeeping. Each module loads ITS part of the pack —
 * the catalogue its products, the blog its articles — through the
 * `core.demo.parts` registry, so core names no module's tables and a module
 * that is not installed simply has no part. While a part runs, every Eloquent
 * model created is written down in `demo_records`; removal walks that list
 * backwards (children before their parents) and deletes each through its
 * model, so media files, pivots and search rows go with it the way they go
 * when the owner deletes by hand.
 *
 *     hook_listen('core.demo.parts', fn (array $parts) => $parts + [
 *         'catalog' => ['label' => 'Catalogue', 'after' => [], 'install' => fn (DemoPack $pack) => …],
 *     ]);
 */
class DemoData
{
    public const HOOK = 'core.demo.parts';

    protected bool $recording = false;

    protected bool $listening = false;

    public function path(string $file = ''): string
    {
        return base_path('database/demo'.($file !== '' ? '/'.ltrim($file, '/') : ''));
    }

    public function installed(): bool
    {
        return DB::table('demo_records')->exists();
    }

    public function count(): int
    {
        return DB::table('demo_records')->count();
    }

    /**
     * The ids of one model the pack made — for a part that works on what an
     * earlier part created (the stock of the demo's products), never on the
     * owner's own rows.
     *
     * @param  class-string<Model>  $class
     * @return list<string>
     */
    public function ids(string $class): array
    {
        return DB::table('demo_records')->where('model_type', $class)->orderBy('id')->pluck('model_id')->all();
    }

    /**
     * The registered parts, each after the ones it names in `after`.
     *
     * @return array<string, array{label: string, after: list<string>, install: callable}>
     */
    public function parts(): array
    {
        $parts = array_filter((array) hook_filter(self::HOOK, []), fn ($part) => is_array($part) && is_callable($part['install'] ?? null));
        $ordered = [];
        $visit = function (string $key, array $trail = []) use (&$visit, &$ordered, $parts) {
            if (isset($ordered[$key]) || ! isset($parts[$key]) || in_array($key, $trail, true)) {
                return;
            }
            foreach ((array) ($parts[$key]['after'] ?? []) as $before) {
                $visit((string) $before, [...$trail, $key]);
            }
            $ordered[$key] = $parts[$key] + ['label' => $key, 'after' => []];
        };
        foreach (array_keys($parts) as $key) {
            $visit((string) $key);
        }

        return $ordered;
    }

    /**
     * Every part, in order, each written down as it goes. A part that fails
     * stops the install; what was created up to then stays recorded, so
     * remove() clears a half-made demo as well as a whole one.
     *
     * `$only` names the parts to install — one a module brought after the
     * demo was put in; null is all of them.
     *
     * @param  (callable(string): void)|null  $say
     * @param  list<string>|null  $only
     * @return list<string> the parts installed
     */
    public function install(?callable $say = null, ?array $only = null): array
    {
        $pack = new DemoPack($this->path(), $say);
        $done = [];

        foreach ($this->parts() as $key => $part) {
            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }

            $pack->say(label_text($part['label']).'…');
            $this->record(fn () => ($part['install'])($pack));
            $done[] = $key;
        }

        return $done;
    }

    /**
     * Everything the pack created, newest first, through its own model.
     *
     * @return array{removed: int, failed: int}
     */
    public function remove(): array
    {
        $removed = 0;
        $failed = 0;

        foreach (DB::table('demo_records')->orderByDesc('id')->get() as $row) {
            try {
                $removed += $this->forget($row->model_type, (string) $row->model_id) ? 1 : 0;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Demo data: a record could not be removed', ['type' => $row->model_type, 'id' => $row->model_id, 'error' => $e->getMessage()]);
            }
            DB::table('demo_records')->where('id', $row->id)->delete();
        }

        return ['removed' => $removed, 'failed' => $failed];
    }

    /** Runs $work with every model it creates written down. */
    public function record(callable $work): mixed
    {
        $this->listen();
        $this->recording = true;

        try {
            return $work();
        } finally {
            $this->recording = false;
        }
    }

    /** One model written down by hand — one made some other way than `created`. */
    public function mark(Model $model): void
    {
        DB::table('demo_records')->insertOrIgnore([
            'model_type' => $model::class,
            'model_id' => (string) $model->getKey(),
            'created_at' => now(),
        ]);
    }

    protected function listen(): void
    {
        if ($this->listening) {
            return;
        }
        $this->listening = true;

        Event::listen('eloquent.created: *', function (string $event, array $models) {
            if (! $this->recording) {
                return;
            }
            foreach ($models as $model) {
                if ($model instanceof Model && $model->getKey() !== null) {
                    $this->mark($model);
                }
            }
        });
    }

    protected function forget(string $class, string $id): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        $model = $class::query()->withoutGlobalScopes()->find($id);

        if (! $model) {
            return false; // gone already — with its parent, or by the owner's hand
        }

        in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model->forceDelete() : $model->delete();

        return true;
    }
}
