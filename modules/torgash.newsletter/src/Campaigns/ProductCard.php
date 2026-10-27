<?php

namespace Torgash\Newsletter\Campaigns;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Product;

/**
 * Products in a campaign's letter, in tables with the styles on the elements
 * — the only layout every mail client draws:
 *
 * - `{product:SKU}` — one card: the picture beside the name, price, button;
 * - `{products:SKU1,SKU2,SKU3}` — a grid of tiles, picture on top, in rows of
 *   three (two for two or four products; `{products:A,B,C|2}` says how many
 *   a row). The tiles are blocks of a maximum width, so a phone stacks them.
 *
 * A product the shop does not show (hidden, gone, an unknown code) is left
 * out rather than drawn broken; without the catalogue module the tokens
 * simply disappear. One query for all of a grid's products.
 */
class ProductCard
{
    /** A picture a mail client gets as the file itself: classic Outlook does not draw WebP. */
    public const ORIGINAL_UP_TO = 400 * 1024;

    public static function html(string $sku): string
    {
        $product = self::products([$sku])->first();

        if (! $product) {
            return '';
        }

        $url = route('catalog.product', $product->slug);
        $image = self::picture($product);

        $picture = $image
            ? '<a href="'.e($url).'"><img src="'.e($image).'" width="160" alt="'.e($product->t('name')).'" style="display:block;width:160px;max-width:100%;height:auto;border:0;border-radius:8px;"></a>'
            : '';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;border:1px solid #e2e8f0;border-radius:12px;border-collapse:separate;">'
            .'<tr>'
            .($picture !== '' ? '<td width="176" valign="top" style="padding:12px;">'.$picture.'</td>' : '')
            .'<td valign="middle" style="padding:12px;">'
            .'<a href="'.e($url).'" style="color:#0f172a;text-decoration:none;font-size:16px;font-weight:600;line-height:1.35;">'.e($product->t('name')).'</a>'
            .'<div style="margin:8px 0 12px;">'.self::priceHtml($product, 18).'</div>'
            .self::button($url)
            .'</td></tr></table>';
    }

    /** `{products:…}`: the codes as typed, `|N` for the tiles in a row. */
    public static function grid(string $spec): string
    {
        [$list, $perRow] = array_pad(explode('|', $spec, 2), 2, null);
        $skus = array_values(array_filter(array_map('trim', explode(',', html_entity_decode($list)))));
        $products = self::products($skus);

        if ($products->isEmpty()) {
            return '';
        }

        $perRow = (int) $perRow ?: (in_array($products->count(), [2, 4], true) ? 2 : 3);
        $perRow = max(1, min(4, $perRow, $products->count()));
        // The frame's text is 544px wide: tiles of a share of it, 8px apart.
        $tileWidth = (int) floor(544 / $perRow) - 8;

        // "Fluid" tiles: blocks of a MAXIMUM width side by side, so a narrow
        // phone screen puts them one under the other by itself — no media
        // query, which the shop's letter frame does not carry and many mail
        // apps would drop anyway. A fixed table of cells overlapped instead.
        $tiles = $products->map(fn (Product $product) => '<div style="display:inline-block;vertical-align:top;width:100%;max-width:'.$tileWidth.'px;margin:4px;text-align:left;font-size:15px;">'.self::tile($product).'</div>')->implode('');

        return '<div style="margin:10px -4px;font-size:0;text-align:center;">'.$tiles.'</div>';
    }

    protected static function tile(Product $product): string
    {
        $url = route('catalog.product', $product->slug);
        $image = self::picture($product);

        $picture = $image
            ? '<a href="'.e($url).'"><img src="'.e($image).'" alt="'.e($product->t('name')).'" width="100%" style="display:block;width:100%;height:auto;border:0;border-radius:8px;background:#f8fafc;"></a>'
            : '<a href="'.e($url).'" style="display:block;height:120px;border-radius:8px;background:#f1f5f9;"></a>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:12px;border-collapse:separate;">'
            .'<tr><td style="padding:10px 10px 0;">'.$picture.'</td></tr>'
            .'<tr><td style="padding:10px;">'
            .'<a href="'.e($url).'" style="display:block;color:#0f172a;text-decoration:none;font-size:14px;font-weight:600;line-height:1.35;word-break:break-word;">'.e($product->t('name')).'</a>'
            .'<div style="margin:6px 0 10px;">'.self::priceHtml($product, 16).'</div>'
            .self::button($url, small: true)
            .'</td></tr></table>';
    }

    /** @return Collection<int, Product> in the order the codes were written */
    protected static function products(array $skus): Collection
    {
        if ($skus === [] || ! class_exists(Product::class)) {
            return collect();
        }

        $found = Product::visible()->withCardData()->whereIn('sku', $skus)->get()->keyBy('sku');

        return collect($skus)->map(fn ($sku) => $found->get($sku))->filter()->values();
    }

    /** An absolute address of the product's first photo — the file itself when it is light and not WebP. */
    protected static function picture(Product $product): ?string
    {
        $media = $product->getFirstMedia('gallery');

        if ($media && in_array($media->mime_type, ['image/jpeg', 'image/png'], true) && $media->size <= self::ORIGINAL_UP_TO) {
            return url($media->getUrl());
        }

        $image = $product->primaryImageUrl(400);

        return $image ? url($image) : null;
    }

    /**
     * The price as the card on the storefront says it: a discount shows the
     * old price struck through and "−N%" — the same Product::oldPrice() (the
     * shop's choice, Omnibus included) and discountPercent() as the card, for
     * a guest. Not beside a "from": the old price was the bare product's.
     */
    protected static function priceHtml(Product $product, int $size): string
    {
        $shown = app(\Torgash\Catalog\Pricing\ShownPrice::class);
        $price = '<span style="font-size:'.$size.'px;font-weight:700;color:'.($product->discountPercent() > 0 ? '#dc2626' : '#0f172a').';">'.e(self::plain($shown->format($product))).'</span>';

        if ($shown->isFrom($product) || ($percent = $product->discountPercent()) <= 0) {
            return str_replace('color:#dc2626', 'color:#0f172a', $price);
        }

        return '<span style="font-size:13px;color:#94a3b8;text-decoration:line-through;">'.e(self::plain(format_price($product->oldPrice()))).'</span>'
            .' <span style="display:inline-block;font-size:12px;font-weight:700;line-height:1;color:#ffffff;background:#dc2626;border-radius:6px;padding:3px 6px;vertical-align:1px;">−'.$percent.'%</span>'
            .'<br>'.$price;
    }

    protected static function plain(string $text): string
    {
        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
    }

    protected static function button(string $url, bool $small = false): string
    {
        $color = \App\Core\Mail\Frame::settings()['color'];

        // A tile's button fills the tile: on a phone three tiles a row are
        // narrow, and a button wider than its cell spills over the next one.
        return $small
            ? '<a href="'.e($url).'" style="display:block;box-sizing:border-box;width:100%;text-align:center;background:'.e($color).';color:#ffffff;text-decoration:none;font-weight:600;font-size:13px;line-height:1.3;padding:8px 6px;border-radius:8px;">'.e(__('View the product')).'</a>'
            : '<a href="'.e($url).'" style="display:inline-block;background:'.e($color).';color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:10px 18px;border-radius:8px;">'.e(__('View the product')).'</a>';
    }
}
