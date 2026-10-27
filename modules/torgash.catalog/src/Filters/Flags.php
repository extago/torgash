<?php

namespace Torgash\Catalog\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The yes/no filters — "in stock", "on sale" — as a registry rather than as
 * markup.
 *
 * The panel had exactly one of these, written into the blade by hand, so a shop
 * could neither turn it off nor add a second one. Both halves of that are the
 * same missing idea: a flag is a NAMED thing with a word on screen and a way to
 * narrow a query, and which of them a shop offers is a setting
 * (`torgash.catalog` → `filter_flags`).
 *
 * Registered by whoever owns the data — this module states the two it can
 * answer from its own columns, `torgash.warehouses` could state "here today",
 * `torgash.promotions` adds a branch to the discount one (see the `on_sale`
 * registration below) — and asked lazily through `catalog.filters.flags`,
 * because provider boot order is not ours to depend on.
 *
 * Three rules, each of which is a bug we would otherwise have shipped twice:
 *
 * **`apply` narrows a QUERY, never a loaded page.** The grid is paginated, so a
 * flag that filtered the collection afterwards would leave page 2 holding
 * eleven products and the pager claiming forty — and the count under the
 * heading would be the unfiltered one.
 *
 * **Only an OFFERED flag applies.** `?in_stock=1` on a shop that has just
 * unticked the box shows the whole category, because the panel no longer draws
 * the control that would let anybody untick it. A filter with no way off it is
 * the trap §101 and §103 are both about.
 *
 * **Every REGISTERED flag's key is reserved** ({@see FilterQuery::RESERVED}),
 * offered or not: a characteristic coded `in_stock` may not own that word one
 * day and lose it the next because a shop ticked a box in the settings.
 *
 * A registered per-request singleton, like Support\Labels and PriceTypes: the
 * grid, the panel and the chip row all ask, and the answer involves a hook.
 */
class Flags
{
    /** @var array<string, array{key: string, label: array<string, string>, apply: callable}>|null */
    protected ?array $flags = null;

    /** @var array<string, bool> */
    protected array $narrowable = [];

    /**
     * Every flag any module can answer for, in registration order.
     *
     * @return array<string, array{key: string, label: array<string, string>, apply: callable}>
     */
    public function all(): array
    {
        if ($this->flags !== null) {
            return $this->flags;
        }

        $flags = [];

        foreach (hook_filter('catalog.filters.flags', [], []) as $key => $definition) {
            if (! is_string($key) || ! isset($definition['apply']) || ! is_callable($definition['apply'])) {
                continue;
            }

            $flags[$key] = [
                'key' => $key,
                'label' => $definition['label'] ?? [],
                'apply' => $definition['apply'],
            ];
        }

        return $this->flags = $flags;
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

    /** key => label, for the settings screen's tick list. */
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
     * The setting's default is `["in_stock"]` rather than everything: a module
     * installing itself must not silently add a control to the storefront, and
     * that one value is exactly what the panel showed before this class
     * existed.
     *
     * @return list<string>
     */
    public function offered(): array
    {
        $chosen = (array) setting('torgash.catalog', 'filter_flags', ['in_stock']);
        $chosen = array_map('strval', $chosen);

        return array_values(array_filter(
            array_keys($this->all()),
            fn (string $key) => in_array($key, $chosen, true),
        ));
    }

    /**
     * The offered flags that can actually narrow THIS listing — §103's rule,
     * applied to a control that has no values to thin out.
     *
     * Measured against the category as a whole, never against what is already
     * ticked: the moment a flag's own state narrows the question, ticking it
     * makes everything else vanish and there is no way to widen again but
     * editing the address. So "In stock" stays on offer on a page where
     * everything happens to be in stock, and goes only where nothing is —
     * which is the same answer narrowToWhatIsHere() gives for a characteristic
     * nothing here carries.
     *
     * One `exists()` per offered flag, and a shop offers two or three. The
     * query is bounded by LIMIT 1, so it costs the same on a catalogue of
     * fifty and of fifty thousand — which is what QueryBudgetTest measures.
     *
     * @param  Builder  $base  the listing's product query, unfiltered
     * @return list<string>
     */
    public function offeredFor(Builder $base): array
    {
        // Keyed by the listing as well as by the flag: one request renders one
        // listing today, but a page showing two categories side by side would
        // otherwise be told about the first one's stock twice.
        $listing = md5($base->toSql().serialize($base->getBindings()));

        return array_values(array_filter($this->offered(), function (string $key) use ($base, $listing) {
            return $this->narrowable[$listing.':'.$key]
                ??= ($this->find($key)['apply'])(clone $base)->exists();
        }));
    }

    /**
     * Which flags this request has in force. Offered ones only — see the class
     * docblock.
     *
     * @return list<string>
     */
    public function active(Request $request): array
    {
        return array_values(array_filter(
            $this->offered(),
            fn (string $key) => $request->boolean($key),
        ));
    }

    /** Narrows a product query by every flag in force. */
    public function apply(Builder $query, Request $request): Builder
    {
        return $this->applyKeys($query, $this->active($request));
    }

    /**
     * The same narrowing, for flags that are not on the request: a published
     * page carries its own (`m_catalog_filter_pages.flags`), and a page is an
     * ADDRESS rather than a control — so it applies what it names whether or
     * not the shop currently offers that box in the panel.
     *
     * A key nobody registers narrows to NOTHING instead of being skipped. The
     * module that knew what "here today" meant has been uninstalled; showing
     * the whole category under a heading that promises a narrower set is the
     * one answer that lies to the visitor and to a crawler at once. The page
     * still answers, still says what it is, and works out its own `noindex`
     * from being empty.
     *
     * @param  list<string>  $keys
     */
    public function applyKeys(Builder $query, array $keys): Builder
    {
        foreach ($keys as $key) {
            $definition = $this->find($key);

            $query = $definition
                ? ($definition['apply'])($query)
                : $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * Has this request got any flag on it at all?
     *
     * Asked by the panel, which shows a "clear everything" only where there is
     * something to clear. It is no longer what decides a canonical: a flag can
     * now be part of a published address (`m_catalog_filter_pages.flags`), so
     * the canonical is decided by matching the WHOLE question — values plus
     * flags — against what the shop has published.
     */
    public function anyActive(Request $request): bool
    {
        return $this->active($request) !== [];
    }

    /** For tests and for a settings screen that has just saved. */
    public function flush(): void
    {
        $this->flags = null;
        $this->narrowable = [];
    }
}
