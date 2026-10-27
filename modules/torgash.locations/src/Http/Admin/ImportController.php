<?php

namespace Torgash\Locations\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Locations\Jobs\ImportPack;
use Torgash\Locations\Models\Place;
use Torgash\Locations\PackImporter;

class ImportController extends Controller
{
    public function index()
    {
        return view('torgash.locations::admin.import', [
            'packs' => PackImporter::available($this->packsPath()),
            'counts' => Place::query()->selectRaw('country, count(*) as total')->groupBy('country')->pluck('total', 'country'),
            // What a wipe would destroy for good, per country: the pack can be
            // loaded again, these cannot.
            'ownCounts' => Place::query()->where(fn ($q) => $q->where('source', 'manual')->orWhere('edited', true))
                ->selectRaw('country, count(*) as total')->groupBy('country')->pluck('total', 'country'),
            'packsPath' => setting('torgash.locations', 'packs_path'),
        ]);
    }

    /** Imports a pack that is already on disk — the "drop the files in" route. */
    public function fromFolder(Request $request)
    {
        $validated = $request->validate(['country' => 'required|string|max:8', 'replace' => 'boolean']);
        $packs = PackImporter::available($this->packsPath());

        if (! isset($packs[$validated['country']])) {
            return back()->withErrors(['pack' => __('That pack is no longer in the folder.')]);
        }

        $pack = $packs[$validated['country']];
        ImportPack::start($pack['states'], $pack['cities'], $validated['country'], $request->boolean('replace'));

        return redirect()->route('admin.locations.import')
            ->with('status', __('Import queued — follow it under Background tasks.'));
    }

    /** Imports an uploaded pair of files, for a pack built somewhere else. */
    public function upload(Request $request)
    {
        $validated = $request->validate([
            'country' => 'required|string|size:2',
            'replace' => 'boolean',
            'states' => 'required|file|mimes:csv,txt',
            'cities' => 'required|file|mimes:csv,txt',
        ]);

        $country = strtoupper($validated['country']);

        // Moved out of the temporary upload directory first: the worker picks
        // the job up in another process, long after PHP has cleaned that up.
        $directory = storage_path('app/locations/'.$country);
        $request->file('states')->move($directory, "{$country}-states.csv");
        $request->file('cities')->move($directory, "{$country}-cities.csv");

        ImportPack::start("{$directory}/{$country}-states.csv", "{$directory}/{$country}-cities.csv", $country, $request->boolean('replace'));

        return redirect()->route('admin.locations.import')
            ->with('status', __('Import queued — follow it under Background tasks.'));
    }

    protected function packsPath(): string
    {
        return base_path((string) setting('torgash.locations', 'packs_path'));
    }
}
