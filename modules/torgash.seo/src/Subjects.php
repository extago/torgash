<?php

namespace Torgash\Seo;

/**
 * What kinds of page this shop has, answered by whoever owns them.
 *
 * `torgash.seo` names none of them. The catalogue registers products,
 * categories and brands; `torgash.pages` registers pages; a module that invents
 * a kind of page tomorrow appears on the audit screen by answering the same
 * hook. That is the whole reason this module can be installed on a shop with no
 * catalogue at all and simply have nothing to show.
 *
 * A registration says how to LIST the rows, what to call one, where it lives on
 * the storefront and where it is edited — plus, only where the columns differ
 * from the conventional ones, what they are called.
 *
 * Asked lazily through `seo.subjects`, because provider boot order is not ours
 * to depend on.
 */
class Subjects
{
    /** The conventional columns — everything but `torgash.pages` uses these. */
    public const COLUMNS = ['title' => 'meta_title', 'description' => 'meta_description', 'h1' => 'h1'];

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $subjects = null;

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->subjects !== null) {
            return $this->subjects;
        }

        $subjects = [];

        foreach (hook_filter('seo.subjects', [], []) as $kind => $definition) {
            $subjects[$kind] = [
                'kind' => $kind,
                'label' => $definition['label'] ?? [],
                'query' => $definition['query'] ?? null,
                'title' => $definition['title'] ?? fn ($model) => (string) $model->getKey(),
                'url' => $definition['url'] ?? fn () => null,
                'edit_url' => $definition['edit_url'] ?? fn () => null,
                // Only for a kind that is also a SCOPE a template can be tied
                // to. A listing wants `title` per row; a picker wants the whole
                // set in whatever order that kind is read in — a category tree
                // indented, for instance — and only its owner knows that.
                'options' => $definition['options'] ?? null,
                'columns' => $definition['columns'] ?? self::COLUMNS,
            ];
        }

        return $this->subjects = $subjects;
    }

    /** @return array<string, mixed>|null */
    public function find(string $kind): ?array
    {
        return $this->all()[$kind] ?? null;
    }

    public function label(string $kind): string
    {
        $label = $this->find($kind)['label'] ?? [];

        return label_text($label, null, $kind);
    }

    /** kind => label, for a select. */
    public function options(): array
    {
        $options = [];

        foreach (array_keys($this->all()) as $kind) {
            $options[$kind] = $this->label($kind);
        }

        return $options;
    }
}
