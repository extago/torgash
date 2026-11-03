<?php

namespace Torgash\Catalog\Exim;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Catalog\Support\CategoryTree;

/**
 * WHICH products an export profile takes (PLAN §59.4, after the big engines'
 * product feed export): the profile's `filters` for the `product` entity,
 * read by `exim.query.product` and written by the profile form.
 *
 * Every key is optional and an absent one narrows nothing:
 *
 *   statuses            list of Product::STATUSES; default ['active']
 *   price_min/max       major units of the books (the `price` column)
 *   stock_min/max       whole units
 *   availability        keys of the shop's availability statuses (in_stock,
 *                       preorder, …): the product's is one of them
 *   in_stock_only       orderable now — the availability status allows it
 *                       and, where it tracks stock, there is some
 *   with_description    a description or a short description in any language
 *   with_image          a photo of its own, or a picture by address while
 *                       the shop allows those
 *   stop_words          words; a product mentioning ANY of them in a ticked
 *                       field is left out
 *   stop_fields         name | short_description | description | seo
 *   categories          ids; categories_subtree takes their subtrees too
 *   categories_exclude  ids; categories_exclude_subtree likewise
 *   labels              ids: the product wears at least one of them
 *   skus_include        SKUs taken as well as (`or`) or as well as being
 *                       within (`and`, include_operator) the rest
 *   skus_exclude        SKUs never taken
 *   features            [{feature, op, value}]: conditions on the shop's
 *                       characteristics, joined by features_operator
 *                       (and | or)
 *
 * The legacy `enabled_only` (true = "active only") is read when `statuses`
 * is absent, so a profile from before keeps meaning what it meant.
 */
class ProductSelection
{
    public const STOP_FIELDS = ['name', 'short_description', 'description', 'seo'];

    public const OPS = ['eq', 'neq', 'in', 'not_in', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty'];

    /** English names of the operators, translated by the lang file. */
    public const OP_LABELS = [
        'eq' => 'equals',
        'neq' => 'is not',
        'in' => 'is one of (comma-separated)',
        'not_in' => 'is none of (comma-separated)',
        'contains' => 'contains',
        'not_contains' => 'does not contain',
        'gt' => 'is greater than',
        'gte' => 'is at least',
        'lt' => 'is less than',
        'lte' => 'is at most',
        'empty' => 'is empty',
        'not_empty' => 'is set',
    ];

    /** The form's fields, normalised: numbers as numbers, lists as lists, unknown words dropped. */
    public static function fromInput(array $input): array
    {
        $list = fn (string $key) => collect(preg_split('/[\s,;]+/', (string) ($input[$key] ?? '')) ?: [])
            ->map(fn ($v) => trim((string) $v))->filter(fn ($v) => $v !== '')->unique()->values()->all();
        $lines = fn (string $key) => collect(preg_split('/[\r\n,;]+/', (string) ($input[$key] ?? '')) ?: [])
            ->map(fn ($v) => trim((string) $v))->filter(fn ($v) => $v !== '')->unique()->values()->all();
        $ids = fn (string $key) => collect((array) ($input[$key] ?? []))->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->unique()->values()->all();
        $number = fn (string $key) => ($input[$key] ?? '') === '' || $input[$key] === null || ! is_numeric(str_replace(',', '.', (string) $input[$key]))
            ? null : (float) str_replace(',', '.', (string) $input[$key]);
        $whole = fn (string $key) => $number($key) === null ? null : (int) $number($key);

        $statuses = array_values(array_intersect(Product::STATUSES, (array) ($input['statuses'] ?? [])));

        $features = [];
        foreach ((array) ($input['features'] ?? []) as $row) {
            $feature = (int) ($row['feature'] ?? 0);
            $op = (string) ($row['op'] ?? 'eq');
            if ($feature <= 0 || ! in_array($op, self::OPS, true)) {
                continue;
            }
            $features[] = ['feature' => $feature, 'op' => $op, 'value' => trim((string) ($row['value'] ?? ''))];
        }

        return [
            'statuses' => $statuses ?: [Product::STATUS_ACTIVE],
            'enabled_only' => $statuses === [] || $statuses === [Product::STATUS_ACTIVE],
            'price_min' => $number('price_min'),
            'price_max' => $number('price_max'),
            'stock_min' => $whole('stock_min'),
            'stock_max' => $whole('stock_max'),
            'availability' => array_values(array_intersect(
                array_keys(app(AvailabilityStatuses::class)->all()),
                array_map('strval', (array) ($input['availability'] ?? [])),
            )),
            'in_stock_only' => (bool) ($input['in_stock_only'] ?? false),
            'with_description' => (bool) ($input['with_description'] ?? false),
            'with_image' => (bool) ($input['with_image'] ?? false),
            'stop_words' => $lines('stop_words'),
            'stop_fields' => array_values(array_intersect(self::STOP_FIELDS, (array) ($input['stop_fields'] ?? []))) ?: ['name'],
            'categories' => $ids('categories'),
            'categories_subtree' => (bool) ($input['categories_subtree'] ?? false),
            'categories_exclude' => $ids('categories_exclude'),
            'categories_exclude_subtree' => (bool) ($input['categories_exclude_subtree'] ?? false),
            'labels' => $ids('labels'),
            'skus_include' => $list('skus_include'),
            'skus_exclude' => $list('skus_exclude'),
            'include_operator' => ($input['include_operator'] ?? 'or') === 'and' ? 'and' : 'or',
            'features' => $features,
            'features_operator' => ($input['features_operator'] ?? 'and') === 'or' ? 'or' : 'and',
        ];
    }

    /** True when the filters narrow nothing beyond "active only". */
    public static function isPlain(array $filters): bool
    {
        $plain = self::fromInput(['statuses' => [Product::STATUS_ACTIVE]]);
        foreach ($plain as $key => $value) {
            if ($key === 'enabled_only' || $key === 'stop_fields' || $key === 'include_operator' || $key === 'features_operator') {
                continue;
            }
            if (($filters[$key] ?? $value) != $value) {
                return false;
            }
        }

        return true;
    }

    /** @param  Builder<Product>  $query */
    public static function apply(Builder $query, array $filters): Builder
    {
        $statuses = (array) ($filters['statuses'] ?? (($filters['enabled_only'] ?? true) ? [Product::STATUS_ACTIVE] : Product::STATUSES));
        $statuses = array_values(array_intersect(Product::STATUSES, $statuses)) ?: [Product::STATUS_ACTIVE];
        if ($statuses !== Product::STATUSES) {
            $query->whereIn('m_catalog_products.status', $statuses);
        }

        $scale = price_scale();
        if (isset($filters['price_min']) && $filters['price_min'] !== '') {
            $query->where('m_catalog_products.price', '>=', (int) $scale->fromMajor($filters['price_min']));
        }
        if (isset($filters['price_max']) && $filters['price_max'] !== '') {
            $query->where('m_catalog_products.price', '<=', (int) $scale->fromMajor($filters['price_max']));
        }
        if (isset($filters['stock_min']) && $filters['stock_min'] !== '') {
            $query->where('m_catalog_products.stock', '>=', (int) $filters['stock_min']);
        }
        if (isset($filters['stock_max']) && $filters['stock_max'] !== '') {
            $query->where('m_catalog_products.stock', '<=', (int) $filters['stock_max']);
        }

        $availability = array_map('strval', (array) ($filters['availability'] ?? []));
        if ($availability !== []) {
            $query->whereIn('m_catalog_products.availability_status', $availability);
        }

        if ($filters['in_stock_only'] ?? false) {
            self::orderable($query);
        }

        if ($filters['with_description'] ?? false) {
            $query->where(function (Builder $q) {
                $q->whereRaw(self::nonEmptyText('m_catalog_products.description'))
                    ->orWhereRaw(self::nonEmptyText('m_catalog_products.short_description'));
            });
        }

        if ($filters['with_image'] ?? false) {
            $query->where(function (Builder $q) {
                $q->whereHas('media', fn ($m) => $m->where('collection_name', 'gallery'));
                if (setting('torgash.catalog', 'external_images', false)) {
                    $q->orWhere(fn (Builder $e) => $e->whereNotNull('m_catalog_products.image_url')->where('m_catalog_products.image_url', '<>', ''));
                }
            });
        }

        $words = (array) ($filters['stop_words'] ?? []);
        $fields = array_values(array_intersect(self::STOP_FIELDS, (array) ($filters['stop_fields'] ?? ['name'])));
        if ($words !== [] && $fields !== []) {
            $columns = [];
            foreach ($fields as $field) {
                $columns = [...$columns, ...match ($field) {
                    'seo' => ['m_catalog_products.meta_title', 'm_catalog_products.meta_description'],
                    default => ['m_catalog_products.'.$field],
                }];
            }
            foreach ($words as $word) {
                $like = '%'.self::escapeLike((string) $word).'%';
                foreach ($columns as $column) {
                    $query->whereRaw('not exists (select 1 from jsonb_each_text(coalesce('.$column.", '{}'::jsonb)) t where t.value ilike ?)", [$like]);
                }
            }
        }

        $exclude = self::categoryIds((array) ($filters['categories_exclude'] ?? []), (bool) ($filters['categories_exclude_subtree'] ?? false));
        if ($exclude !== []) {
            $query->whereDoesntHave('categories', fn ($c) => $c->whereIn('m_catalog_categories.id', $exclude));
        }

        $skusExclude = array_map('strval', (array) ($filters['skus_exclude'] ?? []));
        if ($skusExclude !== []) {
            $query->whereNotIn('m_catalog_products.sku', $skusExclude);
        }

        $labels = array_map('intval', (array) ($filters['labels'] ?? []));
        if ($labels !== []) {
            $query->whereHas('labels', fn ($l) => $l->whereIn('m_catalog_labels.id', $labels));
        }

        // The within: categories and characteristics. SKUs named to include
        // come as well as it (`or`) or must also be within it (`and`).
        $include = self::categoryIds((array) ($filters['categories'] ?? []), (bool) ($filters['categories_subtree'] ?? false));
        $features = (array) ($filters['features'] ?? []);
        $skusInclude = array_map('strval', (array) ($filters['skus_include'] ?? []));
        $within = function (Builder $q) use ($include, $features, $filters) {
            if ($include !== []) {
                $q->whereHas('categories', fn ($c) => $c->whereIn('m_catalog_categories.id', $include));
            }
            if ($features !== []) {
                $any = ($filters['features_operator'] ?? 'and') === 'or';
                $q->where(function (Builder $group) use ($features, $any) {
                    foreach ($features as $condition) {
                        $any
                            ? $group->orWhere(fn (Builder $one) => self::feature($one, $condition))
                            : self::feature($group, $condition);
                    }
                });
            }
        };

        if ($skusInclude !== [] && ($filters['include_operator'] ?? 'or') === 'or' && ($include !== [] || $features !== [])) {
            $query->where(function (Builder $q) use ($within, $skusInclude) {
                $q->where($within)->orWhereIn('m_catalog_products.sku', $skusInclude);
            });
        } else {
            $within($query);
            if ($skusInclude !== []) {
                $query->whereIn('m_catalog_products.sku', $skusInclude);
            }
        }

        return $query;
    }

    /** How many products the filters take right now. */
    public static function count(array $filters): int
    {
        return self::apply(Product::query(), $filters)->count();
    }

    /** The categories named, with their subtrees when asked. @return list<int> */
    protected static function categoryIds(array $ids, bool $subtree): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        return $ids === [] ? [] : ($subtree ? app(CategoryTree::class)->descendants($ids) : $ids);
    }

    /** Products a customer may order now, by the shop's availability statuses and the stock. */
    protected static function orderable(Builder $query): void
    {
        $free = [];
        $tracked = [];
        foreach (app(AvailabilityStatuses::class)->all() as $key => $def) {
            if (! ($def['orderable'] ?? false)) {
                continue;
            }
            ($def['tracks_stock'] ?? false) && ! ($def['allow_negative'] ?? false) ? $tracked[] = $key : $free[] = $key;
        }

        $query->where(function (Builder $q) use ($free, $tracked) {
            $q->whereRaw('false');
            if ($free !== []) {
                $q->orWhereIn('m_catalog_products.availability_status', $free);
            }
            if ($tracked !== []) {
                $q->orWhere(fn (Builder $t) => $t->whereIn('m_catalog_products.availability_status', $tracked)->where('m_catalog_products.stock', '>', 0));
            }
        });
    }

    /**
     * One condition on a characteristic: the product's row for that feature,
     * its value read as text in ANY language (a picked value's names, or the
     * row's own text). "is not", "is none of", "does not contain" and "is
     * empty" are the ABSENCE of a matching row, so a product without the
     * characteristic passes them — the way the words read.
     */
    protected static function feature(Builder $query, array $condition): void
    {
        $featureId = (int) ($condition['feature'] ?? 0);
        $op = (string) ($condition['op'] ?? 'eq');
        $value = trim((string) ($condition['value'] ?? ''));
        $negative = in_array($op, ['neq', 'not_in', 'not_contains', 'empty'], true);

        $rows = function (QueryBuilder $pf) use ($op, $value) {
            $pf->from('m_catalog_product_features as pf')
                ->leftJoin('m_catalog_feature_values as fv', 'fv.id', '=', 'pf.feature_value_id')
                ->whereColumn('pf.product_id', 'm_catalog_products.id');

            // Every spelling the row has: the picked value's names and the typed text.
            $texts = "(select t.value from jsonb_each_text(coalesce(fv.value, '{}'::jsonb)) t union select pf.value_text)";
            $some = fn (string $where, array $bindings = []) => $pf->whereRaw("exists (select 1 from {$texts} x where x.value is not null and {$where})", $bindings);

            switch ($op) {
                case 'eq':
                case 'neq':
                    $some('lower(btrim(x.value)) = lower(?)', [$value]);
                    break;
                case 'in':
                case 'not_in':
                    $values = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
                    if ($values === []) {
                        $pf->whereRaw('false');
                        break;
                    }
                    $some('lower(btrim(x.value)) in ('.implode(',', array_fill(0, count($values), 'lower(?)')).')', $values);
                    break;
                case 'contains':
                case 'not_contains':
                    $some('x.value ilike ?', ['%'.self::escapeLike($value).'%']);
                    break;
                case 'gt':
                case 'gte':
                case 'lt':
                case 'lte':
                    $sign = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op];
                    $number = (float) str_replace(',', '.', $value);
                    // Only what reads as a number is compared; "16 GB" is read
                    // through its leading number. The group is non-capturing:
                    // substring() hands back the first CAPTURED group when
                    // there is one, which for "128 GB" is nothing at all.
                    $leading = "substring(replace(btrim(x.value), ',', '.') from '^-?[0-9]+(?:\\.[0-9]+)?')";
                    $some("coalesce({$leading}, '') <> '' and {$leading}::numeric {$sign} ?", [$number]);
                    break;
                case 'empty':
                case 'not_empty':
                    $some("btrim(x.value) <> ''");
                    break;
            }
        };

        $sub = DB::query();
        $rows($sub);
        $sub->where('pf.feature_id', $featureId)->selectRaw('1');

        $negative ? $query->whereNotExists($sub) : $query->whereExists($sub);
    }

    protected static function nonEmptyText(string $column): string
    {
        return "exists (select 1 from jsonb_each_text(coalesce({$column}, '{}'::jsonb)) d where btrim(d.value) <> '')";
    }

    protected static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** What the form offers: features, categories, labels — in the admin's content language. */
    public static function choices(): array
    {
        $locale = admin_content_locale();

        return [
            'features' => Feature::query()->orderBy('sort')->orderBy('id')->get()
                ->mapWithKeys(fn (Feature $f) => [$f->id => ($f->t('name', $locale) ?: (string) $f->code).($f->code ? ' ('.$f->code.')' : '')])->all(),
            'categories' => app(CategoryTree::class)->options(),
            'labels' => Label::query()->orderBy('sort')->orderBy('id')->get()
                ->mapWithKeys(fn (Label $l) => [$l->id => $l->name_for_admin ?: ($l->t('name', $locale) ?: (string) $l->id)])->all(),
            'statuses' => Product::STATUSES,
            'availability' => app(AvailabilityStatuses::class)->options(),
            'ops' => self::OP_LABELS,
            'stop_fields' => self::STOP_FIELDS,
        ];
    }
}
