<?php

namespace Torgash\Catalog\Support;

use App\Core\Content\Placeholders;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductTab;

/**
 * What stands under the gallery on a product page, in order — as DATA, so that
 * the same list can be drawn as a strip of tabs or as a column of sections.
 *
 * The two layouts are the same content and differ in one thing that matters to
 * a shop: how much room the name of a section has. A tab holds a word
 * ("Reviews"), because six of them stand in a row; a section holds a line with
 * the product in it ("Reviews of the TIGER PRO-300 300W floodlight"), which is
 * the heading a shop actually wants — and the reason it wants it is the same
 * reason it wants a meta description: it is a sentence a search engine reads.
 * So every section carries both names, and the layout picks one.
 *
 * The built-in two are entries like any other, which is what lets a row RENAME
 * them ("Основні характеристики {product}"), move them, or switch one off. A
 * row that does that carries `section` and no content of its own; a row with
 * content is a section the shop wrote.
 *
 * Placeholders are expanded here rather than in the view: a title goes into a
 * text node and a heading into an H2, so both are expanded WITHOUT escaping
 * (Blade escapes on the way out, and twice makes `&amp;amp;`), while the body
 * is admin-written HTML and is expanded with escaping, exactly as an HTML block
 * is.
 */
class ProductSections
{
    /** The page's own sections and where they stand by default. */
    public const BUILT_IN = ['description' => 10, 'specs' => 20];

    /**
     * @param  array<string, bool>  $available  which built-in sections this product has anything for
     * @return list<array{key: string, label: string, heading: string, sort: int, html: ?string, section: ?string}>
     */
    public function for(Product $product, array $available = []): array
    {
        // Not `enabled()`: a row that names a built-in section is read even
        // when it is switched off, because switching it off is how that section
        // is hidden. A written row that is off is simply skipped below.
        $rows = ProductTab::query()->forProduct($product)->get();
        $context = ['product' => $product];

        // The rows that rename a built-in section, keyed by which one. A row
        // for THIS product wins over a shop-wide one: it is the more specific
        // answer, the same rule a per-product price follows.
        $renames = [];
        foreach ($rows as $row) {
            if ($row->section !== null) {
                $current = $renames[$row->section] ?? null;
                if (! $current || $row->product_id !== null) {
                    $renames[$row->section] = $row;
                }
            }
        }

        $sections = [];

        foreach (self::BUILT_IN as $key => $sort) {
            if (! ($available[$key] ?? false)) {
                continue;
            }

            $row = $renames[$key] ?? null;

            // Off, or ruled out: either way the page's own section goes with
            // the row that names it — which is how "no characteristics table on
            // gift cards" is said.
            if ($row && (! $row->enabled || ! $row->isVisible($context))) {
                continue;
            }

            $label = $row ? $this->text($row->t('title'), $context) : '';
            $label = $label !== '' ? $label : $this->defaultLabel($key);

            $sections[] = [
                'key' => $key,
                'label' => $label,
                'heading' => $this->heading($row, $label, $context),
                'sort' => $row && $row->sort !== null ? (int) $row->sort : $sort,
                'html' => null,
                'section' => $key,
            ];
        }

        foreach ($rows as $row) {
            if ($row->section !== null || ! $row->enabled) {
                continue;
            }

            $html = trim((string) $row->t('content'));

            // A label that opens onto nothing reads as a broken page, not as an
            // empty section.
            if ($html === '' || ! $row->isVisible($context)) {
                continue;
            }

            $label = $this->text($row->t('title'), $context);

            $sections[] = [
                'key' => 'tab-'.$row->id,
                'label' => $label,
                'heading' => $this->heading($row, $label, $context),
                'sort' => (int) $row->sort,
                // Cleaned before the placeholders are expanded: what they put
                // in is the engine's own markup (App\Core\Content\SafeHtml).
                'html' => app(Placeholders::class)->expand(app(\App\Core\Content\SafeHtml::class)->clean($html), $context),
                // Text the shop WROTE, drawn with the editor's typography. A
                // module's section (the reviews) is its own markup and says
                // nothing of the kind — dressing it as prose recoloured its
                // controls as links and dimmed the whole of it.
                'rich' => true,
                'section' => null,
            ];
        }

        /** @var list<array<string, mixed>> $sections */
        $sections = hook_filter('catalog.product.tabs', $sections, $context);

        // A module's section answers to a row exactly as a built-in one does:
        // `section` names it (its `section_key`, or its key), and the row may
        // switch it off, rule it out, move it, give it a heading — and rename
        // it, unless the module draws several sections under one key with
        // names of their own (`fixed_label`, the kits). Its `count` is kept
        // apart from its name, so "Reviews 5" survives becoming "Customer
        // reviews".
        $sections = array_values(array_filter(array_map(function (array $s) use ($renames, $context) {
            if (($s['section'] ?? null) !== null && isset(self::BUILT_IN[$s['section']])) {
                return $s;
            }

            $row = $renames[$s['section_key'] ?? $s['key'] ?? ''] ?? null;
            if (! $row) {
                return $s;
            }
            if (! $row->enabled || ! $row->isVisible($context)) {
                return null;
            }

            $label = empty($s['fixed_label']) ? $this->text($row->t('title'), $context) : '';
            $s['label'] = $label !== '' ? $label : $s['label'];
            $heading = $this->text($row->t('heading'), $context);
            $s['heading'] = $heading !== '' ? $heading : ($label !== '' ? $label : ($s['heading'] ?? $s['label']));
            $s['sort'] = (int) $row->sort;

            return $s;
        }, $sections)));

        usort($sections, fn (array $a, array $b) => ($a['sort'] ?? 50) <=> ($b['sort'] ?? 50));

        return array_values($sections);
    }

    /** Whether the shop lays the page out as sections rather than as tabs. */
    public function asSections(): bool
    {
        return setting('torgash.catalog', 'product_details_mode', 'tabs') === 'sections';
    }

    protected function heading(?ProductTab $row, string $label, array $context): string
    {
        $heading = $row ? $this->text($row->t('heading'), $context) : '';

        // No heading written means the section is headed by its own tab word —
        // which is right, and is what a shop sees before it writes a better one.
        return $heading !== '' ? $heading : $label;
    }

    protected function text(?string $template, array $context): string
    {
        return trim(app(Placeholders::class)->expand((string) $template, $context, false));
    }

    protected function defaultLabel(string $section): string
    {
        return self::label($section);
    }

    /**
     * Every section a row may name: the page's own two, and whatever modules
     * declare through `catalog.product.tabs.admin` — `key => [label, sort,
     * module?, note?, url?, renamable]`. The admin's list, its form and its
     * validation all read this, so a module that registers a section there is
     * configurable the same day, and one that is uninstalled stops being a
     * name a row can be saved under.
     *
     * @return array<string, array{label: string, sort: int, module: ?string, note: ?string, url: ?string, renamable: bool}>
     */
    public static function known(): array
    {
        $known = [];
        foreach (self::BUILT_IN as $key => $sort) {
            $known[$key] = ['label' => self::label($key), 'sort' => $sort, 'module' => null, 'note' => null, 'url' => null, 'renamable' => true];
        }
        foreach (hook_filter('catalog.product.tabs.admin', []) as $m) {
            if (! empty($m['key']) && ! isset($known[$m['key']])) {
                $known[$m['key']] = [
                    'label' => (string) ($m['label'] ?? $m['key']),
                    'sort' => (int) ($m['sort'] ?? 50),
                    'module' => $m['module'] ?? null,
                    'note' => $m['note'] ?? null,
                    'url' => $m['url'] ?? null,
                    'renamable' => (bool) ($m['renamable'] ?? true),
                ];
            }
        }

        return $known;
    }

    /** The engine's own word for one of the page's built-in sections — the admin's list uses it too. */
    public static function label(string $section): string
    {
        return match ($section) {
            'description' => __('Description'),
            'specs' => __('Characteristics'),
            default => $section,
        };
    }
}
