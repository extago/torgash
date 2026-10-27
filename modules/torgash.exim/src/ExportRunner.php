<?php

namespace Torgash\Exim;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Torgash\Exim\Models\EximProfile;
use Torgash\Exim\Renderers\CsvRenderer;
use Torgash\Exim\Renderers\JsonRenderer;
use Torgash\Exim\Renderers\RendererInterface;
use Torgash\Exim\Renderers\XmlRenderer;

class ExportRunner
{
    public function __construct(protected SchemaRegistry $schema) {}

    /**
     * Builds the profile's file on the private `local` disk and makes it the
     * one its feed address hands out, returning its relative path.
     *
     * One build of a profile at a time — the queue, "Download now" and a feed
     * fetched on request can all ask at once — so the others wait up to
     * `$wait` seconds. One that gets its turn and finds a file built since
     * `$unlessBuiltSince` takes that file: two fetchers arriving together
     * are handed one build.
     *
     * The data's fingerprint is taken BEFORE the data is read, so a change
     * made during the build makes the next check build again instead of
     * hiding behind this file; `last_run_at` is when the build STARTED, for
     * the same reason. Only the newest file is kept — every run used to leave
     * its file behind, an hourly feed twenty-four a day, for ever.
     *
     * @param  callable(int): void|null  $progress  told the rows written so far
     *
     * @throws LockTimeoutException another build kept the profile longer than $wait
     */
    public function run(EximProfile $profile, ?callable $progress = null, int $wait = 600, ?CarbonInterface $unlessBuiltSince = null): string
    {
        return Cache::lock('exim:build:'.$profile->id, 3600)->block($wait, function () use ($profile, $progress, $unlessBuiltSince) {
            $profile->refresh();

            if ($unlessBuiltSince && $profile->last_run_at?->gte($unlessBuiltSince) && ($current = $this->current($profile))) {
                return $current;
            }

            return $this->build($profile, $progress);
        });
    }

    /** The file the profile's feed address hands out now, or null when there is none yet. */
    public function current(EximProfile $profile): ?string
    {
        $path = $profile->last_file_path;

        return $path && Storage::disk('local')->exists($path) ? $path : null;
    }

    /** How many rows the profile's export holds — a build's progress bar. */
    public function count(EximProfile $profile): int
    {
        return $this->queryFor($profile)->count();
    }

    protected function build(EximProfile $profile, ?callable $progress): string
    {
        $started = now();
        $clock = microtime(true);
        $fingerprint = $profile->rebuild_on_change ? Fingerprint::of($profile->entity) : null;
        // To the millisecond: a second build within the same second must not
        // write over the file the first one is being read from.
        $path = "exim/{$profile->id}/".$started->format('YmdHisv').'.'.($profile->format ?: 'txt');

        $rows = 0;
        $stream = fopen('php://temp', 'w+');

        $counted = function () use (&$rows, $progress) {
            $rows++;

            if ($progress) {
                $progress($rows);
            }
        };

        foreach ($this->renderPieces($profile, $this->queryFor($profile), $counted) as $piece) {
            fwrite($stream, $piece);
        }

        $size = (int) ftell($stream);
        rewind($stream);
        Storage::disk('local')->put($path, $stream);
        fclose($stream);

        // Bookkeeping, not an edit of the profile: saved quietly, or the
        // audit trail grows a line per build and buries the real changes.
        $profile->forceFill([
            'last_run_at' => $started,
            'last_file_path' => $path,
            'last_rows' => $rows,
            'last_size' => $size,
            'last_duration_ms' => (int) round((microtime(true) - $clock) * 1000),
            'built_fingerprint' => $fingerprint,
        ])->saveQuietly();

        $this->sweep($profile, $path);

        return $path;
    }

    /**
     * The profile's older files go. One being read at this very moment stays
     * where the system will not delete an open file (Windows) — the next
     * build takes it.
     */
    protected function sweep(EximProfile $profile, string $keep): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files("exim/{$profile->id}") as $file) {
            if ($file === $keep) {
                continue;
            }

            try {
                $disk->delete($file);
            } catch (Throwable) {
                // Being read right now.
            }
        }
    }

    /**
     * @param  callable(): void|null  $onRow  told of every row as it is read
     * @return \Generator<string>
     */
    public function renderPieces(EximProfile $profile, $query, ?callable $onRow = null): \Generator
    {
        $fieldDefs = $this->columns($profile);

        $priceType = $this->resolvePriceType($profile);
        $context = ['priceType' => $priceType, 'profile' => $profile];

        // What the chosen columns read from other tables, loaded per chunk
        // rather than per row.
        $with = collect($fieldDefs)->pluck('with')->flatten()->filter()->unique()->values()->all();
        if ($with !== []) {
            $query->with($with);
        }

        $rows = (function () use ($query, $fieldDefs, $context, $onRow) {
            foreach ($query->lazyById(500) as $model) {
                $row = [];
                foreach ($fieldDefs as $f) {
                    $row[$f['key']] = ($f['get'])($model, $context);
                }

                if ($onRow) {
                    $onRow();
                }

                yield $row;
            }
        })();

        if ($profile->mode === 'template') {
            yield from (new TemplateRenderer)->render($profile, $rows, $fieldDefs);

            return;
        }

        yield from $this->rendererFor($profile->format)->render($rows, $fieldDefs);
    }

    /**
     * The columns this profile writes: its ticked fields in its languages.
     * A template gets every column the profile ticked — or all of them — so
     * whatever it names is there to be named.
     */
    public function columns(EximProfile $profile): array
    {
        $selected = (array) ($profile->fields ?? []);

        // A template reads what it names — a feed's pictures and
        // characteristics included, and nothing it does not. Ticked columns
        // still narrow it, except a list no box can tick (`template_only`).
        if ($profile->mode === 'template') {
            $declared = collect($this->schema->declared($profile->entity))->keyBy('key');
            $used = TemplateRenderer::usedKeys((string) $profile->template_body, $declared->all(), collect($this->schema->fieldsFor($profile->entity))->keyBy('key')->all());

            $selected = $selected === []
                ? $used
                : array_values(array_unique([...$selected, ...array_filter($used, fn ($key) => $declared->get($key)['template_only'] ?? false)]));

            if ($selected === []) {
                return [];
            }
        }

        return $this->schema->columnsFor($profile->entity, $selected, $profile->locales ?: null);
    }

    public function rendererFor(?string $format): RendererInterface
    {
        return match ($format) {
            'xml' => new XmlRenderer,
            'json' => new JsonRenderer,
            default => new CsvRenderer,
        };
    }

    protected function queryFor(EximProfile $profile)
    {
        $query = hook_filter("exim.query.{$profile->entity}", null, ['filters' => $profile->filters ?? []]);

        if ($query === null) {
            throw new \RuntimeException("No module answers exim.query.{$profile->entity} — is the owning module installed?");
        }

        return $query;
    }

    protected function resolvePriceType(EximProfile $profile): mixed
    {
        return $profile->price_type_id
            ? hook_filter('exim.resolve_price_type', null, ['id' => $profile->price_type_id])
            : null;
    }
}
