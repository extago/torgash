<?php

namespace Torgash\Catalog\Support;

use Illuminate\Support\Str;

/**
 * A characteristic, one of its values and a price type always have a code —
 * whoever wrote the row, and whatever language it is written in.
 *
 * It used to be the admin FORM that filled a blank one in, which is three
 * different holes rather than one convenience. A row created by an import, by
 * 1C, by a seeder or over the API kept a NULL code, and a NULL code is a row
 * that can be matched by nothing: `exim.match.*` asks about ONE column, a
 * filtered address is written in codes, and 1C's own links are keyed by them.
 * So the rule moved to the model, where every door passes through it, and the
 * column became NOT NULL because "assigned unless somebody found another way
 * in" is not a guarantee anything can be built on.
 *
 * **Assigned, never demanded** — core's own HasCode rule, and the reason this
 * is not that trait: these codes are uppercase (they are printed in an address
 * and in an import's header, where `SCREEN-SIZE` reads as a name and
 * `screen-size-a7f2c` does not), and a VALUE's code is unique within its
 * characteristic rather than across the table, which no table-wide trait can
 * express. The two conventions are already in every shop's database; unifying
 * them would rename rows that other systems have written down.
 *
 * Three answers, in order:
 *
 *  1. what the shop typed, kept for ever — including through a rename, or a
 *     code would stop being the stable thing it exists to be;
 *  2. the row's own name, transliterated in the language it is written in
 *     (`Цвет` → `TSVET`, not `ITEM`) — every language the name exists in is
 *     tried, base language first, because a shop that writes only Russian must
 *     not end up with a catalogue of ITEM-2, ITEM-3, ITEM-4;
 *  3. its id — `FEATURE-11`. Reached by a name made entirely of punctuation or
 *     of a script nothing transliterates, and deliberately readable: a person
 *     matching columns in a spreadsheet can find row 11, and could do nothing
 *     at all with `ITEM-7`.
 *
 * The id is not known until the INSERT, so a row that needs answer 3 is
 * written with a placeholder no one could type and renamed in `created()` —
 * two writes, on the rare path only, rather than a NULL the whole engine has
 * to keep testing for.
 */
trait AssignsCode
{
    /** Never typed (the admin form refuses anything outside [A-Z0-9_-]), never shown, never kept. */
    protected const CODE_PENDING = 'PENDING#';

    public static function bootAssignsCode(): void
    {
        // A copy is a new row and needs a name of its own — Eloquent's
        // replicate() carries the code over, and the copy would collide with
        // the thing it was copied from.
        static::replicating(fn ($model) => $model->code = null);

        static::saving(function ($model) {
            $code = trim((string) $model->code);

            if ($code !== '') {
                // However it got here, a code that is already somebody else's
                // is not this row's name. A screen where the shop types its own
                // validates first and says so, which is a better answer than
                // this quiet correction — but a quiet correction still beats a
                // unique-violation 500 from an import.
                $model->code = Codes::unique($code, $model->getTable(), $model->exists ? $model->getKey() : null, $model->codeScope());

                return;
            }

            $base = Codes::fromNames($model->codeNames());

            $model->code = $base === ''
                ? static::CODE_PENDING.Str::upper(Str::random(8))
                : Codes::unique($base, $model->getTable(), $model->exists ? $model->getKey() : null, $model->codeScope());
        });

        static::created(function ($model) {
            if (! str_starts_with((string) $model->code, static::CODE_PENDING)) {
                return;
            }

            $model->code = Codes::unique(
                $model->codeFallbackPrefix().'-'.$model->getKey(),
                $model->getTable(),
                $model->getKey(),
                $model->codeScope(),
            );

            // Quietly: this is the same act as the INSERT that has just
            // happened, not an edit of the row, and re-firing `saving` would
            // put the placeholder back.
            $model->saveQuietly();
        });
    }

    /**
     * What this row calls itself, per language — the base language is tried
     * first, then whatever else is filled in.
     *
     * @return array<string, string>
     */
    protected function codeNames(): array
    {
        return array_filter((array) ($this->name ?? []), 'is_string');
    }

    /** Unique within what — a value's code is only unique inside its characteristic. */
    protected function codeScope(): array
    {
        return [];
    }

    protected function codeFallbackPrefix(): string
    {
        return Str::upper(Str::slug(class_basename(static::class))) ?: 'ROW';
    }
}
