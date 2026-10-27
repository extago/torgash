<?php

namespace Torgash\Cdek\Jobs;

use App\Core\Tasks\TrackedJob;
use Illuminate\Support\Facades\DB;
use Torgash\Cdek\Sync\CitySync;
use Torgash\Cdek\Sync\PlaceMatcher;

/**
 * Reconciles our registry with the carrier's directory — see PlaceMatcher.
 */
class MatchPlaces extends TrackedJob
{
    public function label(): string
    {
        return 'Match places to CDEK';
    }

    public function module(): ?string
    {
        return 'torgash.cdek';
    }

    protected function run(): void
    {
        $this->total(DB::table('m_locations_places')->whereIn('country', CitySync::countries())->count());
        $this->message('Indexing the carrier directory');

        $result = app(PlaceMatcher::class)->run(function (int $done, int $matched) {
            $this->progress($done);
            $this->message(':matched matched so far', ['matched' => $matched]);
        });

        $unmatched = $result['examined'] - $result['matched'];

        $this->result(
            $result + ['unmatched' => $unmatched],
            ':matched of :examined places matched, :unmatched left for you to bind by hand',
            ['matched' => $result['matched'], 'examined' => $result['examined'], 'unmatched' => $unmatched],
        );
    }
}
