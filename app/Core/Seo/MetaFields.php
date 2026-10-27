<?php

namespace App\Core\Seo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The SEO card on an admin form, read back off the request.
 *
 * The three text fields are jsonb, so each one has to be merged into whatever
 * the OTHER languages hold rather than overwritten — the admin edits one
 * content locale at a time. That merge is four lines, and four lines copied
 * into the product form, the category form, the brand form and the page form
 * is four chances to lose a translation on save. So it lives here, beside the
 * component that draws the same fields ({@see resources/views/components/seo}).
 *
 * The column map is a parameter because one module's columns are named
 * differently and will stay that way: `torgash.pages` had `page_title` before
 * any of this existed, and renaming it would rewrite every page a shop wrote.
 */
class MetaFields
{
    /** input name => column, for a model with the conventional columns. */
    public const COLUMNS = [
        'meta_title' => 'meta_title',
        'meta_description' => 'meta_description',
        'h1' => 'h1',
    ];

    /** @return array<string, string|array<string>> */
    public static function rules(array $columns = self::COLUMNS): array
    {
        $rules = [
            'noindex' => 'nullable|boolean',
            // Free-form rather than a URL rule: the page this one is a copy of
            // may be on another site entirely, and a relative address is a
            // legitimate (if less common) answer.
            'canonical_url' => 'nullable|string|max:2048',
        ];

        foreach (array_keys($columns) as $input) {
            $rules[$input] = 'nullable|string|max:'.($input === 'meta_description' ? 500 : 255);
        }

        return $rules;
    }

    /**
     * What to save, merged into the languages already there.
     *
     * A field left blank REMOVES this locale's value rather than storing an
     * empty string — "" and "not stated" have to be the same thing, or a page
     * whose description was cleared would print an empty tag for ever.
     *
     * @param  array<string, string>  $columns  input name => column
     * @return array<string, mixed>
     */
    public static function attributes(Request $request, ?Model $record = null, array $columns = self::COLUMNS): array
    {
        $locale = admin_content_locale();
        $attributes = [];

        foreach ($columns as $input => $column) {
            $values = (array) ($record?->{$column} ?? []);
            $typed = trim((string) $request->input($input, ''));

            if ($typed === '') {
                unset($values[$locale]);
            } else {
                $values[$locale] = $typed;
            }

            $attributes[$column] = $values === [] ? null : $values;
        }

        $attributes['noindex'] = $request->boolean('noindex');
        $attributes['canonical_url'] = trim((string) $request->input('canonical_url', '')) ?: null;

        return $attributes;
    }
}
