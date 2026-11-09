<?php

namespace Torgash\Blocks;

use App\Core\Content\Fragment;
use App\Core\Display\RuleKinds;
use App\Core\PageCache\Capture;
use App\Core\PageCache\Holes;
use Illuminate\Database\Eloquent\Model;
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
 *
 * A page kept ready for guests (PLAN §61) keeps a block that turns out to be
 * the visitor's own — it read something personal (a rule asking their town,
 * "viewed recently"), or its type or a rule's kind says so — as a personal
 * part, drawn again for every visitor; a row or tabs with one such member as
 * one part, since the others close up around it.
 *
 * That redraw is the price of a block being personal, and for a list of cards
 * it is most of what the visitor waits for: a kept page in 12 ms for a guest
 * and in 440 for one with a browsing history, because "Viewed recently" stood
 * in a row of tabs and took the row's 36 cards with it. So a block whose type
 * says it can (`later`, BlockTypes) WAITS: the page says only whether there is
 * anything to see — the name of its tab, or the place it will fill — and the
 * block itself is drawn by its own request (`blocks.later`), when its tab is
 * opened or it is scrolled to. Never a member of a row, nor a block with
 * display rules: a rule asks about the page the block stands on, and that
 * request is not that page. Tabs with one that waits are always drawn as a
 * row of tabs, since only some visitors get a second name in it: app.css
 * makes a row with one name look like the heading a lone tab is given.
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
        // Every member, drawn or not, and whether one is the visitor's own:
        // one that drew nothing for this visitor may draw for the next.
        $members = [];
        $personal = false;
        // What the members read, while each of them is right as drawn for
        // whoever holds none of it (Holes::probe()).
        $read = [];

        foreach ($blocks as $block) {
            $key = self::groupKey($block);

            if ($key !== $groupKey) {
                $out .= $this->personalPart($members, $context, $this->flush($groupKey, $group, $groupFirst, $context), $personal, $read);
                $group = [];
                $members = [];
                $personal = false;
                $read = [];
                $groupKey = $key;
                $groupFirst = $block;
            }

            if ($key === null) {
                if ($this->waits($block)) {
                    $out .= $this->waitingBlock($block, $context);

                    continue;
                }

                $drawn = $this->drawn($block, $context);
                $out .= $this->personalPart([$block], $context, Section::wrap($block->section, $drawn['html']), $drawn['personal'], $drawn['read']);

                continue;
            }

            $inTabs = str_starts_with($key, 'tabs:');
            $members[] = $block;

            // Drawn by its own request; all the row needs is where it stands.
            if ($inTabs && $this->waits($block)) {
                $group[] = ['block' => $block, 'html' => null];

                continue;
            }

            $drawn = $this->drawn($block, $context, inTabs: $inTabs);
            $personal = $personal || $drawn['personal'];
            $read = Capture::together($read, $drawn['read']);

            if (trim($drawn['html']) !== '') {
                $group[] = ['block' => $block, 'html' => $drawn['html']];
            }
        }

        return $out.$this->personalPart($members, $context, $this->flush($groupKey, $group, $groupFirst, $context), $personal, $read);
    }

    /** May this block be drawn after the page? Asks nothing about the visitor. */
    public function waits(Block $block): bool
    {
        $when = BlockTypes::find($block->type)['later']['when'] ?? null;

        return is_callable($when) && empty($block->rules) && $when((array) ($block->settings ?? []));
    }

    /**
     * A block that waited, drawn for whoever asked — what `blocks.later`
     * answers. Its heading is the tab's when it stands in one.
     *
     * @param  array<string, mixed>  $keys  what the address says: the keys laterKeys() gave it
     */
    public function drawLater(Block $block, array $keys): string
    {
        $inTabs = self::groupKey($block) !== null;
        $context = [];

        foreach ((array) (BlockTypes::find($block->type)['later']['context'] ?? []) as $name => $class) {
            $key = (int) ($keys[$name] ?? 0);

            if ($key > 0 && ($found = $class::query()->find($key))) {
                $context[$name] = $found;
            }
        }

        $html = $this->block($block, $context, $inTabs);

        return $inTabs ? $html : Section::wrap($block->section, $html);
    }

    /**
     * The things of the page's context a block that waits needs again, by
     * key — and only those its type named, so what the address may ask for
     * is the type's to say.
     *
     * @return array<string, int>
     */
    protected function laterKeys(Block $block, array $context): array
    {
        $keys = [];

        foreach (array_keys((array) (BlockTypes::find($block->type)['later']['context'] ?? [])) as $name) {
            if (($context[$name] ?? null) instanceof Model) {
                $keys[$name] = (int) $context[$name]->getKey();
            }
        }

        return $keys;
    }

    /**
     * What the visitor's own part of a waiting block needs — plain values:
     * a personal part keeps them in the copy, and fetching the block and the
     * product back on every visit is what waiting is meant to save.
     *
     * @return array{waitingType: string, waitingSettings: array<string, mixed>, waitingKeys: array<string, int>}
     */
    protected function waitingData(Block $block, array $keys): array
    {
        return ['waitingType' => (string) $block->type, 'waitingSettings' => (array) ($block->settings ?? []), 'waitingKeys' => $keys];
    }

    /** A block that waits, standing alone: its place, for a visitor with something to put in it. */
    protected function waitingBlock(Block $block, array $context): string
    {
        $keys = $this->laterKeys($block, $context);

        return app(Holes::class)->render('torgash.blocks::waiting-block', [
            ...$this->waitingData($block, $keys),
            'waitingUrl' => route('blocks.later', ['block' => $block->id, ...$keys]),
        ], reusable: true);
    }

    /**
     * A tab that waits: its name, for a visitor with something to find under
     * it, and a panel that asks for the block once it is shown — hidden, and
     * so inert, for everybody else.
     *
     * @return array{waits: true, bar: string, html: string}
     */
    protected function waitingTab(Block $block, array $context, string $name, int $value, bool $open): array
    {
        $keys = $this->laterKeys($block, $context);

        return [
            'waits' => true,
            'bar' => app(Holes::class)->render('torgash.blocks::waiting-tab', [
                ...$this->waitingData($block, $keys),
                'waitingName' => $name,
                'waitingValue' => $value,
                'waitingOpen' => $open,
                'waitingLabel' => $this->label($block),
            ], reusable: true),
            'html' => view('torgash.blocks::waiting', ['waitingUrl' => route('blocks.later', ['block' => $block->id, ...$keys])])->render(),
        ];
    }

    /**
     * A block drawn, and whether it is the visitor's own — asked only while
     * a copy of the page is being drawn for every guest.
     *
     * @return array{html: string, personal: bool, read: array<string, ?string>|null}
     */
    protected function drawn(Block $block, array $context, bool $inTabs = false): array
    {
        $drawn = app(Holes::class)->probe(fn () => $this->block($block, $context, $inTabs));

        // Personal by what is said: by what, no session tells — never reused.
        if (page_cache()->drawing() && $this->declaredPersonal($block)) {
            $drawn['personal'] = true;
            $drawn['read'] = null;
        }

        // Drawn again for each visitor, its push would land after the page's
        // stack was printed: what the copy holds is the first visitor's.
        if ($drawn['personal'] && $drawn['pushed']) {
            page_cache()->current()?->refuse('block «'.$block->displayTitle().'» is personal and pushes to a stack');
        }

        return ['html' => $drawn['html'], 'personal' => $drawn['personal'], 'read' => $drawn['read']];
    }

    /**
     * Personal by what is said rather than what was read: its type (its view
     * asks something no session shows — the visitor's address), or a rule
     * of a kind that does.
     */
    protected function declaredPersonal(Block $block): bool
    {
        if (! empty(BlockTypes::find($block->type)['personal'])) {
            return true;
        }

        foreach ((array) ($block->rules ?? []) as $rule) {
            if (is_array($rule) && ! empty(RuleKinds::find($rule['kind'] ?? null)['personal'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What blocks drew, kept in a copy as a personal part when one of them
     * is the visitor's own: `torgash.blocks::personal` draws the same blocks
     * again, with the same context, for each visitor — or, with `$read`,
     * keeps as drawn for whoever holds what they read.
     *
     * @param  list<Block>  $blocks
     * @param  array<string, ?string>|null  $read
     */
    protected function personalPart(array $blocks, array $context, string $html, bool $personal, ?array $read = null): string
    {
        return $personal && $blocks !== []
            ? app(Holes::class)->adopt('torgash.blocks::personal', ['blocks' => $blocks, 'context' => $context], $html, $read)
            : $html;
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

    /** @param  list<array{block: Block, html: ?string}>  $group */
    protected function flush(?string $key, array $group, ?Block $first = null, array $context = []): string
    {
        if ($key === null || $group === []) {
            return '';
        }

        // Blocks drawn together stand in one band: the first one's.
        return Section::wrap(($first ?? $group[0]['block'])->section, str_starts_with($key, 'row:') ? $this->row($group) : $this->tabs($group, $context));
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

    /** @param  list<array{block: Block, html: ?string}>  $group */
    protected function tabs(array $group, array $context = []): string
    {
        if ($group === []) {
            return '';
        }

        // The radio group's name: one per row of tabs on the page, and the
        // first block's id is already unique.
        $name = 'block-tabs-'.$group[0]['block']->id;

        // Open at the start: the first tab there for everybody. One that
        // waits only when none is, or a visitor could find nothing open.
        $open = array_key_first(array_filter($group, fn (array $member) => $member['html'] !== null)) ?? 0;

        $tabs = array_map(fn (array $member, int $n) => $member['html'] === null
            ? $this->waitingTab($member['block'], $context, $name, $n, $n === $open)
            : ['label' => $this->label($member['block']), 'html' => $member['html']],
            $group,
            array_keys($group),
        );

        return view('torgash.blocks::tabs', ['tabs' => $tabs, 'name' => $name, 'open' => $open])->render();
    }

    /** The word on a tab: the block's heading, else what the admin calls it. */
    protected function label(Block $block): string
    {
        $heading = BlockTypes::heading($block->type, BlockTypes::localize($block->type, $block->settings ?? []));

        return $heading !== '' ? $heading : $block->displayTitle();
    }
}
