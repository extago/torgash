<?php

namespace Torgash\Catalog\Http\Admin;

use App\Http\Controllers\Controller;
use Torgash\Catalog\Jobs\FillWeightsFromFeature;

class MaintenanceController extends Controller
{
    /**
     * Hands the backfill to the queue and returns immediately — a catalogue of
     * any size outlives the request that pressed the button.
     */
    public function fillWeights()
    {
        if (! trim((string) setting('torgash.catalog', 'weight_feature', ''))) {
            return back()->withErrors([
                'weight_feature' => __('Name the characteristic that holds the weight first, and save.'),
            ]);
        }

        FillWeightsFromFeature::start();

        return back()->with('status', __('Filling weights in the background — follow it under Background tasks.'));
    }
}
