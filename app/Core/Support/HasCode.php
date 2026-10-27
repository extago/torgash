<?php

namespace App\Core\Support;

use Illuminate\Support\Str;

/**
 * A stable, human-typeable name for a row that has to survive leaving the
 * database.
 *
 * An id identifies a row only inside one database. A configuration exported to
 * a spreadsheet and imported back — into this shop after a deletion moved the
 * sequence on, or into another shop entirely — needs something that means the
 * same thing at both ends, and that is what exim matches on (`exim.match.*`
 * asks about ONE column, which is also why this is unique across the table and
 * not within a parent).
 *
 * It is `external_id`'s sibling and not its replacement: that one is what the
 * OTHER system calls the row and belongs to whoever is exchanging with us;
 * this one is what WE call it, and we are the ones who have to keep it stable.
 *
 * **Assigned, never demanded.** A shop that exports nothing must not be made
 * to name two hundred warranty choices, so a row that arrives without a code
 * is given one derived from whatever it already calls itself. A shop that does
 * care types its own and this never touches it again — including when the name
 * it was derived from changes, because a code that followed a rename would
 * stop being the thing this exists to be.
 *
 * In core beside `HasTranslations` for the same reason that one is: two
 * modules that do not know each other need the identical behaviour, and two
 * copies of it would drift on the first edge case (a Cyrillic-only name, which
 * slugs to nothing at all).
 */
trait HasCode
{
    public static function bootHasCode(): void
    {
        // A COPY is a new row and needs a name of its own. Eloquent's
        // `replicate()` carries every attribute over, code included, and the
        // copy would then collide with the thing it was copied from — which is
        // exactly what "copy this shared question onto my product" does
        // (measured: a unique-constraint violation on save).
        static::replicating(fn ($model) => $model->code = null);

        static::saving(function ($model) {
            $code = trim((string) $model->code);

            if ($code === '') {
                $model->code = static::uniqueCode($model->codeSource());

                return;
            }

            // A code that is already somebody else's is not this row's name,
            // however it got here. Regenerating beats a 500 on save; a screen
            // where the shop types its own should validate first and say so,
            // which is a better error than this quiet correction.
            $taken = static::query()
                ->where('code', $code)
                ->when($model->exists, fn ($q) => $q->whereKeyNot($model->getKey()))
                ->exists();

            if ($taken) {
                $model->code = static::uniqueCode($code);
            }
        });
    }

    /**
     * What this row calls itself, for a code to be derived from. Overridden by
     * anything whose name is not in `admin_name`.
     */
    protected function codeSource(): string
    {
        return (string) ($this->admin_name ?? '');
    }

    /**
     * A slug nothing else in this table holds.
     *
     * The fallback is deliberate rather than ornamental: `Str::slug()` answers
     * '' for a name written entirely in Cyrillic, and an empty code is one
     * nothing can be matched by. The suffix is random rather than a counter
     * because a counter needs a query per attempt and races two imports
     * against each other.
     */
    protected static function uniqueCode(string $source): string
    {
        $base = Str::limit(Str::slug($source), 150, '') ?: 'row';
        $code = $base;

        while (static::query()->where('code', $code)->exists()) {
            $code = $base.'-'.Str::lower(Str::random(5));
        }

        return $code;
    }
}
