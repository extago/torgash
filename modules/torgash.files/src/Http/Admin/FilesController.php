<?php

namespace Torgash\Files\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Torgash\Files\Library;
use Torgash\Files\Models\Folder;
use Torgash\Files\Models\StoredFile;

/**
 * The storage screen: one folder at a time (its folders, then its files),
 * or a search across all of them. Folders and files are rows (see the
 * migration), so every action here is a row update — except upload and
 * delete, which also move bytes.
 */
class FilesController extends Controller
{
    public const PER_PAGE = 120;

    public const SORTS = ['new', 'name'];

    public const VIEWS = ['grid', 'list'];

    public function index(Request $request)
    {
        $folder = $this->folderFrom($request->query('folder'));
        $q = trim((string) $request->query('q', ''));
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'new';

        // Tiles or a list: the staff member's choice, kept for the next visit.
        if (in_array($request->query('view'), self::VIEWS, true)) {
            $request->session()->put('files_view', $request->query('view'));
        }
        $view = in_array($request->session()->get('files_view'), self::VIEWS, true) ? $request->session()->get('files_view') : 'grid';

        $files = StoredFile::query()
            ->with('folder')
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->when($q === '', fn ($query) => $query->where('folder_id', $folder?->id))
            ->when($sort === 'name', fn ($query) => $query->orderBy('name'), fn ($query) => $query->orderByDesc('created_at'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $folders = $q !== '' ? collect() : Folder::query()
            ->where('parent_id', $folder?->id)
            ->withCount(['files', 'children'])
            ->orderBy('name')
            ->get();

        return view('torgash.files::admin.index', [
            'folder' => $folder,
            'trail' => $folder?->trail() ?? [],
            'folders' => $folders,
            'files' => $files,
            'q' => $q,
            'sort' => $sort,
            'view' => $view,
            'tree' => $this->tree(),
            'library' => app(Library::class),
        ]);
    }

    /**
     * Uploads, one file or many. The screen's uploader sends them one per
     * request (a progress bar each, one bad file does not sink the rest)
     * and asks for JSON; the plain form without JavaScript sends them all.
     */
    public function upload(Request $request)
    {
        $folder = $this->folderFrom($request->input('folder'));
        $library = app(Library::class);
        $files = $request->file('files');
        $files = is_array($files) ? $files : array_filter([$files]);
        $stored = [];
        $errors = [];

        foreach ($files as $file) {
            try {
                $stored[] = $library->upload($file, $folder, $request->user()?->id);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($files === []) {
            $errors[] = __('No file arrived. It may be bigger than the server accepts.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'stored' => count($stored),
                'errors' => $errors,
            ], $errors !== [] && $stored === [] ? 422 : 200);
        }

        $redirect = redirect()->route('admin.files.index', array_filter(['folder' => $folder?->id]));

        if ($stored !== []) {
            $redirect->with('status', trans_choice(':count file uploaded.|:count files uploaded.', count($stored)));
        }

        return $errors === [] ? $redirect : $redirect->withErrors(['files' => implode(' ', $errors)]);
    }

    public function createFolder(Request $request)
    {
        $parent = $this->folderFrom($request->input('parent'));

        try {
            $created = Folder::create([
                'parent_id' => $parent?->id,
                'name' => app(Library::class)->folderName((string) $request->input('name')),
            ]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('admin.files.index', ['folder' => $created->id])->with('status', __('Folder created.'));
    }

    public function renameFolder(Request $request, Folder $folder)
    {
        try {
            $folder->update(['name' => app(Library::class)->folderName((string) $request->input('name'))]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('status', __('Folder renamed.'));
    }

    /** A folder goes with everything in it — the screen says so before asking. */
    public function deleteFolder(Folder $folder)
    {
        $parent = $folder->parent_id;
        $folder->delete();

        return redirect()->route('admin.files.index', array_filter(['folder' => $parent]))->with('status', __('Folder deleted with everything in it.'));
    }

    /** The name the staff see. The link stays as it was — that is the promise. */
    public function rename(Request $request, StoredFile $file)
    {
        $name = trim((string) $request->input('name'));

        if ($name === '') {
            return back()->withErrors(['name' => __('A file needs a name.')]);
        }

        $file->update(['name' => Str::limit(str_replace(['/', '\\'], '-', $name), 200, '')]);

        return back()->with('status', __('File renamed. Its link has not changed.'));
    }

    public function destroy(StoredFile $file)
    {
        $file->delete();

        return back()->with('status', __('File deleted.'));
    }

    /** Ticked files: moved to a folder, or deleted. */
    public function bulk(Request $request)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
        $files = StoredFile::query()->whereIn('id', $ids)->get();

        if ($files->isEmpty()) {
            return back();
        }

        if ($request->input('action') === 'delete') {
            $files->each->delete();

            return back()->with('status', trans_choice(':count file deleted.|:count files deleted.', $files->count()));
        }

        if ($request->input('action') === 'move') {
            $target = $this->folderFrom($request->input('target'));
            StoredFile::query()->whereIn('id', $files->modelKeys())->update(['folder_id' => $target?->id, 'updated_at' => now()]);

            return back()->with('status', trans_choice(':count file moved. Links have not changed.|:count files moved. Links have not changed.', $files->count()));
        }

        return back();
    }

    /** "0", "" or a missing id is the top of the storage. */
    protected function folderFrom(mixed $id): ?Folder
    {
        $id = (int) $id;

        return $id > 0 ? Folder::query()->findOrFail($id) : null;
    }

    /**
     * Every folder, indented, for "Move to" — top first, children under
     * their parent in name order.
     *
     * @return list<array{id: int, label: string}>
     */
    protected function tree(): array
    {
        $byParent = Folder::query()->orderBy('name')->get(['id', 'parent_id', 'name'])->groupBy(fn ($f) => (int) $f->parent_id);
        $out = [];

        $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent) {
            foreach ($byParent->get($parent, collect()) as $folder) {
                $out[] = ['id' => (int) $folder->id, 'label' => str_repeat('— ', $depth).$folder->name];

                if ($depth < 20) {
                    $walk((int) $folder->id, $depth + 1);
                }
            }
        };
        $walk(0, 0);

        return $out;
    }
}
