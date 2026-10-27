<?php

namespace Torgash\Catalog\Support;

use App\Core\Seo\RedirectManager;
use Illuminate\Database\Eloquent\Model;

/**
 * A catalogue address that follows the model, not the screen that saved it.
 *
 * - A changed slug leaves a redirect from the old address, whoever changed
 *   it: the admin form did, an import, the API or a script did not — a shop
 *   renaming products in bulk lost every old address (the first live shop's
 *   report, 2026-10-27).
 * - A product or category in the bin gives its address up: the column is
 *   unique across the bin too, so a deleted category kept "phones" and the
 *   shop had to rename it to deleted-… before it could make a new one. The
 *   slug becomes `<slug>-deleted-<id>`, written quietly (no redirect from a
 *   live address to a deleted one), and restoring takes the address back
 *   when nobody has taken it meanwhile.
 *
 * The model says what its address looks like (`addressPath()`).
 */
trait KeepsAddress
{
    abstract public function addressPath(string $slug): string;

    protected static function bootKeepsAddress(): void
    {
        static::updated(function (Model $model) {
            $was = (string) $model->getOriginal('slug');

            if (! $model->wasChanged('slug') || $was === '' || $model->trashed() || static::isBinnedSlug($was, $model->getKey())) {
                return;
            }

            app(RedirectManager::class)->recordChange($model->addressPath($was), $model->addressPath((string) $model->slug));
        });

        static::deleted(function (Model $model) {
            if ($model->isForceDeleting() || static::isBinnedSlug((string) $model->slug, $model->getKey())) {
                return;
            }

            $model->slug = $model->slug.'-deleted-'.$model->getKey();
            $model->saveQuietly();
        });

        static::restoring(function (Model $model) {
            $suffix = '-deleted-'.$model->getKey();

            if (! static::isBinnedSlug((string) $model->slug, $model->getKey())) {
                return;
            }

            $own = substr((string) $model->slug, 0, -strlen($suffix));

            if ($own !== '' && ! static::withTrashed()->where('slug', $own)->whereKeyNot($model->getKey())->exists()) {
                $model->slug = $own;
            }
        });
    }

    protected static function isBinnedSlug(string $slug, mixed $id): bool
    {
        return str_ends_with($slug, '-deleted-'.$id);
    }
}
