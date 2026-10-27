<?php

namespace Torgash\Catalog\Selections;

/**
 * Where a product block's products come FROM — an open registry, asked lazily
 * through `catalog.product_sources`, the same shape as the filter flags and the
 * sorts.
 *
 * The catalogue states what it can answer from its own tables: the whole
 * catalogue, a category (with everything under it, as a category always means
 * here), a label, a yes/no filter, and products picked by hand. A promotion is
 * `torgash.promotions`' to add, because the catalogue may not name its classes.
 *
 * A source is
 *
 *     'category' => [
 *         'label'   => 'Category',
 *         'choices' => fn (): array => [id => 'what the admin picks'],   // optional
 *         'apply'   => fn (Builder $query, string $arg, array $settings, array $context): Builder,
 *         'heading' => fn (string $arg): ?string,   // what it is called, optional
 *         'url'     => fn (string $arg): ?string,   // its own page, optional
 *         'order'   => fn (Builder $query, string $arg, array $settings, array $context): Builder,  // optional
 *     ]
 *
 * and a block stores ONE value, `kind:arg` (`category:12`), or the bare kind for
 * a source without choices. One select rather than a kind plus a field per kind
 * shown and hidden: a stored category id beside a stored label id, one of them
 * ignored, is two answers to one question waiting to disagree.
 *
 * `apply` narrows a query that is already `Product::listed()`: whatever a module
 * says this visitor may not see stays unseen through a block too. A source whose
 * argument no longer means anything (a deleted category, a label switched off, a
 * promotion that has ended) narrows to NOTHING — a block that silently widened
 * into the whole catalogue under the heading "Laptops" is the one wrong answer.
 *
 * `$context` is the zone's (see Selection::products()) — `product` on the
 * product page. A source about "this product" without one answers nothing.
 *
 * `order` is what "its own order" means for this source, where it has one
 * (products picked by hand come in the order they were typed); without it, the
 * shop's default sort.
 *
 * A registered per-request singleton, like Flags and Sorts.
 */
class Sources
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $sources = null;

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $sources = [];

        foreach (hook_filter('catalog.product_sources', [], []) as $kind => $definition) {
            if (! is_string($kind) || str_contains($kind, ':') || ! is_callable($definition['apply'] ?? null)) {
                continue;
            }

            $sources[$kind] = $definition + ['label' => [], 'choices' => null, 'heading' => null, 'url' => null, 'order' => null];
        }

        return $this->sources = $sources;
    }

    /**
     * `category:12` → [definition, '12']; null for a kind nobody answers for.
     *
     * @return array{0: array<string, mixed>, 1: string}|null
     */
    public function resolve(string $value): ?array
    {
        [$kind, $arg] = array_pad(explode(':', $value, 2), 2, '');

        $definition = $this->all()[$kind] ?? null;

        return $definition ? [$definition, $arg] : null;
    }

    public function label(array $definition): string
    {
        $label = $definition['label'] ?? [];

        return label_text($label);
    }

    /**
     * Every value a block may store, for the admin's select — the kinds with no
     * choices first, then one group per kind that has them.
     *
     * @return list<array{value: string, label: string, group?: string}>
     */
    public function options(): array
    {
        $plain = [];
        $grouped = [];

        foreach ($this->all() as $kind => $definition) {
            if (! is_callable($definition['choices'])) {
                $plain[] = ['value' => $kind, 'label' => $this->label($definition)];

                continue;
            }

            foreach (($definition['choices'])() as $arg => $label) {
                $grouped[] = ['value' => $kind.':'.$arg, 'label' => (string) $label, 'group' => $this->label($definition)];
            }
        }

        return [...$plain, ...$grouped];
    }

    /** For tests. */
    public function flush(): void
    {
        $this->sources = null;
    }
}
