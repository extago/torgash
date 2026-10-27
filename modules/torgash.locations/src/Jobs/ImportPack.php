<?php

namespace Torgash\Locations\Jobs;

use App\Core\Tasks\TrackedJob;
use Torgash\Locations\PackImporter;

/**
 * A country pack is 33 000 rows for Ukraine and 192 000 for Russia — not
 * something to run inside the request that clicked the button.
 */
class ImportPack extends TrackedJob
{
    public function __construct(
        public string $statesFile,
        public string $citiesFile,
        public string $country,
        public bool $replace = false,
    ) {}

    public function label(): string
    {
        return 'Import locations';
    }

    public function module(): ?string
    {
        return 'torgash.locations';
    }

    protected function run(): void
    {
        $importer = app(PackImporter::class);

        $this->total($importer->countRows($this->citiesFile));
        $this->message('Country: :country', ['country' => $this->country]);

        $counts = $importer->import(
            $this->statesFile,
            $this->citiesFile,
            fn (int $done) => $this->progress($done),
            $this->replace,
        );

        $this->result($counts, $this->replace
            ? ':country — :deleted wiped, :created added'
            : ':country — :created added, :updated updated, :skipped kept as edited, :flagged no longer in the pack', [
                'country' => $this->country,
                'deleted' => $counts['deleted'],
                'created' => $counts['created'],
                'updated' => $counts['updated'],
                'skipped' => $counts['skipped'],
                'flagged' => $counts['flagged'],
            ]);
    }
}
