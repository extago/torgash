<?php

namespace Torgash\Wishlist\Mail;

use Torgash\Catalog\Models\Product;
use Torgash\Wishlist\Letters;
use Torgash\Wishlist\Models\WishList;

/**
 * The wishlist's two letters in core's registry (PLAN §41): something saved
 * is back, something saved is cheaper. Both are the CUSTOMER'S consent, so
 * the way out of them is an appendix the shop cannot edit away; the products
 * are a block — photograph, name, price (and what it was), each a link in
 * the list's own language and money (Letters: a worker has no request).
 */
class WishlistLetters
{
    public const BACK = 'wishlist.back_in_stock';

    public const CHEAPER = 'wishlist.price_dropped';

    /** @return array<string, array<string, mixed>> */
    public static function events(): array
    {
        $common = [
            'group' => 'Wishlist',
            'audience' => 'customer',
            'button' => ['label' => 'Open the wishlist', 'url' => '{wishlist_url}'],
            'help' => [
                'products' => 'The products, with photographs and prices (on a line of its own)',
                'count' => 'How many products',
                'wishlist_url' => 'The link to the wishlist',
            ],
        ];

        return [
            self::BACK => $common + [
                'label' => 'Wishlist: back in stock',
                'subject' => 'Something in your wishlist is back in stock',
                'heading' => 'Something you saved can be bought again.',
                'body' => '{products}',
                'tokens' => fn (array $c) => self::tokens($c),
                'blocks' => fn (array $c) => ['products' => self::products($c['list'] ?? null, self::backRows($c))],
                'append' => fn (array $c) => self::append($c['list'] ?? null, 'stock', __('You asked to be told when something in your wishlist is back in stock.')),
                'sample' => fn () => self::sample(false),
            ],
            self::CHEAPER => $common + [
                'label' => 'Wishlist: cheaper now',
                'subject' => 'Something in your wishlist is cheaper now',
                'heading' => 'Something you saved is cheaper now.',
                'body' => '{products}',
                'tokens' => fn (array $c) => self::tokens($c),
                'blocks' => fn (array $c) => ['products' => self::products($c['list'] ?? null, self::dropRows($c))],
                'append' => fn (array $c) => self::append($c['list'] ?? null, 'price', __('You asked to be told when something in your wishlist gets cheaper.')),
                'sample' => fn () => self::sample(true),
            ],
        ];
    }

    /** @return array<string, string> */
    protected static function tokens(array $c): array
    {
        $list = $c['list'] ?? null;

        return $list ? [
            'count' => (string) count((array) ($c['product_ids'] ?? $c['drops'] ?? [])),
            'wishlist_url' => Letters::url($list, 'wishlist.index'),
        ] : [];
    }

    /** @return list<array<string, mixed>> */
    protected static function backRows(array $c): array
    {
        $list = $c['list'] ?? null;

        if (! $list) {
            return [];
        }

        return Product::whereIn('id', (array) ($c['product_ids'] ?? []))->withCardData()->get()
            ->map(fn (Product $product) => [
                'name' => $product->t('name'),
                'url' => Letters::url($list, 'catalog.product', $product->slug),
                'image' => Letters::image($product->primaryImageUrl(160)),
                'price' => Letters::money($list, $product->publicPrice()),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    protected static function dropRows(array $c): array
    {
        $list = $c['list'] ?? null;
        $drops = (array) ($c['drops'] ?? []);

        if (! $list || $drops === []) {
            return [];
        }

        $products = Product::whereIn('id', array_column($drops, 'product_id'))->withCardData()->get()->keyBy('id');
        $rows = [];

        foreach ($drops as $drop) {
            if ($product = $products->get($drop['product_id'])) {
                $rows[] = [
                    'name' => $product->t('name'),
                    'url' => Letters::url($list, 'catalog.product', $product->slug),
                    'image' => Letters::image($product->primaryImageUrl(160)),
                    'old' => Letters::money($list, (int) $drop['old']),
                    'new' => Letters::money($list, (int) $drop['new']),
                    'percent' => (int) round(100 * ($drop['old'] - $drop['new']) / max(1, $drop['old'])),
                ];
            }
        }

        return $rows;
    }

    protected static function products(?WishList $list, array $rows): string
    {
        return $list ? view('torgash.wishlist::mail.products', ['letterRows' => $rows])->render() : '';
    }

    protected static function append(?WishList $list, string $what, string $why): string
    {
        return $list ? e($why).' <a href="'.e(Letters::unsubscribeUrl($list, $what)).'" style="color:inherit;">'.e(__('Stop these letters')).'</a>' : '';
    }

    /** A real list with something in it, for the editor's preview. */
    protected static function sample(bool $drops): ?array
    {
        $list = WishList::whereHas('items')->with('items')->latest('id')->first();

        if (! $list) {
            return ['list' => null];
        }

        $ids = $list->items->pluck('product_id')->take(3)->all();

        return $drops
            ? ['list' => $list, 'drops' => array_map(fn ($id) => ['product_id' => $id, 'old' => 1200_00, 'new' => 990_00], $ids)]
            : ['list' => $list, 'product_ids' => $ids];
    }
}
