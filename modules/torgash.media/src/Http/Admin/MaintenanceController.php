<?php

namespace Torgash\Media\Http\Admin;

use App\Http\Controllers\Controller;
use Torgash\Media\Jobs\RebuildThumbnails;

class MaintenanceController extends Controller
{
    /**
     * Hands the rebuild to the queue and returns immediately.
     *
     * The work itself — and the comment explaining why the sweep of stale
     * conversion files belongs with it — lives in RebuildThumbnails.
     */
    public function regenerate()
    {
        RebuildThumbnails::start();

        return back()->with('status', __('Rebuilding thumbnails in the background — follow it under Background tasks.'));
    }
}
