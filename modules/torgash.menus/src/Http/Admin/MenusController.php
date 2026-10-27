<?php

namespace Torgash\Menus\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Menus\CategoryCounts;
use Torgash\Menus\ItemKinds;
use Torgash\Menus\MenuBuilder;
use Torgash\Menus\Placements;
use Torgash\Menus\Models\Menu;
use Torgash\Menus\Models\MenuItem;
use Torgash\Menus\ShopPages;
use Torgash\Menus\Shortcuts;

class MenusController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['enabled', 'disabled'], true) ? (string) $request->query('status') : '',
        ];

        // A shop has a handful: searched in PHP over the name in every
        // language and the code.
        $all = Menu::withCount('items')->orderBy('id')->get();
        $found = $filters['q'] === '' ? $all : $all->filter(fn (Menu $menu) => collect((array) $menu->name)
            ->push($menu->code)
            ->contains(fn ($text) => mb_stripos((string) $text, $filters['q']) !== false));

        return view('torgash.menus::admin.index', [
            'menus' => (match ($filters['status']) {
                'enabled' => $found->where('enabled', true),
                'disabled' => $found->where('enabled', false),
                default => $found,
            })->values(),
            'filters' => $filters,
            'statusCounts' => [
                '' => $found->count(),
                'enabled' => $found->where('enabled', true)->count(),
                'disabled' => $found->where('enabled', false)->count(),
            ],
            'placements' => app(Placements::class)->all(),
        ]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, Menu $menu)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $menu->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.menus::admin._status', ['menu' => $menu]);
    }

    /** Several at once, through the models so the audit hears each one. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $menus = Menu::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $menus->each->delete();

            return back()->with('status', trans_choice(':count menu deleted.|:count menus deleted.', $menus->count(), ['count' => $menus->count()]));
        }

        $menus->each(fn (Menu $menu) => $menu->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count menu updated.|:count menus updated.', $menus->count(), ['count' => $menus->count()]));
    }

    public function create()
    {
        return view('torgash.menus::admin.form', ['menu' => new Menu]);
    }

    public function store(Request $request)
    {
        $menu = Menu::create($this->menuData($request));

        return redirect()->route('admin.menus.items', $menu)->with('status', __('Menu created.'));
    }

    public function edit(Menu $menu)
    {
        return view('torgash.menus::admin.form', [
            'menu' => $menu->loadCount('items'),
            'placements' => app(Placements::class)->of($menu->code),
        ]);
    }

    public function update(Request $request, Menu $menu)
    {
        $menu->update($this->menuData($request, $menu));

        // Saving stays on the form; "Save and close" in the gear goes back.
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.menus.index')
            : redirect()->route('admin.menus.edit', $menu))->with('status', __('Menu updated.'));
    }

    public function destroy(Menu $menu)
    {
        $menu->delete();

        return redirect()->route('admin.menus.index')->with('status', __('Menu deleted.'));
    }

    /** The item tree for one menu — the screen an admin actually works on. */
    public function items(Menu $menu)
    {
        return view('torgash.menus::admin.items', [
            'menu' => $menu,
            'placements' => app(Placements::class)->of($menu->code),
            'byParent' => $menu->items()->with('sourceCategory')->get()->groupBy(fn (MenuItem $i) => (int) $i->parent_id),
        ]);
    }

    /**
     * The whole tree as the drag-and-drop left it: `items[<id>][parent]` and
     * `items[<id>][sort]` for the rows that moved. Checked as a whole — every
     * parent is a row of THIS menu and following parents from any row reaches
     * the root — because a drop the script got wrong must not cut a branch
     * off the tree, where the builder would never reach it again.
     */
    public function arrangeItems(Request $request, Menu $menu)
    {
        $rows = $menu->items()->get()->keyBy('id');
        $posted = array_map(fn ($place) => (array) $place, (array) $request->input('items', []));
        $parents = [];

        foreach ($posted as $id => $place) {
            $id = (int) $id;
            $parent = (int) ($place['parent'] ?? 0);

            if (! $rows->has($id) || ($parent !== 0 && ! $rows->has($parent))) {
                abort(422, __('The menu has changed since the page was opened — reload it.'));
            }

            $parents[$id] = $parent;
        }

        foreach ($rows as $row) {
            $parents[$row->id] ??= (int) $row->parent_id;
        }

        foreach (array_keys($parents) as $id) {
            for ($at = $id, $steps = 0; $at !== 0; $at = $parents[$at] ?? 0) {
                abort_if(++$steps > count($parents), 422, __('An item cannot be placed inside itself.'));
            }
        }

        foreach ($posted as $id => $place) {
            $this->place($rows[(int) $id], $parents[(int) $id], (int) ($place['sort'] ?? 0));
        }

        return $request->ajax()
            ? response()->noContent()
            : redirect()->route('admin.menus.items', $menu);
    }

    /**
     * The arrows beside a row, for a browser with no script and for a
     * keyboard: up and down among its siblings, "in" under the sibling above
     * (last of its children), "out" to just after its parent.
     */
    public function moveItem(Request $request, Menu $menu, MenuItem $item)
    {
        abort_unless($item->menu_id === $menu->id, 404);

        $rows = $menu->items()->get();
        $siblingsOf = fn (int $parent) => $rows->filter(fn (MenuItem $r) => (int) $r->parent_id === $parent)->values();
        $position = fn (int $parent, int $id) => (int) $siblingsOf($parent)->search(fn (MenuItem $r) => $r->id === $id);

        $parent = (int) $item->parent_id;
        $siblings = $siblingsOf($parent);
        $index = $position($parent, $item->id);
        $grandparent = (int) ($rows->firstWhere('id', $parent)?->parent_id ?? 0);

        [$newParent, $newIndex] = match ($request->input('direction')) {
            'up' => [$parent, max(0, $index - 1)],
            'down' => [$parent, $index + 1],
            'in' => $index > 0 ? [$siblings[$index - 1]->id, PHP_INT_MAX] : [$parent, $index],
            'out' => $parent !== 0 ? [$grandparent, $position($grandparent, $parent) + 1] : [$parent, $index],
            default => [$parent, $index],
        };

        $order = $siblingsOf($newParent)->reject(fn (MenuItem $r) => $r->id === $item->id)->values()->all();
        array_splice($order, min($newIndex, count($order)), 0, [$item]);

        foreach ($order as $at => $row) {
            $this->place($row, $newParent, ($at + 1) * 10);
        }

        return redirect()->to(route('admin.menus.items', $menu).'#item-'.$item->id);
    }

    /** Written through the model, and only when something moved — the audit sees each real change. */
    protected function place(MenuItem $row, int $parent, int $sort): void
    {
        $row->parent_id = $parent ?: null;
        $row->sort = $sort;

        if ($row->isDirty()) {
            $row->save();
        }
    }

    /**
     * What a category item would open into right now, from the form as it
     * stands (nothing saved): decided by the storefront's own sift, counted
     * fresh rather than from the menu's cache.
     */
    public function previewItem(Request $request, Menu $menu)
    {
        $item = new MenuItem([
            'kind' => 'category',
            'source_category_id' => $request->integer('source_category_id') ?: null,
            'options' => $this->categoryOptions($request),
        ]);
        $item->setRelation('sourceCategory', $item->source_category_id ? app(CategoryTree::class)->find($item->source_category_id) : null);

        app(CategoryCounts::class)->fresh();

        return view('torgash.menus::admin.partials.category-preview', [
            'item' => $item,
            'preview' => app(MenuBuilder::class)->explain($item),
        ]);
    }

    public function createItem(Menu $menu)
    {
        return view('torgash.menus::admin.item-form', $this->itemFormData($menu, new MenuItem));
    }

    public function storeItem(Request $request, Menu $menu)
    {
        $item = $menu->items()->create($this->itemData($request, $menu));

        return $request->input('after_save') === 'close'
            ? redirect()->to(route('admin.menus.items', $menu).'#item-'.$item->id)->with('status', __('Item created.'))
            : redirect()->route('admin.menus.items.edit', [$menu, $item])->with('status', __('Item created.'));
    }

    public function editItem(Menu $menu, MenuItem $item)
    {
        abort_unless($item->menu_id === $menu->id, 404);

        return view('torgash.menus::admin.item-form', $this->itemFormData($menu, $item));
    }

    public function updateItem(Request $request, Menu $menu, MenuItem $item)
    {
        abort_unless($item->menu_id === $menu->id, 404);
        $item->update($this->itemData($request, $menu, $item));

        return $request->input('after_save') === 'close'
            ? redirect()->to(route('admin.menus.items', $menu).'#item-'.$item->id)->with('status', __('Item updated.'))
            : redirect()->route('admin.menus.items.edit', [$menu, $item])->with('status', __('Item updated.'));
    }

    public function destroyItem(Menu $menu, MenuItem $item)
    {
        abort_unless($item->menu_id === $menu->id, 404);
        $item->delete();

        return redirect()->route('admin.menus.items', $menu)->with('status', __('Item deleted.'));
    }

    protected function itemFormData(Menu $menu, MenuItem $item): array
    {
        $tree = app(CategoryTree::class);

        return [
            'menu' => $menu,
            'item' => $item,
            'kinds' => app(ItemKinds::class)->options(admin_content_locale()),
            'pages' => app(ShopPages::class)->options(admin_content_locale()),
            'shortcuts' => app(Shortcuts::class)->options(),
            'categoryOptions' => $tree->options(),
            'categoryPicker' => collect($tree->options())->map(fn (string $label, int $id) => ['id' => $id, 'label' => $label])->values()->all(),
            'parentOptions' => $this->parentOptions($menu, $item),
        ];
    }

    /**
     * Every item of the menu, indented, minus this one and everything under
     * it — an item cannot be moved inside itself.
     *
     * @return list<array{id: int, label: string}>
     */
    protected function parentOptions(Menu $menu, MenuItem $item): array
    {
        $byParent = $menu->items()->with('sourceCategory')->get()->groupBy(fn (MenuItem $i) => (int) $i->parent_id);
        $out = [];

        $walk = function (int $parentId, int $depth, array $seen) use (&$walk, &$out, $byParent, $item) {
            foreach ($byParent->get($parentId, collect()) as $row) {
                if (($item->exists && $row->id === $item->id) || in_array($row->id, $seen, true)) {
                    continue;
                }

                $out[] = ['id' => $row->id, 'label' => str_repeat('— ', $depth).$row->adminLabel()];
                $walk($row->id, $depth + 1, [...$seen, $row->id]);
            }
        };

        $walk(0, 0, []);

        return $out;
    }

    protected function menuData(Request $request, ?Menu $menu = null): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('m_menus', 'code')->ignore($menu?->id)],
        ]);

        return [
            'name' => $this->mergeTranslatable($menu?->name, $validated['name'] ?? '', $locale),
            'code' => $validated['code'],
            'enabled' => $request->boolean('enabled'),
        ];
    }

    protected function itemData(Request $request, Menu $menu, ?MenuItem $item = null): array
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_keys(app(ItemKinds::class)->all()))],
            'name' => 'nullable|string|max:190',
            'url' => 'nullable|string|max:2000',
            'target' => 'nullable|string|max:190',
            'source_category_id' => 'nullable|exists:m_catalog_categories,id',
            'parent_id' => ['nullable', Rule::exists('m_menu_items', 'id')->where('menu_id', $menu->id)],
            'icon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'badge' => 'nullable|string|max:40',
            'note' => 'nullable|string|max:190',
            'badge_style' => ['nullable', Rule::in(MenuItem::BADGE_STYLES)],
            'show_on' => ['required', Rule::in(MenuItem::SHOW_ON)],
            'css_class' => 'nullable|string|max:190',
            'sort' => 'nullable|integer',
            'options.depth' => 'nullable|integer|min:0|max:3',
            'options.show_when' => ['nullable', Rule::in(CategoryCounts::RULES)],
            'options.sort' => ['nullable', Rule::in(['tree', 'name', 'count'])],
            'options.limit' => 'nullable|integer|min:0|max:100',
            'options.exclude' => 'nullable|array',
            'options.exclude.*' => 'integer',
        ]);

        $kind = $validated['kind'];

        if ($kind === 'page' && empty($validated['target'])) {
            throw ValidationException::withMessages(['target' => __('Choose the page this item leads to.')]);
        }

        // Two selects post into one column; only the one the kind shows counts.
        if ($kind === 'shortcut') {
            $validated['target'] = $request->input('shortcut_target');

            if (empty($validated['target'])) {
                throw ValidationException::withMessages(['shortcut_target' => __('Choose the shortcut.')]);
            }
        }

        if ($kind === 'link' && empty($validated['url'])) {
            throw ValidationException::withMessages(['url' => __('Type the address this item leads to.')]);
        }

        // Moving an item under one of its own descendants would cut the
        // branch off the tree: the builder would never reach it again.
        if ($item && ! empty($validated['parent_id']) && in_array((int) $validated['parent_id'], $this->branchIds($item), true)) {
            throw ValidationException::withMessages(['parent_id' => __('An item cannot be placed inside itself.')]);
        }

        $options = $kind === 'category' ? $this->categoryOptions($request) : null;

        $badge = $this->mergeTranslatable($item?->badge, $validated['badge'] ?? '', $locale);
        $note = $this->mergeTranslatable($item?->note, $validated['note'] ?? '', $locale);

        return [
            'kind' => $kind,
            'name' => $this->mergeTranslatable($item?->name, $validated['name'] ?? '', $locale),
            'url' => in_array($kind, ['link', 'category'], true) ? (($validated['url'] ?? null) ?: null) : null,
            'target' => in_array($kind, ['page', 'shortcut'], true) ? ($validated['target'] ?? null) : null,
            'source_category_id' => $kind === 'category' ? ($validated['source_category_id'] ?? null) : null,
            'options' => $options,
            'parent_id' => $validated['parent_id'] ?? null,
            'icon' => ($validated['icon'] ?? null) ?: null,
            'badge' => $badge === [] ? null : $badge,
            'note' => $note === [] ? null : $note,
            'badge_style' => $validated['badge_style'] ?? null,
            'highlight' => $request->boolean('highlight'),
            'show_on' => $validated['show_on'],
            'rules' => app(DisplayRules::class)->normalise($request->input('rules', [])),
            'rules_match' => $request->input('rules_match') === DisplayRules::MATCH_ANY ? DisplayRules::MATCH_ANY : DisplayRules::MATCH_ALL,
            'new_tab' => $request->boolean('new_tab'),
            'css_class' => ($validated['css_class'] ?? null) ?: null,
            'sort' => (int) ($validated['sort'] ?? 0),
            'enabled' => $request->boolean('enabled'),
        ];
    }

    /**
     * A category item's settings from the form — shared by saving and by the
     * preview, so the preview shows what Save would store. Validated before a
     * save; here every value is only forced into its range.
     *
     * @return array<string, mixed>
     */
    protected function categoryOptions(Request $request): array
    {
        $sort = (string) $request->input('options.sort', 'tree');
        $showWhen = (string) $request->input('options.show_when', 'always');

        return [
            'depth' => max(0, min(3, (int) $request->input('options.depth', 1))),
            'show_when' => in_array($showWhen, CategoryCounts::RULES, true) ? $showWhen : 'always',
            'hide_empty' => $request->boolean('options.hide_empty'),
            'counts' => $request->boolean('options.counts'),
            'sort' => in_array($sort, ['tree', 'name', 'count'], true) ? $sort : 'tree',
            'limit' => max(0, min(100, (int) $request->input('options.limit', 0))),
            'all_link' => $request->boolean('options.all_link'),
            'child_icons' => $request->boolean('options.child_icons'),
            'replace' => $request->boolean('options.replace'),
            'exclude' => array_values(array_unique(array_map('intval', (array) $request->input('options.exclude', [])))),
        ];
    }

    /** @return list<int> this item and everything beneath it */
    protected function branchIds(MenuItem $item): array
    {
        $byParent = MenuItem::query()->where('menu_id', $item->menu_id)->get(['id', 'parent_id'])->groupBy(fn ($i) => (int) $i->parent_id);
        $ids = [$item->id];

        for ($i = 0; $i < count($ids); $i++) {
            foreach ($byParent->get($ids[$i], collect()) as $child) {
                if (! in_array($child->id, $ids, true)) {
                    $ids[] = $child->id;
                }
            }
        }

        return $ids;
    }

    protected function mergeTranslatable(?array $existing, string $incoming, string $locale): array
    {
        $existing ??= [];

        if (trim($incoming) === '') {
            unset($existing[$locale]);
        } else {
            $existing[$locale] = $incoming;
        }

        return $existing;
    }
}
