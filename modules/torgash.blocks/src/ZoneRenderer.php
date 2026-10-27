<?php

namespace Torgash\Blocks;

use App\Core\Content\Fragment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Torgash\Blocks\Models\Block;

/**
 * What a zone prints: its blocks, one after another — except where several of
 * them stand next to each other and carry the same `tabs` word, which are drawn
 * as ONE row of tabs, each named by its block's heading, or the same `row`
 * word, which are drawn side by side as COLUMNS (`width` each, stacked on a
 * phone). A block in a row is never also a tab: the row wins.
 *
 * Columns follow the same rule as tabs and for the same reasons — a word on
 * neighbouring blocks rather than a container block, so there is one editor,
 * one place a block lives and one order in the zone. A column that renders
 * nothing is left out and the others close up; a row left with ONE block is
 * simply that block at full width.
 *
 * Tabs are a property of where blocks stand, never a block that holds blocks.
 * A container block would need an editor inside the editor, a second place a
 * block can live and a second answer to "which zone is this in" — while the
 * tabs a shop actually asks for ("Hits / New / On sale") are just the blocks it
 * already has, side by side. Each keeps its own display rules, its own switch
 * and its own place in the zone's order.
 *
 * Every block is rendered BEFORE the tabs are decided, and one that printed
 * nothing is not a tab: "On sale" with nothing on sale must not be a label
 * leading to an empty panel — which is exactly what the hand-written homepage
 * tabs this replaces used to skip. A group left with ONE member is still drawn
 * by the tab view, as a heading over its content, so the block's view never
 * has to know which of the two it ended up as: inside a group it is told
 * `inTabs` and leaves its heading to whoever draws the tab.
 *
 * The tabs are radio buttons (daisyUI's own), so they work with no JavaScript.
 */
class ZoneRenderer
{
    /**
     * @param  Collection<int, Block>|iterable<Block>  $blocks  enabled, in zone order
     * @param  array<string, mixed>  $context  whatever the zone was rendering
     */
    public function render(iterable $blocks, array $context = []): string
    {
        $out = '';
        $group = [];
        $groupKey = null;
        // The group's band is its FIRST block's, drawn or not — as the admin's zone screen says.
        $groupFirst = null;

        foreach ($blocks as $block) {
            $key = self::groupKey($block);

            if ($key !== $groupKey) {
                $out .= $this->flush($groupKey, $group, $groupFirst);
                $group = [];
                $groupKey = $key;
                $groupFirst = $block;
            }

            if ($key === null) {
                $out .= Section::wrap($block->section, $this->block($block, $context));

                continue;
            }

            $html = $this->block($block, $context, inTabs: str_starts_with($key, 'tabs:'));

            if (trim($html) !== '') {
                $group[] = ['block' => $block, 'html' => $html];
            }
        }

        return $out.$this->flush($groupKey, $group, $groupFirst);
    }

    /**
     * Which neighbours a block is drawn together with: its row, else its tabs,
     * else none. Public, so the admin's zone screen groups exactly as the
     * storefront does.
     */
    public static function groupKey(Block $block): ?string
    {
        $row = trim((string) $block->row);
        if ($row !== '') {
            return 'row:'.$row;
        }

        $tabs = trim((string) $block->tabs);

        return $tabs !== '' ? 'tabs:'.$tabs : null;
    }

    /** @param  list<array{block: Block, html: string}>  $group */
    protected function flush(?string $key, array $group, ?Block $first = null): string
    {
        if ($key === null || $group === []) {
            return '';
        }

        // Blocks drawn together stand in one band: the first one's.
        return Section::wrap(($first ?? $group[0]['block'])->section, str_starts_with($key, 'row:') ? $this->row($group) : $this->tabs($group));
    }

    /**
     * Side by side on a 12-column grid. A width is a fraction of the row; the
     * columns that did not say share what is left, the remainder going to the
     * first of them — so a row always fills its width however it was set.
     *
     * @param  list<array{block: Block, html: string}>  $group
     */
    protected function row(array $group): string
    {
        if (count($group) === 1) {
            return $group[0]['html'];
        }

        $spans = self::spans(array_map(fn (array $member) => $member['block']->width, $group));

        return view('torgash.blocks::row', [
            'cells' => array_map(fn (array $member, int $span) => ['html' => $member['html'], 'span' => $span], $group, $spans),
        ])->render();
    }

    /** The twelfths each width stands for. */
    public const WIDTHS = ['1/4' => 3, '1/3' => 4, '1/2' => 6, '2/3' => 8, '3/4' => 9];

    /**
     * Twelfths per column: the ones set, and the rest shared equally among the
     * columns that did not say (at least one each).
     *
     * @param  list<?string>  $widths
     * @return list<int>
     */
    public static function spans(array $widths): array
    {
        $fixed = array_map(fn ($width) => self::WIDTHS[(string) $width] ?? null, $widths);
        $auto = count(array_filter($fixed, fn ($span) => $span === null));
        $left = max(0, 12 - array_sum(array_filter($fixed)));
        $share = $auto ? intdiv($left, $auto) : 0;
        $extra = $auto ? $left - $share * $auto : 0;

        return array_map(function ($span) use ($share, &$extra) {
            if ($span !== null) {
                return $span;
            }

            $give = $extra-- > 0 ? 1 : 0;

            return max(1, $share + $give);
        }, $fixed);
    }

    /**
     * A block whose type vanished (module disabled) renders nothing rather than
     * throwing — the storefront must not break because an admin turned a module
     * off; the row stays and comes back with the module.
     */
    public function block(Block $block, array $context = [], bool $inTabs = false): string
    {
        $type = BlockTypes::find($block->type);

        if (! $type || empty($type['view']) || ! view()->exists($type['view'])) {
            return '';
        }

        // One block that throws — a theme's override of its template, settings
        // its view does not expect — is left out on its own: the zone's other
        // blocks still print, and staff see a plate with a link to the block.
        return Fragment::isolate(
            function () use ($block, $type, $context, $inTabs) {
                // Asked per render and not at boot: the zone context is what carries the
                // product, and the same subscription serves every page the zone appears
                // on. A block with no rules answers yes without touching anything.
                if (! $block->isVisible($context)) {
                    return '';
                }

                // The shop's own classes go onto the block's outermost element —
                // every type draws its own, so this is the one place that knows.
                return CssClasses::onRoot(view($type['view'], [
                    'block' => $block,
                    // Translatable fields are flattened to the visitor's language HERE,
                    // once, so no block template ever has to know that translations
                    // exist — including templates shipped by other modules.
                    'settings' => BlockTypes::localize($block->type, $block->settings ?? []),
                    'zoneContext' => $context,
                    'inTabs' => $inTabs,
                ])->render(), $block->css_class);
            },
            fn () => __('block «:name»', ['name' => $block->displayTitle()]),
            fn () => Route::has('admin.blocks.edit') ? route('admin.blocks.edit', $block) : null,
        );
    }

    /** @param  list<array{block: Block, html: string}>  $group */
    protected function tabs(array $group): string
    {
        if ($group === []) {
            return '';
        }

        $tabs = array_map(fn (array $member) => [
            'label' => $this->label($member['block']),
            'html' => $member['html'],
        ], $group);

        return view('torgash.blocks::tabs', [
            'tabs' => $tabs,
            // The radio group's name: one per row of tabs on the page, and the
            // first block's id is already unique.
            'name' => 'block-tabs-'.$group[0]['block']->id,
        ])->render();
    }

    /** The word on a tab: the block's heading, else what the admin calls it. */
    protected function label(Block $block): string
    {
        $heading = BlockTypes::heading($block->type, BlockTypes::localize($block->type, $block->settings ?? []));

        return $heading !== '' ? $heading : $block->displayTitle();
    }
}
