<?php

namespace App\Core\Seo;

/**
 * {@see PageSubject} for a model that keeps the conventional columns:
 * `meta_title`, `meta_description`, `h1` (jsonb, translatable), `noindex`
 * (bool) and `canonical_url`.
 *
 * A model whose columns are named differently implements `pageMeta()` itself —
 * the pages module does, because its title column was called `page_title` a
 * release before this existed and renaming it would rewrite everybody's pages.
 *
 * The one rule worth stating: a translatable meta field is read at the CURRENT
 * locale and NEVER falls back to another language, which is the opposite of
 * `t()`. A Russian page printing the English description the shop happened to
 * fill in is worse than printing the sentence the controller worked out in
 * Russian — and that sentence is exactly what an absent value falls through to.
 */
trait HasPageMeta
{
    /** @return array{title: ?string, description: ?string, h1: ?string, noindex: bool, canonical: ?string} */
    public function pageMeta(): array
    {
        return [
            'title' => $this->pageMetaText('meta_title'),
            'description' => $this->pageMetaText('meta_description'),
            'h1' => $this->pageMetaText('h1'),
            'noindex' => (bool) ($this->noindex ?? false),
            'canonical' => $this->canonical_url ?: null,
        ];
    }

    public function pageMetaKind(): string
    {
        return static::PAGE_META_KIND;
    }

    /**
     * The languages this record really has words in — its name (or title,
     * or a brand's value) filled — or null when it cannot tell. PageMeta
     * advertises only these as translations and points the canonical of an
     * untranslated address at one that has them (audit 2026-10-15: "Дрели"
     * was served at the English address as English).
     *
     * @return list<string>|null
     */
    public function pageLanguages(): ?array
    {
        foreach (['name', 'title', 'value'] as $field) {
            // A column, never a method of that name (FilterPage::title()).
            if (! array_key_exists($field, $this->getAttributes())) {
                continue;
            }
            $values = $this->getAttribute($field);
            if (is_array($values)) {
                return array_values(array_keys(array_filter($values, fn ($text) => is_string($text) && trim($text) !== '')));
            }
        }

        return null;
    }

    /** One translatable meta column at the current locale, with no cross-language fallback. */
    protected function pageMetaText(string $field): ?string
    {
        $values = $this->{$field} ?? null;

        if (is_string($values)) {
            return trim($values) ?: null;
        }

        return is_array($values)
            ? (trim((string) ($values[app()->getLocale()] ?? '')) ?: null)
            : null;
    }
}
