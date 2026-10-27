<?php

namespace Torgash\Exim\Jobs;

use App\Core\Files\FileStorage;
use App\Core\Tasks\TrackedJob;
use Illuminate\Support\Facades\Storage;
use Torgash\Exim\Import\ImportFile;
use Torgash\Exim\ImportRunner;

/**
 * Commits a checked import in the background.
 *
 * The FILE travels, not its rows: the upload stays on the private disk until
 * this job has read it (`exim/imports/…`), and the mapping and options the
 * admin chose come along. Putting every row into the payload — what this job
 * used to do — makes a ten-thousand-line price list a ten-megabyte row in the
 * jobs table.
 *
 * Note on progress: ImportRunner::commit() wraps every row in one transaction,
 * so the ticks below are only visible once that transaction commits. Kept that
 * way deliberately — a half-applied price list is worse than a progress bar
 * that jumps at the end. Photos are not part of it: they are fetched by a task
 * of their own once the rows are in (torgash.media, "Import images").
 */
class RunImport extends TrackedJob
{
    /**
     * @param  string  $path  on the private disk
     * @param  list<string>  $mapping  field key per column, "" = skip
     */
    public function __construct(
        public string $entity,
        public string $path,
        public array $mapping,
        public array $options = [],
    ) {}

    /**
     * The entity goes in the message, not the name: the name is stored as the
     * English source string and translated when the list renders it, and an
     * entity glued into it would never match a translation key.
     */
    public function label(): string
    {
        return 'Import';
    }

    public function module(): ?string
    {
        return 'torgash.exim';
    }

    protected function run(): void
    {
        $storage = app(FileStorage::class);
        $local = $storage->localCopy('private', $this->path);

        try {
            $file = ImportFile::read($local, $this->options);
            $runner = app(ImportRunner::class);

            $this->total($file->count());
            $this->message('Entity: :entity', ['entity' => $this->entity]);

            $counts = $runner->commit(
                $this->entity,
                $runner->mappedRows($file, $this->entity, $this->mapping, (array) ($this->options['formulas'] ?? [])),
                fn (int $done) => $this->progress($done),
                $this->options,
            );
        } finally {
            $storage->release($local);
        }

        // Read, so no longer needed: an uploaded price list is the shop's
        // business data and does not stay on the server for nobody.
        Storage::disk('private')->delete($this->path);

        $batch = $counts['batch'];
        $images = count($batch->items('images'));

        $this->result([
            'created' => $counts['created'],
            'updated' => $counts['updated'],
            'skipped' => $counts['skipped'],
            'products_with_images' => $images,
            'notes' => $batch->notes(),
            'note_count' => $batch->noteCount(),
        ], $images > 0
            ? ':entity — :created created, :updated updated, :skipped skipped; photos for :images are being fetched'
            : ':entity — :created created, :updated updated, :skipped skipped', [
                'entity' => $this->entity,
                'created' => $counts['created'],
                'updated' => $counts['updated'],
                'skipped' => $counts['skipped'],
                'images' => $images,
            ]);
    }
}
