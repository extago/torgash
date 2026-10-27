<?php

namespace Torgash\Menus;

use App\Core\Display\DisplayRules;
use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Menus\Models\Menu;
use Torgash\Menus\Models\MenuItem;

/**
 * A menu as the page draws it: the shop's rows turned into Nodes (PLAN §39).
 *
 * Everything a template needs is decided HERE, once — which rows this visitor
 * may see (the row's switch, core's display rules), what a kind turns into, how
 * far a category opens and which of its subcategories survive the availability
 * rule, and which entry is the page being read. A look only draws.
 *
 * Two queries for EVERY menu on the page — the enabled menus, then all their
 * enabled rows at once (the trees assembled in PHP, so manual nesting has no
 * depth limit and costs nothing extra); a row's category comes from the tree
 * the page already reads, and at most one counts query covers the whole page
 * (CategoryCounts). A page draws six menus or more (header, catalogue button,
 * strip, footer columns, phone tab bar), and asking per menu cost fourteen
 * queries. Memoised for the request; per request, never static.
 */
class MenuBuilder
{
    /** @var array<string, list<Node>> */
    protected array $built = [];

    /** @var array<int, Collection<int, Category>>|null parent id (0 = root) => listed children */
    protected ?array $children = null;

    /** @var array<string, Menu>|null code => enabled menu */
    protected ?array $menus = null;

    /** @var array<int, Collection<int, MenuItem>>|null menu id => its enabled rows, in order */
    protected ?array $rows = null;

    public function __construct(
        protected ItemKinds $kinds,
        protected CategoryCounts $counts,
        protected DisplayRules $rules,
    ) {}

    /** @return list<Node> — empty for a menu that does not exist or is switched off */
    public function nodes(string $code): array
    {
        if (isset($this->built[$code])) {
            return $this->built[$code];
        }

        $menu = $this->enabledMenus()[$code] ?? null;

        if (! $menu) {
            return $this->built[$code] = [];
        }

        $byParent = $this->rowsOf($menu)->groupBy(fn (MenuItem $i) => (int) $i->parent_id);

        $nodes = $this->level($byParent, 0, []);
        $this->markActive($nodes);

        return $this->built[$code] = $nodes;
    }

    /**
     * Whether a menu with this code is there to be drawn — the header's
     * fallback asks this, not "is the list empty": a menu the shop emptied on
     * purpose must not bring the automatic one back.
     */
    public function exists(string $code): bool
    {
        return isset($this->enabledMenus()[$code]);
    }

    /** @return array<string, Menu> */
    protected function enabledMenus(): array
    {
        return $this->menus ??= Menu::query()->where('enabled', true)->get()->keyBy('code')->all();
    }

    /**
     * The menu's enabled rows — loaded for every enabled menu the first time
     * any is asked, since a page that draws one menu draws five more. The
     * category a row points at is the tree's own object (disabled ones
     * included, so categoryNode() can still tell "outlived its target").
     *
     * @return Collection<int, MenuItem>
     */
    protected function rowsOf(Menu $menu): Collection
    {
        if ($this->rows === null) {
            $tree = app(CategoryTree::class);

            $this->rows = MenuItem::query()
                ->whereIn('menu_id', array_map(fn (Menu $m) => $m->id, $this->enabledMenus()))
                ->where('enabled', true)
                ->orderBy('sort')->orderBy('id')
                ->get()
                ->each(fn (MenuItem $i) => $i->setRelation('sourceCategory', $i->source_category_id ? $tree->find((int) $i->source_category_id) : null))
                ->groupBy('menu_id')
                ->all();
        }

        return $this->rows[$menu->id] ?? new Collection;
    }

    /** @return array<string, string> code => name, for a select */
    public function options(?string $locale = null): array
    {
        return Menu::query()->orderBy('id')->get()
            ->mapWithKeys(fn (Menu $m) => [$m->code => (string) ($m->t('name', $locale) ?: $m->code)])
            ->all();
    }

    /**
     * @param  Collection<int, Collection<int, MenuItem>>  $byParent
     * @param  list<int>  $seen  guards a looping parent_id — a corrupt row, not an impossible one
     * @return list<Node>
     */
    protected function level(Collection $byParent, int $parentId, array $seen): array
    {
        $out = [];

        foreach ($byParent->get($parentId, collect()) as $item) {
            if (in_array($item->id, $seen, true)) {
                continue;
            }

            if (! $this->rules->passes((array) ($item->rules ?? []), [], (string) ($item->rules_match ?: DisplayRules::MATCH_ALL))) {
                continue;
            }

            $kind = $this->kinds->find($item->kind);
            $resolved = $kind ? ($kind['resolve'])($item, $this) : null;

            if ($resolved === null) {
                continue;
            }

            $children = array_merge(
                $resolved['children'] ?? [],
                $this->level($byParent, $item->id, [...$seen, $item->id]),
            );

            if (! empty($resolved['replace'])) {
                array_push($out, ...$children);

                continue;
            }

            $typed = $item->typedName();
            $label = $typed !== '' ? $typed : (string) ($resolved['label'] ?? '');

            // A heading with nothing under it and no words is not worth a row.
            if ($item->kind === 'heading' && $label === '' && $children === []) {
                continue;
            }

            $href = $item->kind === 'category' && $item->url ? $item->url : ($resolved['href'] ?? null);
            $badge = trim((string) ($item->t('badge') ?? ''));
            $note = trim((string) ($item->t('note') ?? ''));

            $out[] = new Node(
                id: 'm'.$item->id,
                kind: $item->kind,
                label: $label,
                href: $href,
                icon: $this->icon($item->icon) ?? $this->icon($resolved['icon'] ?? null),
                badge: $badge !== '' ? $badge : null,
                badgeStyle: in_array($item->badge_style, MenuItem::BADGE_STYLES, true) ? $item->badge_style : 'primary',
                highlight: (bool) $item->highlight,
                showOn: in_array($item->show_on, MenuItem::SHOW_ON, true) ? $item->show_on : 'all',
                newTab: (bool) $item->new_tab,
                cssClass: $item->css_class,
                count: $resolved['count'] ?? null,
                children: $children,
                active: (bool) ($resolved['active'] ?? false),
                note: $note !== '' ? $note : ($resolved['note'] ?? null),
                key: $resolved['key'] ?? null,
                action: $resolved['action'] ?? null,
                counter: isset($resolved['counter']) ? (int) $resolved['counter'] : null,
                // A typed icon is the shop's choice for THIS item and beats the category's picture.
                image: $item->icon ? null : ($resolved['image'] ?? null),
            );
        }

        return $out;
    }

    /**
     * A category item (PLAN §39.4): the category — or, with none chosen, the
     * whole catalogue — opened `depth` levels down, each subcategory kept only
     * when its subtree passes `show_when`. Null when the item itself is empty
     * by that rule and the shop asked for empty items to go.
     *
     * @return array<string, mixed>|null
     */
    public function categoryNode(MenuItem $item): ?array
    {
        $category = $item->sourceCategory;

        // A chosen category that has since been disabled or deleted draws
        // nothing — the row outlived what it pointed at. A HIDDEN one is
        // drawn: it is out of the automatic lists, and a person putting it in
        // this menu by hand is exactly how a hidden category gets a way in.
        if ($item->source_category_id && (! $category || ! $category->isReachable())) {
            return null;
        }

        $rule = in_array($item->option('show_when'), CategoryCounts::RULES, true) ? $item->option('show_when') : 'always';
        $withCounts = (bool) $item->option('counts', false);
        $count = $category && ($rule !== 'always' || $withCounts) ? $this->counts->of($category->id, $rule === 'always' ? 'listed' : $rule) : null;

        if ($category && $rule !== 'always' && $count === 0 && $item->option('hide_empty', false)) {
            return null;
        }

        $children = $this->subcategories($item, $category?->id ?? 0, max(0, min(3, (int) $item->option('depth', 1))), $rule);

        if ($category && $children !== [] && $item->option('all_link', false)) {
            array_unshift($children, new Node(
                id: 'm'.$item->id.'-all',
                kind: 'all',
                label: __('Everything in :category', ['category' => $category->t('name')]),
                href: route('catalog.category', $category->slug),
            ));
        }

        return [
            'label' => $category ? (string) $category->t('name') : __('Catalog'),
            'href' => $category ? route('catalog.category', $category->slug) : route('catalog.index'),
            'icon' => $category?->icon,
            'image' => $category?->iconImageUrl(),
            'count' => $withCounts ? $count : null,
            'children' => $children,
            'replace' => (bool) $item->option('replace', false),
        ];
    }

    /** @return list<Node> */
    protected function subcategories(MenuItem $item, int $parentId, int $depth, string $rule, int $level = 1): array
    {
        if ($depth < $level) {
            return [];
        }

        $withCounts = (bool) $item->option('counts', false);
        $rows = $this->sift($item, $parentId, $rule);
        $hidden = count(array_filter($rows, fn (array $r) => $r['why'] === 'limit'));

        $out = [];

        foreach ($rows as $row) {
            if ($row['why'] !== null) {
                continue;
            }

            $kid = $row['category'];
            $out[] = new Node(
                id: 'c'.$kid->id,
                kind: 'category',
                label: (string) $kid->t('name'),
                href: route('catalog.category', $kid->slug),
                icon: $item->option('child_icons', true) ? $this->icon($kid->icon) : null,
                image: $item->option('child_icons', true) ? $kid->iconImageUrl() : null,
                count: $withCounts ? $row['count'] : null,
                children: $this->subcategories($item, $kid->id, $depth, $rule, $level + 1),
            );
        }

        if ($hidden > 0 && $parentId > 0 && ($parent = app(CategoryTree::class)->find($parentId))) {
            $out[] = new Node(
                id: 'c'.$parentId.'-more',
                kind: 'more',
                label: trans_choice(':count more|:count more', $hidden, ['count' => $hidden]),
                href: route('catalog.category', $parent->slug),
            );
        }

        return $out;
    }

    /**
     * Every enabled child of one category, in the order the item asks for,
     * each with the verdict on it: `why` is null for one that is drawn, else
     * `excluded` (ticked away on the item), `empty` (nothing in its branch by
     * the item's rule) or `limit` (past "at most per level"). The storefront
     * keeps the nulls; the admin preview shows all of them — one decision, so
     * the preview cannot explain a menu the shop does not draw.
     *
     * `count` is null unless something asked for numbers — the rule, the
     * counter, "most products first" or the preview — because counting is
     * the one query a plain menu does not pay for.
     *
     * @return list<array{category: Category, count: ?int, why: ?string}>
     */
    public function sift(MenuItem $item, int $parentId, string $rule, bool $count = false): array
    {
        $exclude = array_map('intval', (array) $item->option('exclude', []));
        $countRule = $rule === 'always' ? 'listed' : $rule;

        $kids = match ($item->option('sort', 'tree')) {
            'name' => $this->childrenOf($parentId)->sortBy(fn (Category $c) => mb_strtolower((string) $c->t('name'))),
            'count' => $this->childrenOf($parentId)->sortByDesc(fn (Category $c) => $this->counts->of($c->id, $countRule)),
            default => $this->childrenOf($parentId),
        };

        $limit = max(0, (int) $item->option('limit', 0));
        $count = $count || $rule !== 'always' || $item->option('counts', false) || $item->option('sort') === 'count';
        $drawn = 0;
        $out = [];

        foreach ($kids as $kid) {

            $why = match (true) {
                in_array($kid->id, $exclude, true) => 'excluded',
                $rule !== 'always' && $this->counts->of($kid->id, $rule) === 0 => 'empty',
                $limit > 0 && $drawn >= $limit => 'limit',
                default => null,
            };

            if ($why === null) {
                $drawn++;
            }

            $out[] = ['category' => $kid, 'count' => $count ? $this->counts->of($kid->id, $countRule) : null, 'why' => $why];
        }

        return $out;
    }

    /**
     * What a category item would open into NOW, including what it leaves
     * out and why — the admin's preview (PLAN §39.7), without which "only
     * what can be bought" is a black box that ate the scarves.
     *
     * @return array{self_count: ?int, self_hidden: bool, rule: string, rows: list<array<string, mixed>>}
     */
    public function explain(MenuItem $item): array
    {
        $category = $item->sourceCategory;
        $rule = in_array($item->option('show_when'), CategoryCounts::RULES, true) ? $item->option('show_when') : 'always';
        $depth = max(0, min(3, (int) $item->option('depth', 1)));
        $selfCount = $category ? $this->counts->of($category->id, $rule === 'always' ? 'listed' : $rule) : null;

        $walk = function (int $parentId, int $level) use (&$walk, $item, $rule, $depth): array {
            if ($depth < $level) {
                return [];
            }

            return array_map(fn (array $row) => $row + [
                'children' => $row['why'] === null ? $walk($row['category']->id, $level + 1) : [],
            ], $this->sift($item, $parentId, $rule, true));
        };

        return [
            'self_count' => $selfCount,
            'self_hidden' => $category !== null && $rule !== 'always' && $selfCount === 0 && (bool) $item->option('hide_empty', false),
            'rule' => $rule,
            'rows' => $walk($category?->id ?? 0, 1),
        ];
    }

    /** @return Collection<int, Category> */
    protected function childrenOf(int $parentId): Collection
    {
        $this->children ??= app(CategoryTree::class)->all()
            ->filter(fn (Category $c) => $c->isListed())
            ->groupBy(fn (Category $c) => (int) $c->parent_id)
            ->all();

        return $this->children[$parentId] ?? collect();
    }

    /** A Lucide name that exists — anything else draws no icon rather than throwing. */
    protected function icon(?string $name): ?string
    {
        return $name && preg_match('/^[a-z0-9-]+$/', $name) && is_file(resource_path('icons/lucide/'.$name.'.svg')) ? $name : null;
    }

    /**
     * The entry for the page being read: the LONGEST address in the menu the
     * current path is at or under (the admin sidebar's rule — a product of
     * "Laptops" lights "Laptops", not "Catalogue"), plus its ancestors as the
     * trail. The home page matches only itself, or it would light everywhere.
     *
     * @param  list<Node>  $nodes
     */
    protected function markActive(array $nodes): void
    {
        $current = '/'.trim((string) request()->getPathInfo(), '/');
        $best = null;
        $bestLength = -1;
        $bestPath = [];

        $walk = function (array $nodes, array $path) use (&$walk, $current, &$best, &$bestLength, &$bestPath) {
            foreach ($nodes as $node) {
                $target = $node->href ? $this->pathOf($node->href) : null;

                if ($target !== null && ($current === $target || ($target !== '/' && str_starts_with($current, $target.'/'))) && strlen($target) > $bestLength) {
                    $best = $node;
                    $bestLength = strlen($target);
                    $bestPath = $path;
                }

                $walk($node->children, [...$path, $node]);
            }
        };

        $walk($nodes, []);

        if ($best) {
            $best->active = true;

            foreach ($bestPath as $ancestor) {
                $ancestor->trail = true;
            }
        }
    }

    /** The path of an address on THIS site, or null for another site's. */
    protected function pathOf(string $href): ?string
    {
        $parts = parse_url($href);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['host']) && $parts['host'] !== request()->getHost()) {
            return null;
        }

        return '/'.trim((string) ($parts['path'] ?? ''), '/');
    }

    public function flush(): void
    {
        $this->built = [];
        $this->children = null;
        $this->menus = null;
        $this->rows = null;
    }
}
