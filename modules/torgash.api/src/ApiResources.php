<?php

namespace Torgash\Api;

/**
 * What the API exposes — declared by whoever owns the data, served by one
 * controller.
 *
 * The alternative was a controller per entity, and it is the shape that makes
 * an API rot: every new module means a new controller repeating paging,
 * sorting, permission checks and error shapes, each subtly its own. Here a
 * module says what it has and how to read one, and every convention in §15.1 is
 * applied to it from one place:
 *
 *     ApiResources::register('products', [
 *         'label'      => 'Products',
 *         'permission' => 'catalog.manage',
 *         'query'      => fn () => Product::query()->withCardData(),
 *         'serialize'  => fn (Product $p) => ['sku' => $p->sku, ...],
 *         'sortable'   => ['sku', 'price', 'created_at'],
 *         'filters'    => ['status' => fn ($q, $v) => $q->where('status', $v)],
 *         'lookup'     => ['external_id', 'sku'],
 *         'update'     => fn (Product $p, array $data) => ...,   // omit for read-only
 *         'schema'     => ['sku' => 'string', 'price' => 'integer'],   // for the docs
 *     ]);
 *
 * `schema`, `writable`, `description` and the prose form of a filter
 * (`['apply' => fn, 'description' => …]`) are read by nothing at runtime: they
 * are what `Torgash\Api\OpenApi` writes the reference page from. Documentation
 * lives beside the registration on purpose — a description kept in a second file
 * is a description that is wrong within a month.
 *
 * `lookup` is the part worth naming: a caller addresses a row by our id OR by
 * the key their own system knows it as, so an exchange never has to store our
 * autoincrements. `/products/1c-42` and `/products/ST-2002` and `/products/4`
 * are the same product.
 *
 * Names are SECTIONED — `admin.products`, `shop.products` — because the two are
 * genuinely different resources that happen to share a table: the admin one
 * answers with cost prices and disabled rows and is gated by a permission, the
 * shop one answers with what a visitor may see and needs no token at all.
 * Sylius splits them the same way and for the same reason; one resource with a
 * "hide some fields" flag is how a cost price eventually reaches a storefront.
 */
class ApiResources
{
    /** @var array<string, array<string, mixed>> */
    protected static array $resources = [];

    protected static bool $announced = false;

    /**
     * Asks the modules for their resources, once.
     *
     * Lazily rather than from this module's boot(), because provider boot order
     * is not ours to depend on: if the API booted first, every module's
     * listener would still be unregistered and the registry would come out
     * empty on some machines and full on others. Asked at the first read
     * instead, when everything has certainly booted.
     */
    protected static function announce(): void
    {
        if (static::$announced) {
            return;
        }

        static::$announced = true;

        hook_action('api.resources');
    }

    /** @param array<string, mixed> $definition */
    public static function register(string $name, array $definition): void
    {
        $definition += [
            'label' => ['en' => $name],
            'description' => null,
            'permission' => null,
            'query' => null,
            'serialize' => fn ($model) => $model->toArray(),
            'sortable' => [],
            'filters' => [],
            'lookup' => [],
            'update' => null,
            // fn (array $data): Model — making a new row, for the few
            // resources where that is an act the shop's own code performs
            // (issuing a gift card), described by `creatable` like `writable`.
            'create' => null,
            'creatable' => [],
            'writable' => [],
            'schema' => [],
            'actions' => [],
        ];

        $definition['filters'] = array_map(static::callable(...), $definition['filters']);
        $definition['actions'] = array_map(static::callable(...), $definition['actions']);

        static::$resources[$name] = $definition;
    }

    /**
     * A filter or an action may be written as a bare closure or as a closure
     * with a sentence beside it — the same choice a manifest gives a permission,
     * and for the same reason: the shape that is quickest to write is the one
     * that gets written, and prose that is optional is prose that exists.
     *
     * Normalised on the way in, so every reader sees one shape and nobody has to
     * remember which resource used which. Run it with `$filter['apply']`.
     *
     * @param  callable|array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    protected static function callable(callable|array $spec): array
    {
        return is_array($spec) ? $spec : ['apply' => $spec];
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        static::announce();

        return static::$resources;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $name): ?array
    {
        static::announce();

        return static::$resources[$name] ?? null;
    }

    /** For tests, which register throwaway resources and must not leak them. */
    public static function forget(string $name): void
    {
        unset(static::$resources[$name]);
    }
}
