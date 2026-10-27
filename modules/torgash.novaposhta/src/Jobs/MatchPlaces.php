<?php

namespace Torgash\NovaPoshta\Jobs;

use App\Core\Tasks\TrackedJob;
use Illuminate\Support\Facades\DB;
use Torgash\NovaPoshta\Sync\PlaceMatcher;

/**
 * Reconciles our registry with the carrier's directory — see PlaceMatcher for
 * why this cannot be done by name and why it is done once rather than per
 * checkout.
 */
class MatchPlaces extends TrackedJob
{
    public function label(): string
    {
        return 'Match places to Nova Poshta';
    }

    public function module(): ?string
    {
        return 'torgash.novaposhta';
    }

    protected function run(): void
    {
        $this->total(DB::table('m_locations_places')->where('country', 'UA')->count());
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
