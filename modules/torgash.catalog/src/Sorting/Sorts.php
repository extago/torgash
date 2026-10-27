<?php

namespace Torgash\Catalog\Sorting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Torgash\Catalog\Stock\AvailabilityStatuses;

/**
 * In what ORDER a listing answers — an open registry, exactly as the yes/no
 * filters are ({@see \Torgash\Catalog\Filters\Flags}), and for the same reason:
 * the three listings called `->latest()` in their own hand, so a shop could
 * neither choose another order nor offer the visitor a choice at all.
 *
 * A sort is a NAMED thing with a word on screen and a way to order a query.
 * Which of them exist is a registry (`catalog.sorts`, asked lazily — provider
 * boot order is not ours to depend on); which of them this shop offers is a
 * setting (`catalog_sorts`), and which one it starts on is another
 * (`default_sort`).
 *
 * The rules are the ones the filters already live by, and each is a bug this
 * would otherwise have shipped:
 *
 * **`apply` orders a QUERY, never a loaded page.** The grid is paginated: a
 * collection sorted after the fact orders the twelve rows page 1 happened to
 * receive, which looks right on the first screen and is wrong everywhere else.
 *
 * **Only an OFFERED sort applies.** `?sort=price_desc` on a shop that has just
 * unticked that box falls back to the default rather than ordering by it — the
 * panel no longer draws the control that would let anybody choose otherwise,
 * and an order nothing on screen explains reads as a broken page.
 *
 * **`sort` is reserved whatever a shop ticks** ({@see
 * \Torgash\Catalog\Filters\FilterQuery::RESERVED}, where the word has been
 * held since the filters learned to speak): what an address MEANS may not
 * depend on a tick box in the settings.
 *
 * Nothing had to be done about the canonical: `PageMeta::$canonicalParams`
 * holds `page` alone, so `?sort=` falls out of it by itself — which is the
 * right answer, since sorting changes the ORDER of a set of products and not
 * the set.
 *
 * **"Out of stock last" is not a sort**, it is a tumbler applied BEFORE
 * whichever one is chosen (`out_of_stock_last`) — otherwise every sort in the
 * registry would need a sold-out twin, and a module registering its own would
 * have to know about the shop's stock policy to stay consistent with the rest.
 * What counts as "out of stock" is not `stock <= 0` but the same answer that
 * decides whether the product can be bought at all (PLAN §31.1): a preorder
 * with a negative balance is orderable and stays where its sort puts it.
 * Since PLAN §31.6 the two groups are WEIGHTS on the statuses themselves
 * (`AvailabilityStatuses::applyRank()`): by default still "buyable, then the
 * rest", but a shop may put preorder below in-stock or mix a status of its own
 * in with it — the setting's key kept its old name, so no shop lost its tick.
 *
 * A registered per-request singleton, like Filters\Flags: the listing, the
 * select and the settings screen all ask, and the answer involves a hook.
 */
class Sorts
{
    /** @var array<string, array{key: string, label: array<string, string>, icon: string, apply: callable}>|null */
    protected ?array $sorts = null;

    /**
     * Every sort any module can answer for, in registration order.
     *
     * @return array<string, array{key: string, label: array<string, string>, icon: string, apply: callable}>
     */
    public function all(): array
    {
        if ($this->sorts !== null) {
            return $this->sorts;
        }

        $sorts = [];

        foreach (hook_filter('catalog.sorts', [], []) as $key => $definition) {
            if (! is_string($key) || ! isset($definition['apply']) || ! is_callable($definition['apply'])) {
                continue;
            }

            $sorts[$key] = [
                'key' => $key,
                'label' => $definition['label'] ?? [],
                // A Lucide name drawn beside the word in the listing's sort
                // menu. Optional: a module that names none gets the generic
                // up-and-down arrows rather than a menu with a hole in it.
                'icon' => (string) ($definition['icon'] ?? '') ?: 'arrow-up-down',
                'apply' => $definition['apply'],
            ];
        }

        return $this->sorts = $sorts;
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function label(string $key): string
    {
        $label = $this->find($key)['label'] ?? [];

        return label_text($label, null, $key);
    }

    public function icon(string $key): string
    {
        return $this->find($key)['icon'] ?? 'arrow-up-down';
    }

    /** key => label, for the settings screen's tick list and its default select. */
    public function options(): array
    {
        $options = [];

        foreach (array_keys($this->all()) as $key) {
            $options[$key] = $this->label($key);
        }

        return $options;
    }

    /**
     * The ones this shop has ticked, in registration order.
     *
     * The default is every sort the catalogue itself registers, unlike the
     * filter flags' deliberately minimal one: a flag adds a control that
     * narrows what a visitor is shown, while a sort only offers the same
     * products in another order, and a select with one entry in it is not a
     * choice anybody has to be protected from.
     *
     * @return list<string>
     */
    public function offered(): array
    {
        $chosen = (array) setting('torgash.catalog', 'catalog_sorts', ['popular', 'newest', 'price_asc', 'price_desc', 'name']);
        $chosen = array_map('strval', $chosen);

        return array_values(array_filter(
            array_keys($this->all()),
            fn (string $key) => in_array($key, $chosen, true),
        ));
    }

    /**
     * What a listing sorts by when the visitor has not said.
     *
     * The shop's setting, when it is still on offer; otherwise the first thing
     * that is. Unticking the sort a shop had made its default may not leave
     * every listing in whatever order Postgres felt like returning rows in.
     */
    public function default(): string
    {
        $offered = $this->offered();

        if ($offered === []) {
            return '';
        }

        $chosen = (string) setting('torgash.catalog', 'default_sort', 'newest');

        return in_array($chosen, $offered, true) ? $chosen : $offered[0];
    }

    /**
     * Which sort THIS request is in — an offered one it asked for, else the
     * shop's default. Never a word nobody registered: an unknown `?sort=` is
     * not an error worth a 404, it is a link from an older version of the shop
     * or somebody's typo, and the honest answer is the listing in its usual
     * order.
     */
    public function current(Request $request): string
    {
        $asked = (string) $request->get('sort', '');

        return in_array($asked, $this->offered(), true) ? $asked : $this->default();
    }

    /**
     * Order a listing: the sold-out tumbler first, then the chosen sort.
     *
     * The tumbler goes first because it is a coarser question than any sort —
     * "is this worth showing at all today" comes before "cheapest first" — and
     * because an ORDER BY written the other way round would leave it deciding
     * nothing but ties.
     *
     * `id` closes every ordering, so a page boundary cannot fall inside a group
     * of rows the database is free to return in any order it likes: without it
     * a product can appear on page 1 and again on page 2 while a third is never
     * shown at all. It matters most for the one thing this shop is about to do
     * — import a catalogue, where a thousand products share a creation second.
     */
    public function apply(Builder $query, Request $request): Builder
    {
        return $this->applyKey($query, $this->current($request));
    }

    /**
     * Order a query by a sort the SHOP named, rather than one a visitor asked
     * for — a product block on the home page. Any registered sort, offered or
     * not: "only an offered sort applies" is about a control the panel draws,
     * and a block's order is chosen in the admin where no such control exists
     * (a shop may well want "best rated" for one block without putting it in
     * every listing's select). An unknown key falls back to the default, the
     * same forgiving answer `current()` gives an old link.
     */
    public function applyKey(Builder $query, string $key): Builder
    {
        if (setting('torgash.catalog', 'out_of_stock_last', false)) {
            app(AvailabilityStatuses::class)->applyRank($query);
        }

        $definition = $this->find($key) ?? $this->find($this->default());

        $query = $definition ? ($definition['apply'])($query) : $query->latest();

        return $query->orderBy('m_catalog_products.id', 'desc');
    }

    /** For tests and for a settings screen that has just saved. */
    public function flush(): void
    {
        $this->sorts = null;
    }
}
