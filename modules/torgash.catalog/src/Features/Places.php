<?php

namespace Torgash\Catalog\Features;

/**
 * Where a characteristic is SHOWN — the filter panel, the specification
 * section, the short list under the buy button.
 *
 * One `purpose` column used to answer two different questions at once. "Info"
 * meant "a spec, and not in the filters"; "Filter" meant "in the filters, and
 * also a spec" — so a shop could never ask for the combinations it actually
 * wanted: filterable but not printed among the specifications, printed under
 * the buy button and nowhere else, or a characteristic that is only ever
 * addressed by a published page and drawn nowhere at all.
 *
 * So `purpose` keeps only what a characteristic IS (a plain one, a variation
 * axis, a brand whose values get pages) and WHERE it appears is this list.
 *
 * **Being drawn and being filterable are different questions.** Nothing here
 * decides whether `?material=steel` narrows the catalogue: every characteristic
 * with a code is a word an address can be written in, whatever a shop has
 * ticked. The alternative was measured against the rest of the engine and is
 * wrong: a published filter page (`m_catalog_filter_pages`) is a set of
 * characteristic VALUES at an address of its own, so a characteristic that
 * stopped narrowing when its box was unticked would silently widen every page
 * built on it into its whole category — under a heading still promising less,
 * which is the one answer that misleads a visitor and a crawler at the same
 * time. Unticking `filters` therefore says "do not OFFER this control", and the
 * way off such a filter is the chip row, which names every filter in force
 * whether its box is drawn or not (§102).
 *
 * That is also why this is not the same rule `Filters\Flags` lives by, where an
 * un-offered flag does not apply: a flag is the SHOP's own tick box in the
 * settings and has no published address behind it, and a flag in force with no
 * control drawn would be a filter with no way off it.
 *
 * A registry rather than three columns, for the reason
 * `m_catalog_filter_pages.flags` holds keys rather than a column per filter: a
 * module that draws characteristics somewhere of its own — a comparison table,
 * a printable data sheet — registers its place through `catalog.feature.places`
 * and is tickable the same day, without a migration on another module's table.
 *
 * Stateless: the answer is a handful of arrays from one filter, and the callers
 * (the feature form, the filter panel, the product page) ask once each.
 */
class Places
{
    /** Ticked for a characteristic nobody has said anything about — what `purpose = filter` used to mean. */
    public const DEFAULT = ['filters', 'specs', 'buy_box'];

    /**
     * Every place any module can draw a characteristic in, in registration
     * order.
     *
     * Asked lazily through the hook, because provider boot order is not ours to
     * depend on — the rule every other registry in this module follows.
     *
     * @return array<string, array{key: string, label: array<string, string>, help?: array<string, string>}>
     */
    public function all(): array
    {
        $places = [];

        foreach (hook_filter('catalog.feature.places', [], []) as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                continue;
            }

            $places[$key] = [
                'key' => $key,
                'label' => $definition['label'] ?? $key,
                'help' => $definition['help'] ?? null,
            ];
        }

        return $places;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function label(string $key, ?string $locale = null): string
    {
        $place = $this->all()[$key] ?? null;
        $locale ??= app()->getLocale();

        if (! $place) {
            return $key;
        }

        return label_text($place['label'] ?? null, $locale, $key);
    }
}
