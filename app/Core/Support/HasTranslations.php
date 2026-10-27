<?php

namespace App\Core\Support;

/**
 * For JSONB-translatable fields: {"en": "...", "ru": "..."}.
 * Cast the attribute to 'array' and read it via $model->t('name').
 * Fallback chain: given/current locale → fallback locale → first available.
 *
 * $locale defaults to app()->getLocale() (the visitor's locale on the
 * storefront). Admin views should pass admin_content_locale() explicitly —
 * that's a separate concept (which translation admin is looking at/editing),
 * independent of the admin's own interface language.
 */
trait HasTranslations
{
    public function t(string $field, ?string $locale = null): ?string
    {
        $values = $this->{$field} ?? [];

        return $values[$locale ?? app()->getLocale()]
            ?? $values[content_base_locale()]
            ?? (is_array($values) ? reset($values) : null)
            ?: null;
    }
}
