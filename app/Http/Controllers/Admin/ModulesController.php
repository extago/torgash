<?php

namespace App\Http\Controllers\Admin;

use App\Core\Access\AccessGuard;
use App\Core\Admin\AdminMenu;
use App\Core\Modules\ModuleArchive;
use App\Core\Modules\ModuleDocs;
use App\Core\Modules\ModuleManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Throwable;

class ModulesController extends Controller
{
    /**
     * Every module on disk, with what the screen filters by worked out once:
     * its state, the sidebar section it lives in (the settings screen's
     * answer, `AdminMenu::sectionOf`), and the words the search reads.
     */
    public function index(ModuleManager $modules)
    {
        $menu = app(AdminMenu::class);
        $headings = $menu->sectionHeadings();
        $rows = [];

        foreach ($modules->all() as $manifest) {
            $section = $menu->sectionOf($manifest);
            $rows[] = [
                'manifest' => $manifest,
                'state' => $this->state($modules, $manifest->id),
                'section' => $section,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp(label_text($a['manifest']->name), label_text($b['manifest']->name)));

        $counts = ['all' => count($rows)] + array_count_values(array_column($rows, 'state'));

        return view('admin.modules.index', [
            'modules' => $modules,
            'rows' => $rows,
            'counts' => $counts,
            'sections' => array_intersect_key($headings, array_flip(array_unique(array_column($rows, 'section')))),
            'canUpload' => app(AccessGuard::class)->isSuper(auth()->user()),
            // The countries some module is made for, and the shop's own picked
            // first — so a Ukrainian shop does not scroll past other countries'.
            'regions' => collect($modules->all())->flatMap(fn ($m) => $m->regions)->unique()->sort()->values()->all(),
            'shopCountry' => strtoupper((string) setting(\App\Core\Settings\CoreSettings::ID, 'default_country', '')) ?: null,
        ]);
    }

    /**
     * The module's own picture, for its tile. Any module on disk — not only an
     * enabled one, since the screen shows what could be installed too — and only
     * the one file its manifest names inside its assets/.
     *
     * A third party's SVG is a document that can carry script, so it is sent
     * with a sandboxing policy: drawn in an <img> it could not run anyway, and
     * opened on its own it still cannot reach the admin's session.
     */
    public function logo(ModuleManager $modules, string $id)
    {
        $file = $modules->find($id)?->logoFile();
        abort_unless($file, 404);

        $types = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

        return response()->file($file, [
            'Content-Type' => $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))],
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    protected function state(ModuleManager $modules, string $id): string
    {
        return match (true) {
            ! $modules->isInstalled($id) => 'available',
            $modules->isEnabled($id) => 'enabled',
            default => 'disabled',
        };
    }

    /**
     * A module's own documentation — the Markdown it ships in docs/, one page
     * per side tab. Shown for any discovered module, installed or not: "what is
     * this and how do I set it up" is most useful before you commit to it.
     */
    public function docs(ModuleManager $modules, string $id)
    {
        $manifest = $modules->find($id);
        abort_unless($manifest, 404);

        $docs = new ModuleDocs($manifest);
        $pages = $docs->pages(app()->getLocale());
        abort_if($pages === [], 404);

        $slug = (string) request('doc', $pages[0]['slug']);
        $html = $docs->render($slug, app()->getLocale());
        abort_if($html === null, 404);

        return view('admin.modules.docs', [
            'manifest' => $manifest,
            'pages' => $pages,
            'active' => $slug,
            'html' => $html,
        ]);
    }

    /** An image embedded by a docs page, served from inside the module's docs/. */
    public function docsMedia(ModuleManager $modules, string $id, string $path)
    {
        $manifest = $modules->find($id);
        abort_unless($manifest, 404);

        $file = (new ModuleDocs($manifest))->media($path);
        abort_unless($file, 404);

        return response()->file($file);
    }

    /**
     * A module as a .zip: put into modules/ (or replacing an older version of
     * itself), not installed — installing stays the row's own button, so the
     * person uploading sees what arrived before it touches the database.
     *
     * Only the built-in Administrator group: this writes PHP onto the server,
     * which is more power than any permission the shop can hand out.
     */
    public function upload(Request $request, ModuleArchive $archive)
    {
        abort_unless(app(AccessGuard::class)->isSuper($request->user()), 403);

        $request->validate([
            'archive' => ['required', 'file', 'max:20480', 'mimes:zip'],
        ]);

        try {
            $result = $archive->put($request->file('archive')->getRealPath());
        } catch (Throwable $e) {
            return back()->withErrors(['archive' => $e->getMessage()]);
        }

        return redirect()->to(route('admin.modules').'#module-'.str_replace('.', '-', $result['id']))->with('status', $result['replaced']
            ? __(':module updated from :from to :to.', ['module' => label_text($result['name']), 'from' => $result['replaced'], 'to' => $result['version']])
            : __(':module :version is in place — install it below.', ['module' => label_text($result['name']), 'version' => $result['version']]));
    }

    public function install(ModuleManager $modules, string $id)
    {
        return $this->attempt($modules, $id, fn () => $modules->install($id));
    }

    public function uninstall(ModuleManager $modules, string $id)
    {
        return $this->attempt($modules, $id, fn () => $modules->uninstall($id));
    }

    public function toggle(ModuleManager $modules, string $id)
    {
        return $this->attempt($modules, $id, fn () => $modules->setEnabled($id, ! $modules->isEnabled($id)));
    }

    /**
     * Dependency rules are enforced by throwing, and this screen is the only
     * place a person can hit them — so show the reason in the row instead of
     * letting HTMX swap in a 500 page.
     */
    protected function attempt(ModuleManager $modules, string $id, callable $action)
    {
        try {
            $action();

            return $this->row($modules, $id);
        } catch (Throwable $e) {
            return $this->row($modules, $id, $e->getMessage());
        }
    }

    /** HTMX swaps a single table row after an action. */
    protected function row(ModuleManager $modules, string $id, ?string $error = null)
    {
        $manifest = $modules->find($id);

        return view('admin.modules._row', [
            'modules' => $modules,
            'manifest' => $manifest,
            'moduleState' => $this->state($modules, $id),
            'moduleSection' => app(AdminMenu::class)->sectionOf($manifest),
            'sections' => app(AdminMenu::class)->sectionHeadings(),
            'error' => $error,
        ]);
    }
}
