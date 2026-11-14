<?php

namespace Torgash\GoogleAnalytics;

use App\Core\Access\Impersonation;
use App\Core\Http\Csp;
use App\Core\Privacy\Consent;
use App\Core\Seo\PageMeta;
use Illuminate\Http\Request;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Seo\ProductFacts;

/**
 * Google Analytics 4 on the storefront (PLAN §73) — by a web stream's
 * measurement ID (gtag.js), or through a Google Tag Manager container.
 *
 * Two pieces, each where it has to be:
 * - the HEAD: Google's tag — gtag.js and its start, or the container's
 *   loader — and, on a product page, `view_item`. Where the shop asks for
 *   consent the whole of it waits for "statistics" behind core's gate
 *   (`Consent::gate`) and starts on the same page once given; or, when the
 *   shop chose so AND Google's tags are told the visitor's choice (Consent
 *   Mode, `Consent::tellsGoogle()`), it loads at once and Consent Mode keeps
 *   it from storing anything until they agree; or, the shop's decision, it
 *   does not wait at all, as code in the "Necessary" category does — the
 *   consent signals stay the visitor's own, never granted on their behalf;
 * - the FOOTER: google-analytics.js, which turns the page's own events into
 *   GA4's recommended ones — add_to_cart / remove_from_cart
 *   (`torgash:cart-changed`), add_to_wishlist, begin_checkout
 *   (`torgash:checkout-started`), purchase (`torgash:order-placed`) and
 *   generate_lead for the forms the shop picked (`torgash:enquiry-sent`).
 *   Nothing is sent before the tag runs.
 *
 * Sums go in the books' currency: Google Analytics converts them into the
 * property's own. Through Tag Manager the same events go into the data
 * layer in GA4's e-commerce format, for the shop's own tags there.
 *
 * Nothing is printed for a manager looking at the shop as a customer: that
 * visit is the shop's own, and its purchase would be the manager's browser.
 */
class Tag
{
    public const MODULE = 'torgash.google-analytics';

    /** Google Analytics' own cookie, "GA1.1.<client id>", in plain text. */
    public const CLIENT_COOKIE = '_ga';

    /** The client ID as a fact an order or an enquiry writes down about the browser (ClientFacts). */
    public const FACT = 'ga_client_id';

    /** A measurement ID (gtag.js) or a Tag Manager container. */
    public const ID_PATTERN = '/^(G|GTM)-[A-Z0-9]{4,20}$/';

    /** The tag's ID, upper-cased, or null — not set, or not one. */
    public function id(): ?string
    {
        $id = strtoupper(trim((string) setting(self::MODULE, 'tag_id')));

        return preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }

    /** Is it a Tag Manager container rather than a measurement ID? */
    public function manager(): bool
    {
        return str_starts_with((string) $this->id(), 'GTM-');
    }

    /** Is the tag on this page? */
    public function active(): bool
    {
        return $this->id() !== null && ! app(Impersonation::class)->active();
    }

    public function on(string $key): bool
    {
        return (bool) setting(self::MODULE, $key);
    }

    /**
     * Does the tag wait for the visitor's consent? Not when the shop said
     * not to (`none`, its decision). Under Consent Mode only while Google's
     * tags are actually told the visitor's choice: loaded at once without
     * that, it would store its cookies on everyone, which is what `none`
     * says and `consent_mode` does not.
     */
    public function waits(): bool
    {
        return match (setting(self::MODULE, 'consent')) {
            'none' => false,
            'consent_mode' => ! Consent::tellsGoogle(),
            default => true,
        };
    }

    /** What a product is called in the events: its code, or its number in the shop. */
    public function itemId(): string
    {
        return setting(self::MODULE, 'item_id') === 'id' ? 'id' : 'sku';
    }

    /**
     * The kinds of form whose sending is a lead.
     *
     * @return list<string>
     */
    public function leads(): array
    {
        return array_values(array_filter(
            array_map('strval', (array) setting(self::MODULE, 'leads')),
            fn (string $kind) => preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $kind) === 1,
        ));
    }

    /** The head's part: the tag, and a product page's view_item — waiting for consent where it must. */
    public function head(?PageMeta $meta): string
    {
        if (! $this->active()) {
            return '';
        }

        $viewItem = null;

        if ($this->on('ecommerce') && ($product = $meta?->subject()) instanceof Product) {
            $item = self::item((new ProductFacts($product))->item(), $this->itemId());
            $viewItem = ['currency' => shop_currency(), 'value' => $item['price'], 'items' => [$item]];
        }

        $code = view('torgash.google-analytics::tag', [
            'googleTag' => $this->id(),
            'googleManager' => $this->manager(),
            'googleViewItem' => $viewItem,
        ])->render();

        return $this->waits() ? Consent::gate('statistics', $code) : app(Csp::class)->sign($code);
    }

    /** The footer's part: the script that hears the page's events. */
    public function footer(): string
    {
        if (! $this->active()) {
            return '';
        }

        return sprintf(
            '<script nonce="%s" src="%s" data-google-tag="%s" data-manager="%d" data-ecommerce="%d" data-item-id="%s" data-leads="%s" defer></script>',
            e(csp_nonce()), e(module_asset(self::MODULE, 'google-analytics.js')), e((string) $this->id()),
            $this->manager(), $this->on('ecommerce'), e($this->itemId()), e(implode(',', $this->leads())),
        );
    }

    /**
     * A product as GA4's events want it: `item_id` (the code, or the number
     * when it has none or the shop chose the number), the category path as
     * item_category … item_category5, the price per unit in the books.
     *
     * @param  array<string, mixed>  $facts  ProductFacts::item()
     * @return array<string, mixed>
     */
    public static function item(array $facts, string $idKey = 'sku'): array
    {
        $sku = (string) ($facts['sku'] ?? '');
        $item = [
            'item_id' => $idKey === 'sku' && $sku !== '' ? $sku : (string) $facts['id'],
            'item_name' => (string) ($facts['name'] ?? ''),
        ];

        if (filled($facts['brand'] ?? null)) {
            $item['item_brand'] = (string) $facts['brand'];
        }

        foreach (array_slice(array_values((array) ($facts['categories'] ?? [])), 0, 5) as $i => $name) {
            $item[$i === 0 ? 'item_category' : 'item_category'.($i + 1)] = (string) $name;
        }

        return $item + [
            'price' => round((float) ($facts['price'] ?? 0), 2),
            'quantity' => max(1, (int) ($facts['quantity'] ?? 1)),
        ];
    }

    /** The visitor's client ID from Google's cookie — "GA1.1.123456789.1700000000" is 123456789.1700000000 — or nothing. */
    public static function clientId(Request $request): ?string
    {
        $value = (string) $request->cookie(self::CLIENT_COOKIE);

        return preg_match('/^GA\d+\.\d+\.(\d{1,20}\.\d{1,20})$/', $value, $found) === 1 ? $found[1] : null;
    }
}
