<?php

namespace App\Http\Controllers\Admin;

use App\Core\Files\FileStorage;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Where the shop's files live, said on a screen: which storage, which bucket,
 * how much is in it, and a button that writes a file and reads it back.
 *
 * Read-only on purpose. The choice and the keys are in .env (see
 * config/filesystems.php): a secret in the settings table is in every
 * database dump, and switching storage without moving the files first would
 * take every photograph off the site — so the switch is a deployment step,
 * with `torgash:files-copy` before it, and this screen says how.
 */
class StorageController extends Controller
{
    public function index(FileStorage $files)
    {
        return view('admin.storage.index', [
            'storage' => $files->describe(),
            'usage' => $this->usage(),
            'probes' => session('probes'),
        ]);
    }

    public function check(FileStorage $files)
    {
        $probes = [];
        foreach (FileStorage::DISKS as $disk) {
            $probes[$disk] = $files->probe($disk);
        }

        return redirect()->route('admin.storage')->with('probes', $probes);
    }

    /**
     * What is stored, by the rows that know about it — media, plus whatever an
     * owner counts for its declared folder (FileStorage::directories()) —
     * counted in the database, never by listing a bucket, which costs a
     * request per thousand files.
     *
     * @return array<string, array{files: int, bytes: int}>
     */
    protected function usage(): array
    {
        $usage = [];

        foreach (DB::table('media')->select('disk', DB::raw('count(*) as files'), DB::raw('coalesce(sum(size), 0) as bytes'))->groupBy('disk')->get() as $row) {
            $usage[$row->disk] = ['files' => (int) $row->files, 'bytes' => (int) $row->bytes];
        }

        foreach (app(FileStorage::class)->directories() as $declared) {
            if ($declared['files'] !== null) {
                $usage[$declared['disk']]['files'] = ($usage[$declared['disk']]['files'] ?? 0) + $declared['files'];
                $usage[$declared['disk']]['bytes'] = ($usage[$declared['disk']]['bytes'] ?? 0) + (int) $declared['bytes'];
            }
        }

        return $usage;
    }
}
