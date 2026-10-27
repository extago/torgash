<?php

namespace Torgash\Blocks\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Core\Display\RuleKinds;
use App\Core\Zones\ZoneRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Torgash\Blocks\BlockTypes;
use Torgash\Blocks\CssClasses;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\Section;
use Torgash\Blocks\ThemeDefaults;
use Torgash\Blocks\ZoneRenderer;
use Torgash\Blocks\ZoneSwitches;

class BlocksController extends Controller
{
    public function __construct(protected ZoneRegistry $zones, protected ZoneSwitches $switches) {}

    public function index()
    {
        return view('torgash.blocks::admin.index', [
            'grouped' => $this->zones->grouped(),
            'counts' => Block::selectRaw('zone, count(*) as n')->groupBy('zone')->pluck('n', 'zone'),
            'disabledZones' => $this->switches->disabled(),
        ]);
    }

    /**
     * "Put the zones back the way the theme meant them."
     *
     * A screen rather than one button, because zones are independent and "give
     * me back the header, leave the footer alone" is an ordinary thing to want.
     * It shows, per zone, what the theme ships, what is in there now and whose
     * each of those is — which is what makes the Replace button honest: it
     * names what it is about to throw away before anybody presses it.
     */
    public function themeDefaults(ThemeDefaults $defaults)
    {
        return view('torgash.blocks::admin.theme-defaults', [
            'zones' => $defaults->zones(),
            'registry' => $this->zones,
        ]);
    }

    /** Add the theme's blocks that are missing; everything else stays put. */
    public function restoreAdd(string $zone, ThemeDefaults $defaults)
    {
        abort_unless($this->zones->has($zone), 404);

        $added = $defaults->add($zone);

        return back()->with('status', $added
            ? __(':n blocks added from the theme.', ['n' => $added])
            : __('Nothing was missing — the zone already has everything the theme ships.'));
    }

    /**
     * The zone as the theme ships it, and nothing else. Destructive, which is
     * why the screen made the reader read the list first.
     */
    public function restoreReplace(string $zone, ThemeDefaults $defaults)
    {
        abort_unless($this->zones->has($zone), 404);

        $result = $defaults->replace($zone);

        return back()->with('status', __('Zone replaced: :removed removed, :added put back from the theme.', [
            'removed' => $result['removed'],
            'added' => $result['added'],
        ]));
    }

    public function zone(string $zone)
    {
        abort_unless($this->zones->has($zone), 404);

        $definition = $this->zones->find($zone);
        $shape = $definition['shape'] ?? 'wide';
        $types = BlockTypes::all();

        return view('torgash.blocks::admin.zone', [
            'zone' => $definition,
            'shape' => $shape,
            // The canvas: the zone's blocks in the groups the storefront draws
            // them in (ZoneRenderer::groupKey), so what the screen shows side
            // by side is what the page does.
            'groups' => $this->groups($this->ordered($zone)),
            // The picker: what makes sense in a zone of this shape first, the
            // rest one click away — a shop sometimes knows better than a list.
            'fitting' => array_filter($types, fn (array $type, string $name) => BlockTypes::fits($name, $shape), ARRAY_FILTER_USE_BOTH),
            'others' => array_filter($types, fn (array $type, string $name) => ! BlockTypes::fits($name, $shape), ARRAY_FILTER_USE_BOTH),
            'zoneEnabled' => $this->switches->enabled($zone),
        ]);
    }

    /** @return list<Block> the zone's blocks in the order it draws them */
    protected function ordered(string $zone): array
    {
        return Block::where('zone', $zone)->orderBy('sort')->orderBy('id')->get()->all();
    }

    /**
     * Adjacent blocks sharing a row or a tabs word, as the storefront groups
     * them.
     *
     * @param  list<Block>  $blocks
     * @return list<array{key: ?string, kind: string, blocks: list<Block>}>
     */
    protected function groups(array $blocks): array
    {
        $groups = [];

        foreach ($blocks as $block) {
            $key = ZoneRenderer::groupKey($block);
            $last = array_key_last($groups);

            if ($key !== null && $last !== null && $groups[$last]['key'] === $key) {
                $groups[$last]['blocks'][] = $block;

                continue;
            }

            $groups[] = [
                'key' => $key,
                'kind' => $key === null ? 'single' : (str_starts_with($key, 'row:') ? 'row' : 'tabs'),
                'blocks' => [$block],
            ];
        }

        return $groups;
    }

    /**
     * Write the zone's order from a list of groups, and tidy what the move may
     * have left: a "group" of one is a block on its own (its word cleared), so
     * no stale word waits to glue it to the next block it meets.
     *
     * @param  list<array{key: ?string, kind: string, blocks: list<Block>}>  $groups
     */
    protected function save(array $groups): void
    {
        $sort = 0;

        foreach ($groups as $group) {
            $alone = count($group['blocks']) === 1;

            foreach ($group['blocks'] as $block) {
                $block->sort = $sort++;

                if ($alone) {
                    $block->row = null;
                    $block->width = null;
                    $block->tabs = null;
                }

                if ($block->isDirty()) {
                    $block->save();
                }
            }
        }
    }

    /** The group a block is in, by index. */
    protected function groupOf(array $groups, Block $block): int
    {
        foreach ($groups as $index => $group) {
            foreach ($group['blocks'] as $member) {
                if ($member->id === $block->id) {
                    return $index;
                }
            }
        }

        abort(404);
    }

    /**
     * Put the block's group together with the group right below it — as
     * columns or as tabs. The word is the upper group's own when it already is
     * one, otherwise a new one named after this block (unique by its id).
     */
    public function join(Request $request, Block $block)
    {
        $as = $request->input('as') === 'tabs' ? 'tabs' : 'row';
        $groups = $this->groups($this->ordered($block->zone));
        $index = $this->groupOf($groups, $block);

        if (! isset($groups[$index + 1])) {
            return back();
        }

        $upper = $groups[$index];
        $word = $upper['kind'] === $as ? substr((string) $upper['key'], strlen($as) + 1) : $as.'-'.$block->id;

        foreach ([...$upper['blocks'], ...$groups[$index + 1]['blocks']] as $member) {
            $member->row = $as === 'row' ? $word : null;
            $member->tabs = $as === 'tabs' ? $word : null;
            if ($as === 'tabs') {
                $member->width = null;
            }
        }

        $groups[$index]['blocks'] = [...$upper['blocks'], ...$groups[$index + 1]['blocks']];
        $groups[$index]['key'] = $as.':'.$word;
        $groups[$index]['kind'] = $as;
        array_splice($groups, $index + 1, 1);
        $this->save($groups);

        return back()->with('status', $as === 'row' ? __('Put side by side.') : __('Joined as tabs.'));
    }

    /** Take one block out of its row or tabs, to stand on its own just below them. */
    public function detach(Block $block)
    {
        $groups = $this->groups($this->ordered($block->zone));
        $index = $this->groupOf($groups, $block);

        if (count($groups[$index]['blocks']) > 1) {
            $groups[$index]['blocks'] = array_values(array_filter($groups[$index]['blocks'], fn (Block $member) => $member->id !== $block->id));
            $block->row = $block->tabs = $block->width = null;
            array_splice($groups, $index + 1, 0, [['key' => null, 'kind' => 'single', 'blocks' => [$block]]]);
            $this->save($groups);
        }

        return back()->with('status', __('Taken out on its own.'));
    }

    /** A column's width — a fraction of its row, or empty for "an equal share". */
    public function width(Request $request, Block $block)
    {
        $request->validate(['width' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(ZoneRenderer::WIDTHS))]]);
        $block->update(['width' => $request->input('width') ?: null]);

        return back();
    }

    /**
     * The whole zone off, as opposed to one block off. Kept on the zone and not
     * on the blocks so that switching it back on restores exactly what was
     * there — the rows are never touched.
     */
    /**
     * The band a row or a block stands in (PLAN §51), from the zone screen.
     * Posted for the group's first block — the one whose band the group is
     * drawn in.
     */
    public function section(Request $request, Block $block)
    {
        $this->refuseBadClasses($request->input('section.class'), 'section.class');
        $block->update(['section' => Section::accept($request->input('section'))]);

        return back()->with('status', __('Section saved.'));
    }

    public function toggleZone(string $zone)
    {
        abort_unless($this->zones->has($zone), 404);

        $enabled = $this->switches->toggle($zone);

        return back()->with('status', $enabled ? __('Zone enabled.') : __('Zone disabled.'));
    }

    public function create(string $zone, string $type)
    {
        abort_unless($this->zones->has($zone) && BlockTypes::has($type), 404);

        $block = new Block(['zone' => $zone, 'type' => $type, 'settings' => [], 'enabled' => true]);

        return view('torgash.blocks::admin.form', $this->formData($block));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'zone' => 'required|string',
            'type' => 'required|string',
        ]);

        abort_unless($this->zones->has($validated['zone']) && BlockTypes::has($validated['type']), 404);

        $block = new Block(['zone' => $validated['zone'], 'type' => $validated['type']]);
        $this->fill($request, $block);
        $block->sort = (int) Block::where('zone', $block->zone)->max('sort') + 1;
        $block->save();

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.blocks.zone', $block->zone)
            : redirect()->route('admin.blocks.edit', $block))->with('status', __('Block created.'));
    }

    public function edit(Block $block)
    {
        return view('torgash.blocks::admin.form', $this->formData($block));
    }

    public function update(Request $request, Block $block)
    {
        $this->fill($request, $block);
        $block->save();

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.blocks.zone', $block->zone)
            : redirect()->route('admin.blocks.edit', $block))->with('status', __('Block updated.'));
    }

    public function destroy(Block $block)
    {
        $zone = $block->zone;
        $block->delete();

        return redirect()->route('admin.blocks.zone', $zone)->with('status', __('Block deleted.'));
    }

    public function toggle(Block $block)
    {
        $block->update(['enabled' => ! $block->enabled]);

        return back();
    }

    /**
     * Up and down move the block's whole GROUP past the group beside it — a
     * row of columns or a set of tabs travels as one, so a move can never
     * wedge a stranger into the middle of a row. Left and right move a block
     * among the members of its own group.
     */
    public function move(Request $request, Block $block)
    {
        $direction = (string) $request->input('direction');
        $groups = $this->groups($this->ordered($block->zone));
        $index = $this->groupOf($groups, $block);

        if (in_array($direction, ['up', 'down'], true)) {
            $other = $direction === 'up' ? $index - 1 : $index + 1;

            if (isset($groups[$other])) {
                [$groups[$index], $groups[$other]] = [$groups[$other], $groups[$index]];
            }
        } elseif (in_array($direction, ['left', 'right'], true)) {
            $members = $groups[$index]['blocks'];
            // By id: the route's model is another instance of the same row.
            $at = array_search($block->id, array_map(fn (Block $member) => $member->id, $members), true);
            $to = $direction === 'left' ? $at - 1 : $at + 1;

            if ($at !== false && isset($members[$to])) {
                [$members[$at], $members[$to]] = [$members[$to], $members[$at]];
                $groups[$index]['blocks'] = $members;
            }
        }

        $this->save($groups);

        return back();
    }

    protected function formData(Block $block): array
    {
        // Where the block stands among its neighbours — read-only here, the
        // arranging is the zone screen's job.
        $group = null;
        if ($block->exists) {
            $groups = $this->groups($this->ordered($block->zone));
            $group = $groups[$this->groupOf($groups, $block)];
        }

        return [
            'block' => $block,
            'group' => $group,
            'type' => BlockTypes::find($block->type),
            'schema' => BlockTypes::schema($block->type),
            'zone' => $this->zones->find($block->zone),
            // Only worth offering where the content is rendered raw — an
            // escaped field would show the token's markup as literal text.
            // The list itself is the shared picker's business; this is only
            // whether this block type has anywhere to put one.
            'offerPlaceholders' => collect(BlockTypes::schema($block->type))
                ->contains(fn ($field) => ($field['type'] ?? '') === 'html'),
            // Every kind any module registered, plus its operators and choices
            // — the editor is generated from the registry, so a module adding a
            // kind needs no change here or in the view.
            'ruleKinds' => RuleKinds::all(),
            'ruleGroups' => RuleKinds::grouped(),
            'ruleOptions' => collect(RuleKinds::all())
                ->filter(fn (array $k) => ($k['value'] ?? 'none') === 'options')
                ->map(fn (array $k, string $name) => RuleKinds::options($name))
                ->all(),
        ];
    }

    /**
     * The display rules as posted, reduced to what the registry recognises.
     *
     * A kind nobody registered, an operator that kind does not offer, or a row
     * with nothing chosen where a value is required is DROPPED rather than
     * stored: a rule the engine cannot evaluate hides the block for ever (it
     * fails closed, deliberately), and a typo in a form should not do that.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function rules(Request $request): ?array
    {
        return app(DisplayRules::class)->normalise($request->input('rules', []));
    }

    /**
     * The one write path for both editors. `input_mode` says which one the
     * admin was using; either way the result goes through BlockTypes::sanitize(),
     * so the raw-JSON textarea has exactly the same reach as the generated form
     * and cannot introduce a key the type never declared.
     */
    protected function fill(Request $request, Block $block): void
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:190',
            'tabs' => 'nullable|string|max:64',
            'input_mode' => 'nullable|in:form,json',
            // Malformed JSON has to be an error, not a silent fallback: an
            // unparseable payload would otherwise sanitize down to defaults and
            // quietly wipe the block the admin was trying to edit.
            'settings_json' => ['nullable', 'string', function ($attribute, $value, $fail) use ($request) {
                if ($request->input('input_mode') !== 'json') {
                    return;
                }

                $blank = trim((string) $value) === '';
                $decoded = $blank ? [] : json_decode($value, true);

                if (! $blank && json_last_error() !== JSON_ERROR_NONE) {
                    $fail(__('Invalid JSON: :error', ['error' => json_last_error_msg()]));

                    return;
                }
                // A list ([1,2,3]) decodes to an array too, and would sanitize
                // down to defaults — i.e. silently wipe the block. Settings are
                // a key/value object; anything else is a mistake worth saying.
                if (! is_array($decoded) || (! empty($decoded) && array_is_list($decoded))) {
                    $fail(__('JSON must be an object of settings, e.g. {"heading": "..."}.'));
                }
            }],
        ]);

        $mode = ($validated['input_mode'] ?? 'form') === 'json' ? 'json' : 'form';

        $incoming = $mode === 'json'
            ? (array) (json_decode($validated['settings_json'] ?? '{}', true) ?: [])
            : (array) $request->input('settings', []);

        // Nothing at all from the JSON tab erases EVERY setting in EVERY
        // language at once — that tab is not scoped to the content language the
        // way the form is, so "I cleared the box to clear this language" takes
        // the other languages with it. Emptying a textarea is far too cheap a
        // gesture for an irreversible wipe.
        //
        // Guarded HERE and not in the rules above on purpose: an emptied field
        // arrives as null (ConvertEmptyStringsToNull), and `nullable` makes the
        // validator skip its closure for null — so the rule looked like it
        // covered this and did not. Next to the assignment it protects, it
        // cannot be bypassed by how the value happens to arrive.
        if ($mode === 'json' && $incoming === [] && ($block->settings ?? []) !== []) {
            throw ValidationException::withMessages([
                'settings_json' => __('An empty object would erase this block\'s settings in every language. Clear the individual fields in the Settings tab instead.'),
            ]);
        }

        $block->title = $validated['title'] ?? null;
        if ($request->has('css_class')) {
            $this->refuseBadClasses($request->input('css_class'), 'css_class');
            $block->css_class = CssClasses::accept($request->input('css_class'));
        }
        // Only when the request carries it: the arrangement is made on the
        // zone screen (join, take out), and a form without the field must not
        // quietly take a block out of its tabs. Trimmed and nothing else — it
        // is compared for equality with the neighbours' word.
        if ($request->has('tabs')) {
            $block->tabs = trim((string) ($validated['tabs'] ?? '')) ?: null;
        }
        if ($request->has('width')) {
            $width = (string) $request->input('width');
            $block->width = isset(ZoneRenderer::WIDTHS[$width]) ? $width : null;
        }
        $block->enabled = $request->boolean('enabled');
        $block->rules = $this->rules($request);
        $block->rules_match = $request->input('rules_match') === DisplayRules::MATCH_ANY
            ? DisplayRules::MATCH_ANY
            : DisplayRules::MATCH_ALL;
        // The mode travels with the payload because translatable fields read the
        // two editors differently: the form posts one language, the JSON tab the
        // whole object. See BlockTypes::castTranslatable().
        $block->settings = BlockTypes::sanitize($block->type, $incoming, $block->settings ?? [], $mode);
    }

    /**
     * A name that is not a plain class is said, not silently dropped: the
     * admin typed it for a reason, and "it didn't work" is worse than "this
     * one is not a class name".
     */
    protected function refuseBadClasses(mixed $typed, string $field): void
    {
        $rejected = CssClasses::rejected($typed);

        if ($rejected !== []) {
            throw ValidationException::withMessages([
                $field => __('Not a class name: :names. Letters, digits, "-" and "_", not starting with a digit.', ['names' => implode(' ', $rejected)]),
            ]);
        }
    }
}
