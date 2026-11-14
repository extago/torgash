<?php

namespace Torgash\Metrika;

use App\Core\Access\Impersonation;
use App\Core\Http\Csp;
use App\Core\Privacy\Consent;
use App\Core\Seo\PageMeta;
use App\Core\Support\Currencies;
use Illuminate\Http\Request;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Seo\ProductFacts;

/**
 * The Yandex Metrica counter on the storefront (PLAN §73).
 *
 * Three pieces, each where it has to be:
 * - the HEAD: the e-commerce container (`dataLayerYM`, its own name, so
 *   Google's `dataLayer` and a tag manager are left alone), the product a
 *   product page shows ("detail"), and the counter itself — through core's
 *   consent gate (`Consent::gate`), so where the shop asks for consent it
 *   waits for "statistics" and starts on the same page once given; unless
 *   the shop unticked `wait_consent` and counts everyone, as code in the
 *   "Necessary" category does (its decision). What goes into the container
 *   leaves nobody's browser until the counter reads it;
 * - the FOOTER: metrika.js, which turns the page's own events into the
 *   counter's — `torgash:cart-changed` (add, remove, the `basket` goal),
 *   `torgash:order-placed` (purchase, `order`), `torgash:wishlist-changed`,
 *   `torgash:one-click-opened`, `torgash:enquiry-sent` (a call back) — and
 *   counts an address the page changed itself ("Show more");
 * - the RESPONSE: Metrica shows the shop's pages inside its own (the click
 *   map, Webvisor's recordings), which `frame-ancestors` has to allow.
 *
 * Metrica does not convert currencies — a sum in another than the counter's
 * comes out as zero — so every sum is sent in the counter's currency,
 * converted from the books at the shop's rate.
 *
 * Nothing is printed for a manager looking at the shop as a customer: that
 * visit is the shop's own, and its purchase would be the manager's browser.
 */
class Counter
{
    public const MODULE = 'torgash.metrika';

    /** The counter's own id of the visitor — its ClientID — set by tag.js, plain text. */
    public const CLIENT_COOKIE = '_ym_uid';

    /** The ClientID as a fact an order or an enquiry writes down about the browser (ClientFacts). */
    public const FACT = 'metrika_client_id';

    public const CONTAINER = 'dataLayerYM';

    public const GOALS = ['basket', 'order', 'wishlist', 'buy_with_one_click_form_opened', 'call_request'];

    /**
     * Where Metrica draws the shop's pages: its interface in every domain it
     * has, and Webvisor's player (Metrica's help, "Installing the counter on
     * a site with CSP" and "Click map").
     */
    public const FRAME_ANCESTORS = [
        'https://metrika.yandex.ru', 'https://metrika.yandex.by', 'https://metrika.yandex.com', 'https://metrika.yandex.com.tr',
        'https://metrika.yandex.kz', 'https://metrika.yandex.uz', 'https://metrika.yandex', 'https://metrika.ya.ru',
        'https://metrica.yandex.ru', 'https://metrica.yandex.by', 'https://metrica.yandex.com', 'https://metrica.yandex.com.tr',
        'https://metrica.yandex.kz', 'https://metrica.yandex', 'https://metrica.ya.ru',
        'https://analytics.yandex.ru', 'https://analytics.yandex.by', 'https://analytics.yandex.com', 'https://analytics.yandex.com.tr',
        'https://analytics.yandex.kz',
        'https://metr.yandex.ru', 'https://metr.yandex.by', 'https://metr.yandex.com', 'https://metr.yandex.com.tr', 'https://metr.yandex.kz',
        'https://webvisor.com', 'https://*.webvisor.com',
    ];

    /** The counter's number, or null — not set, or not a number. */
    public function id(): ?string
    {
        $id = trim((string) setting(self::MODULE, 'counter_id'));

        return preg_match('/^\d{1,12}$/', $id) === 1 ? $id : null;
    }

    /** Is the counter on this page? */
    public function active(): bool
    {
        return $this->id() !== null && ! app(Impersonation::class)->active();
    }

    public function on(string $key): bool
    {
        return (bool) setting(self::MODULE, $key);
    }

    /**
     * The currency the counter counts in, and what one unit of the books is
     * in it. A currency since deleted is the books again.
     *
     * @return array{0: string, 1: float}
     */
    public function currency(): array
    {
        $code = (string) setting(self::MODULE, 'currency');
        $currency = $code !== '' ? app(Currencies::class)->find($code) : null;

        return $currency ? [(string) $currency->code, (float) $currency->rate ?: 1.0] : [shop_currency(), 1.0];
    }

    /** What the counter is started with. */
    public function init(): array
    {
        return array_filter([
            'clickmap' => $this->on('clickmap'),
            'trackLinks' => $this->on('track_links'),
            'accurateTrackBounce' => $this->on('accurate_bounce'),
            'webvisor' => $this->on('webvisor'),
            'trackHash' => $this->on('track_hash'),
        ]) + ($this->on('ecommerce') ? ['ecommerce' => self::CONTAINER] : []);
    }

    /** The head's part: the container, a product page's product, and the counter waiting for consent where it must. */
    public function head(?PageMeta $meta): string
    {
        if (! $this->active()) {
            return '';
        }

        [$currency, $rate] = $this->currency();
        $detail = null;

        if ($this->on('ecommerce') && ($product = $meta?->subject()) instanceof Product) {
            $item = (new ProductFacts($product))->item();
            unset($item['quantity']);
            $detail = ['ecommerce' => ['currencyCode' => $currency, 'detail' => ['products' => [self::product($item, $rate)]]]];
        }

        $counter = view('torgash.metrika::counter', ['metrikaId' => $this->id(), 'metrikaInit' => $this->init()])->render();

        return view('torgash.metrika::head', ['metrikaDetail' => $detail])->render()
            .($this->on('wait_consent') ? Consent::gate('statistics', $counter) : app(Csp::class)->sign($counter));
    }

    /** The footer's part: the script that hears the page's events. */
    public function footer(): string
    {
        if (! $this->active()) {
            return '';
        }

        [$currency, $rate] = $this->currency();
        $goals = array_values(array_intersect(self::GOALS, (array) setting(self::MODULE, 'goals')));

        return sprintf(
            '<script nonce="%s" src="%s" data-metrika-counter="%s" data-goals="%s" data-ecommerce="%d" data-currency="%s" data-rate="%s" data-hits="%d" defer></script>',
            e(csp_nonce()), e(module_asset(self::MODULE, 'metrika.js')), e((string) $this->id()), e(implode(',', $goals)),
            $this->on('ecommerce'), e($currency), e((string) $rate), $this->on('count_hits'),
        );
    }

    /** Do Metrica's maps or recordings draw the shop's pages inside Metrica? */
    public function frameable(): bool
    {
        return $this->id() !== null && ($this->on('webvisor') || $this->on('clickmap') || $this->on('track_links'));
    }

    /**
     * A product as Metrica's e-commerce wants it: the category as a path of
     * up to five levels joined by "/" (a "/" inside a name would make a level
     * of its own), the price in the counter's currency.
     *
     * @param  array<string, mixed>  $item  ProductFacts::item()
     * @return array<string, mixed>
     */
    public static function product(array $item, float $rate = 1.0): array
    {
        $category = implode('/', array_map(fn ($name) => str_replace('/', '|', (string) $name), (array) ($item['categories'] ?? [])));

        return array_filter([
            'id' => (string) $item['id'],
            'name' => (string) ($item['name'] ?? ''),
            'price' => round((float) ($item['price'] ?? 0) * $rate, 2),
            'brand' => $item['brand'] ?? null,
            'category' => $category,
            'quantity' => $item['quantity'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** The visitor's ClientID from the counter's cookie: digits, or nothing. */
    public static function clientId(Request $request): ?string
    {
        $value = (string) $request->cookie(self::CLIENT_COOKIE);

        return preg_match('/^\d{6,30}$/', $value) === 1 ? $value : null;
    }
}
