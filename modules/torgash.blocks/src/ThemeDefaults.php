<?php

namespace Torgash\Blocks;

use App\Core\Themes\ThemeManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Torgash\Blocks\Models\Block;

/**
 * Putting back what the theme meant a zone to look like.
 *
 * A theme.json zone may carry `default_blocks`, and until now that was a
 * one-way street: the seeding migration laid them out once, guarded per zone,
 * and that was the only time they were ever read. The guard is right — an
 * upgrade has no business dropping a block back into a zone the owner has
 * arranged — but it left two things impossible, and both were reported as
 * problems rather than imagined:
 *
 *  - an owner who took the header apart could not get it back except by reading
 *    the theme file and rebuilding it by hand;
 *  - a theme that adds `default_blocks` in a new version never reaches an
 *    installed shop at all, because the migration is already marked as run.
 *    Same reason a SECOND theme's zones come up empty: only that one pass ever
 *    filled anything.
 *
 * So restoring is an ACTION somebody presses, not a migration. A migration must
 * be one-off and silent; this is a person looking at a screen that tells them
 * exactly what is about to happen.
 *
 * Two modes, because "restore" means two different things:
 *
 *  - **ADD** what is missing, matched by type and title. The safe one: whatever
 *    the owner has arranged stays exactly where it is, and only blocks the
 *    theme ships that are not there arrive, at the end of the zone.
 *  - **REPLACE** the zone with what the theme ships. The zone goes back to how
 *    it looked on install, and everything currently in it is deleted — which is
 *    why the screen lists what will be lost by name first.
 *
 * Display rules are NOT invented. A theme block gets the rules its own file
 * describes and nothing otherwise, and empty rules mean "always show", which is
 * the honest default for a block whose author said nothing about when it should
 * appear.
 */
class ThemeDefaults
{
    /** What `source` a block laid out from a theme file carries. */
    public const SOURCE = 'theme';

    public function __construct(protected ThemeManager $themes) {}

    /**
     * The zones the ACTIVE theme chain furnishes, with what is there now.
     *
     * The chain and not every theme on disk, unlike the seeding migration: this
     * is a screen, and offering to restore a zone belonging to a theme nobody
     * is running would be offering to fill something that renders nowhere.
     *
     * @return array<string, array{defaults: list<array<string, mixed>>, present: Collection<int, Block>, missing: list<array<string, mixed>>}>
     */
    public function zones(): array
    {
        $found = [];

        // Parents first, so a child theme's zone of the same name wins — the
        // same precedence ZoneRegistry::registerFromThemes() applies.
        foreach (array_reverse($this->themes->chain()) as $theme) {
            foreach ($this->themes->meta($theme)['zones'] ?? [] as $zone) {
                $defaults = array_values(array_filter(
                    $zone['default_blocks'] ?? [],
                    fn ($block) => is_array($block) && ! empty($block['type']),
                ));

                if (empty($zone['name']) || $defaults === []) {
                    continue;
                }

                $found[$zone['name']] = $defaults;
            }
        }

        $present = Block::whereIn('zone', array_keys($found))
            ->orderBy('sort')->orderBy('id')->get()
            ->groupBy('zone');

        $out = [];

        foreach ($found as $name => $defaults) {
            $here = $present->get($name, collect());

            $out[$name] = [
                'defaults' => $defaults,
                'present' => $here,
                'missing' => array_values(array_filter(
                    $defaults,
                    fn (array $block) => ! $this->isPresent($block, $here),
                )),
            ];
        }

        return $out;
    }

    /**
     * Add the theme's blocks that are not in this zone, leaving everything else
     * untouched. Returns how many arrived.
     */
    public function add(string $zone): int
    {
        $found = $this->zones()[$zone] ?? null;

        if (! $found || $found['missing'] === []) {
            return 0;
        }

        $sort = (int) Block::where('zone', $zone)->max('sort');

        foreach ($found['missing'] as $block) {
            $this->insert($zone, $block, ++$sort);
        }

        return count($found['missing']);
    }

    /**
     * The zone as the theme ships it, and nothing else.
     *
     * In one transaction: a half-replaced zone — old blocks gone, new ones not
     * yet in — is a storefront rendering an empty header, and it would be left
     * that way by any failure in the middle.
     *
     * @return array{removed: int, added: int}
     */
    public function replace(string $zone): array
    {
        $found = $this->zones()[$zone] ?? null;

        if (! $found) {
            return ['removed' => 0, 'added' => 0];
        }

        return $this->replaceWith($zone, $found['defaults']);
    }

    /**
     * A zone replaced by a given list — the theme's own defaults above, or a
     * theme PRESET's layout (core announces those through
     * `core.theme.preset_blocks`, having no idea blocks exist). Same
     * transaction, same mark, same trust: a preset is theme.json too.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{removed: int, added: int}
     */
    public function replaceWith(string $zone, array $blocks): array
    {
        $blocks = array_values(array_filter($blocks, fn ($block) => is_array($block) && ! empty($block['type'])));

        return DB::transaction(function () use ($zone, $blocks) {
            $removed = Block::where('zone', $zone)->delete();

            $sort = 0;

            foreach ($blocks as $block) {
                $this->insert($zone, $block, $sort++);
            }

            return ['removed' => $removed, 'added' => count($blocks)];
        });
    }

    /**
     * Is this one of the theme's blocks already in the zone?
     *
     * Type AND title, because a zone legitimately holds two blocks of one type
     * (two banners), and the title is the only thing a theme file gives them to
     * be told apart by. Not the settings: an owner who edited the theme's own
     * block still has it, and "add missing" must not hand them a second copy.
     *
     * @param  Collection<int, Block>  $present
     */
    protected function isPresent(array $block, $present): bool
    {
        return $present->contains(
            fn (Block $existing) => $existing->type === $block['type']
                && (string) $existing->title === (string) ($block['title'] ?? ''),
        );
    }

    /**
     * Settings go in as the theme wrote them, WITHOUT BlockTypes::sanitize() —
     * the same call the seeding migration deliberately skips, and for the same
     * reason: a theme file is code under version control, at the same trust
     * level as a template. What it must not skip is the mark, which is the
     * whole point of this class existing.
     */
    protected function insert(string $zone, array $block, int $sort): void
    {
        Block::create(array_filter([
            'zone' => $zone,
            'type' => $block['type'],
            'source' => self::SOURCE,
            'title' => $block['title'] ?? null,
            'tabs' => $block['tabs'] ?? null,
            'row' => $block['row'] ?? null,
            'width' => $block['width'] ?? null,
            'settings' => $block['settings'] ?? [],
            // Only what the theme actually describes. A theme that says nothing
            // about when its block should show gets no rules, and no rules means
            // always — inventing a condition here would hide the block for
            // reasons nobody could find on the screen.
            //
            // Filtered rather than passed as null: `rules_match` is NOT NULL
            // with its own default, so writing a null into it is a constraint
            // violation rather than "leave it alone".
            'rules' => $block['rules'] ?? null,
            'rules_match' => $block['rules_match'] ?? null,
            'sort' => $sort,
            'enabled' => true,
        ], fn ($value) => $value !== null));
    }
}
