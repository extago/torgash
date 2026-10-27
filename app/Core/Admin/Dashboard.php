<?php

namespace App\Core\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The admin's first page: WIDGETS, each owned by whoever owns the data, laid
 * out the way the person reading it chose.
 *
 * A widget is an entry of `core.admin.dashboard.widgets` (asked with
 * `['user' => …]`):
 *
 *     [
 *       'key'      => 'checkout.sales',      // unique, owner-prefixed
 *       'title'    => 'Sales',               // English, translated by the owner's lang file
 *       'icon'     => 'chart-column',        // lucide name
 *       'size'     => 'wide',                // quarter | third | half | wide | full — which column it starts in
 *       'position' => 10,                    // default order, lower first
 *       'can'      => 'orders.manage',       // optional: who may see it
 *       'link'     => ['url' => …, 'label' => 'All orders'], // optional, in its header
 *       'render'   => fn () => view(…)->render(),             // its BODY, asked only if shown
 *     ]
 *
 * The page is COLUMNS, not a grid of cells: a band across the whole width on
 * top, then two or three columns, each a stack of its own. Widgets are of
 * every height, and a grid of cells leaves a hole under every short one — a
 * stack per column never does. The person picks the columns (LAYOUTS) and
 * puts each widget in one; a widget lays itself out by the width it lands in
 * (a container query), never by the screen's.
 *
 * Core draws the frame and knows no module's name. What a person arranged —
 * the columns, where each widget stands, what they hid — lives on THEIR row
 * (`users.admin_preferences`), because two managers want two dashboards; a
 * widget nobody placed yet goes where its `size` says, after the placed ones,
 * so a module installed next month appears without anyone saving anything.
 *
 * A widget that throws is reported and replaced by a note, never allowed to
 * take the first page of the admin down with it.
 */
class Dashboard
{
    /** The declared sizes, kept so a manifest-era widget validates. */
    public const SIZES = ['quarter', 'third', 'half', 'wide', 'full'];

    /**
     * The columns a person can pick: each a list of widths in twelfths.
     * Named by what they look like, drawn as such in the picker.
     */
    public const LAYOUTS = [
        'main-side' => [8, 4],
        'side-main' => [4, 8],
        'thirds' => [4, 4, 4],
        'halves' => [6, 6],
        'single' => [12],
    ];

    public const DEFAULT_LAYOUT = 'main-side';

    /** The band across the whole width, above the columns. */
    public const TOP = 'top';

    /**
     * Every widget this person may see, each with `hidden`, and where it
     * stands: the layout, the zones (`top`, `c0`, `c1`, `c2` — lists of keys
     * in order) and the hidden ones.
     *
     * @return array{layout: string, widths: list<int>, widgets: array<string, array<string, mixed>>, zones: array<string, list<string>>, hidden: list<string>}
     */
    public function board(?User $user): array
    {
        $prefs = $this->preferences($user);
        $layout = $prefs['layout'];
        $columns = count(self::LAYOUTS[$layout]);
        $hidden = array_flip($prefs['hidden']);

        $widgets = [];
        foreach ((array) hook_filter('core.admin.dashboard.widgets', [], ['user' => $user]) as $widget) {
            if (! isset($widget['key'], $widget['render']) || ! is_callable($widget['render'])) {
                continue;
            }
            if (isset($widget['can']) && ! $user?->can($widget['can'])) {
                continue;
            }

            $widget['size'] = in_array($widget['size'] ?? null, self::SIZES, true) ? $widget['size'] : 'half';
            $widget['position'] = (float) ($widget['position'] ?? 100);
            $widget['hidden'] = isset($hidden[$widget['key']]);
            $widget['title'] = label_text($widget['title'] ?? null, null, $widget['key']);
            $widget['icon'] ??= 'square';
            $widgets[$widget['key']] = $widget;
        }

        uasort($widgets, fn ($a, $b) => $a['position'] <=> $b['position']);

        $zones = [self::TOP => []];
        for ($i = 0; $i < $columns; $i++) {
            $zones['c'.$i] = [];
        }

        // Placed ones first, in the order they were left.
        foreach ($prefs['zones'] as $zone => $keys) {
            $target = $this->fit($zone, $columns);
            foreach ($keys as $key) {
                if (isset($widgets[$key]) && ! $widgets[$key]['hidden'] && ! $this->placed($zones, $key)) {
                    $zones[$target][] = $key;
                }
            }
        }

        // The rest where their size says, by their own position — which is
        // where a new module's widget lands.
        foreach ($widgets as $key => $widget) {
            if (! $widget['hidden'] && ! $this->placed($zones, $key)) {
                $zones[$this->home($widget, $columns)][] = $key;
            }
        }

        return [
            'layout' => $layout,
            'widths' => self::LAYOUTS[$layout],
            'widgets' => $widgets,
            'zones' => $zones,
            'hidden' => array_values(array_keys(array_filter($widgets, fn ($w) => $w['hidden']))),
        ];
    }

    /**
     * Where a widget nobody placed starts: a wide one in the first column, a
     * narrow one in the last; with three columns a half-width one in the
     * middle. `full` does not claim the band — the band is the person's to
     * give, and a module deciding it is the most important thing on the page
     * is exactly the decision a module must not make.
     */
    protected function home(array $widget, int $columns): string
    {
        if ($columns === 1) {
            return 'c0';
        }

        return match ($widget['size']) {
            'wide', 'full' => 'c0',
            'half' => $columns === 3 ? 'c1' : 'c0',
            default => 'c'.($columns - 1),
        };
    }

    /** A zone that may no longer exist (a layout with fewer columns) folds into the last column. */
    protected function fit(string $zone, int $columns): string
    {
        if ($zone === self::TOP) {
            return self::TOP;
        }

        $index = (int) ltrim($zone, 'c');

        return 'c'.min(max(0, $index), $columns - 1);
    }

    protected function placed(array $zones, string $key): bool
    {
        foreach ($zones as $keys) {
            if (in_array($key, $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /** One widget's body, or a note saying it failed — never an exception. */
    public function render(array $widget): string
    {
        try {
            return (string) ($widget['render'])();
        } catch (Throwable $e) {
            report($e);

            return '<p class="text-sm text-error">'.e(__('This widget could not be drawn. The error is in the log.')).'</p>';
        }
    }

    /** @return array{layout: string, zones: array<string, list<string>>, hidden: list<string>} */
    public function preferences(?User $user): array
    {
        $stored = (array) (($user?->admin_preferences ?? [])['dashboard'] ?? []);
        $layout = (string) ($stored['layout'] ?? '');

        $zones = [];
        foreach ((array) ($stored['zones'] ?? []) as $zone => $keys) {
            if (is_string($zone) && preg_match('/^(top|c[0-2])$/', $zone)) {
                $zones[$zone] = array_values(array_filter((array) $keys, 'is_string'));
            }
        }

        return [
            'layout' => isset(self::LAYOUTS[$layout]) ? $layout : self::DEFAULT_LAYOUT,
            'zones' => $zones,
            'hidden' => array_values(array_filter((array) ($stored['hidden'] ?? []), 'is_string')),
        ];
    }

    /**
     * Store one person's arrangement. Written past Eloquent on purpose: a
     * personal layout is not an edit of the account — it must not move
     * `updated_at` or land in the audit trail as "changed the user".
     *
     * @param  array<string, list<string>>  $zones
     * @param  list<string>  $hidden
     */
    public function save(User $user, string $layout, array $zones, array $hidden): void
    {
        $clean = [];
        foreach ($zones as $zone => $keys) {
            if (is_string($zone) && preg_match('/^(top|c[0-2])$/', $zone)) {
                $clean[$zone] = array_values(array_unique(array_filter((array) $keys, 'is_string')));
            }
        }

        $this->write($user, [
            'layout' => isset(self::LAYOUTS[$layout]) ? $layout : self::DEFAULT_LAYOUT,
            'zones' => $clean,
            'hidden' => array_values(array_unique(array_filter($hidden, 'is_string'))),
        ]);
    }

    /** One widget out of sight, everything else as it was. */
    public function hide(User $user, string $key): void
    {
        $prefs = $this->preferences($user);
        $prefs['hidden'][] = $key;

        $this->save($user, $prefs['layout'], $prefs['zones'], $prefs['hidden']);
    }

    /** Back to every widget shown, in the owners' own order and the default columns. */
    public function reset(User $user): void
    {
        $this->write($user, null);
    }

    protected function write(User $user, ?array $dashboard): void
    {
        $prefs = (array) ($user->admin_preferences ?? []);

        if ($dashboard === null) {
            unset($prefs['dashboard']);
        } else {
            $prefs['dashboard'] = $dashboard;
        }

        $json = $prefs ? json_encode($prefs) : null;
        DB::table('users')->where('id', $user->id)->update(['admin_preferences' => $json]);
        $user->setRawAttributes(['admin_preferences' => $json] + $user->getAttributes(), true);
    }
}
