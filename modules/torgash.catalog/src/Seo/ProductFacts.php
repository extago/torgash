<?php

namespace Torgash\Catalog\Seo;

use App\Core\Content\Markdown;
use App\Core\Support\Currencies;
use Illuminate\Support\Str;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\ShownPrice;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Catalog\Support\ShortDescription;
use Torgash\Media\Gallery;

/**
 * What is true about a product, for a reader that is not a person.
 *
 * One class behind both machine-readable renditions — the JSON-LD in the page
 * head and the Markdown at `<url>.md` — because the two used to be one
 * template each and would answer differently within a release: the head said
 * one price and the shop charged another. Structured data that disagrees with
 * the page is worse than none at all (a search engine treats it as a lie about
 * the offer), so there is one place that decides and two that print.
 *
 * Every answer here is for NOBODY IN PARTICULAR — `publicPrice()`, never
 * `effectivePrice()`. A crawler has no account, no customer group and no
 * session, and a price computed for whoever happened to be logged in is the
 * same bug `publicPrice()` was written for (a manager in a wholesale group
 * exporting the catalogue at half price).
 */
class ProductFacts
{
    public function __construct(public readonly Product $product) {}

    public function name(): string
    {
        return (string) $this->product->t('name');
    }

    public function url(): string
    {
        return route('catalog.product', $this->product->slug);
    }

    /**
     * What one of these can be bought for.
     *
     * The CHEAPEST one, where the product demands an answer that costs money
     * before the buy button works (PLAN §33.7) — which is the whole reason
     * this class exists said again: the head quoting the bare configuration
     * while the page quotes the buildable price is the head and the shop
     * disagreeing about the offer, and a search engine reads that as a lie
     * rather than as a rounding.
     *
     * @return array{minor: int, amount: string, currency: string}
     */
    public function price(): array
    {
        return $this->money(app(ShownPrice::class)->publicMinimum($this->product));
    }

    /** Is that price a floor rather than the price — "from 45 000"? */
    public function priceIsFrom(): bool
    {
        return app(ShownPrice::class)->publicMinimum($this->product) > (int) $this->product->publicPrice();
    }

    /**
     * What it cost before, when that is more than it costs now — otherwise
     * null, and never beside a "from": the old price is about the bare
     * product, and setting it against the cheapest BUILD claims a discount
     * that was never given (the card drops it for the same reason).
     */
    public function oldPrice(): ?array
    {
        if ($this->priceIsFrom()) {
            return null;
        }

        $old = $this->product->oldPrice();

        return $old > $this->product->publicPrice() ? $this->money($old) : null;
    }

    public function inStock(): bool
    {
        return $this->product->isOrderable();
    }

    /**
     * The shop's own words, not the number: `stockLabel()` honours the
     * "show stock levels" setting, and a text rendition that disclosed an exact
     * count the HTML page hides would be a leak with a different Accept header.
     */
    public function availability(): string
    {
        return $this->product->stockLabel();
    }

    /** schema.org's own vocabulary for the JSON-LD `availability` property. */
    public function schemaAvailability(): string
    {
        return app(AvailabilityStatuses::class)->schemaAvailability((string) $this->product->availability_status);
    }

    public function brand(): ?string
    {
        foreach ($this->product->featureAssignments as $assignment) {
            if ($assignment->feature?->purpose === 'brand') {
                return $assignment->display();
            }
        }

        return null;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return $this->product->categories
            ->filter(fn ($category) => $category->isListed())
            ->map(fn ($category) => (string) $category->t('name'))
            ->filter()->values()->all();
    }

    public function description(): string
    {
        return Markdown::fromHtml($this->product->t('description'));
    }

    /**
     * The one line under the name — typed, or composed from the shop's
     * characteristics. A fact like any other: it is what the page itself says
     * about the product, so the text rendition and a meta description may both
     * lean on it rather than each inventing a sentence.
     */
    public function shortDescription(): string
    {
        return app(ShortDescription::class)->for($this->product);
    }

    /**
     * The description as prose — no marks, one paragraph.
     *
     * Structured data and a meta description are both read by something that
     * shows the text verbatim, so a `**` left in it is punctuation a search
     * result prints.
     */
    public function plainDescription(): string
    {
        $text = $this->description();

        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/^#{1,6}\s*/mu', '', $text) ?? $text;
        $text = preg_replace('/^\s*>\s?/mu', '', $text) ?? $text;
        $text = str_replace(['**', '~~', '`'], '', $text);
        $text = preg_replace('/(?<!\w)\*(?!\s)/u', '', $text) ?? $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A sentence about the product for a meta description, falling back to its
     * name and price when it has no description — an empty description is the
     * single commonest SEO fault in a real catalogue, and the shop knows enough
     * to say something rather than nothing.
     */
    public function summary(int $limit = 160): string
    {
        $text = $this->plainDescription();

        // The short description before the composed sentence: it is what the
        // shop chose to say, and "Name. Brand. 1 000 UAH" is the last resort.
        if ($text === '') {
            $text = $this->shortDescription();
        }

        if ($text === '') {
            $text = $this->composedSummary();
        }

        return Str::limit($text, $limit);
    }

    /**
     * The last resort, said as a sentence a person would write (audit
     * 2026-10-15: "Apple iPhone 17. 78990 USD" was what search results
     * showed): the name and brand, the first characteristics the page lists,
     * and the price written the way the currency writes it.
     */
    protected function composedSummary(): string
    {
        $head = $this->name().(($brand = $this->brand()) && ! str_contains(mb_strtolower($this->name()), mb_strtolower($brand)) ? ' — '.$brand : '');

        $specs = array_slice(array_map(
            fn (array $row) => $row['label'].': '.$row['value'],
            array_values(array_filter($this->specs(), fn (array $row) => $row['type'] === 'feature' && trim((string) ($row['value'] ?? '')) !== '')),
        ), 0, 3);

        $price = $this->price();
        $currency = app(\App\Core\Support\Currencies::class)->find($price['currency']);
        $written = $currency ? $currency->format((float) $price['amount']) : $price['amount'].' '.$price['currency'];

        return trim($head.($specs !== [] ? ': '.implode(', ', $specs) : '').'. '
            .($this->priceIsFrom() ? __('Price from :price.', ['price' => $written]) : __('Price :price.', ['price' => $written])));
    }

    /**
     * A generated conversion, never the archival original — the file a
     * photographer uploaded is measured in megabytes and nobody asking a
     * question about a laptop wants it. `alt` comes along because an image
     * with no alt tells a reader that cannot see it nothing at all.
     *
     * @return list<array{url: string, alt: string}>
     */
    public function images(int $minWidth = 800): array
    {
        $images = [];

        foreach ($this->product->galleryImages()->values() as $index => $media) {
            $images[] = [
                'url' => Gallery::thumbUrl($media, $minWidth),
                // altFor(), not alt(): the caption somebody typed, else
                // whatever an SEO template can say about it, else the name.
                'alt' => Gallery::altFor($media, $this->product, $index) ?: $this->name(),
            ];
        }

        if ($images === []) {
            foreach ($this->product->externalImageUrls() as $externalUrl) {
                $images[] = ['url' => $externalUrl, 'alt' => $this->name()];
            }
        }

        return $images;
    }

    /**
     * The characteristics table, in the order the product page shows it:
     * the global features first (named and translated), then the free-form
     * rows with their layout sections.
     *
     * @return list<array{type: 'section'|'feature', label: string, value?: string}>
     */
    public function specs(): array
    {
        $rows = [];

        foreach ($this->product->featureAssignments as $assignment) {
            // The same characteristics the page prints, and no others: a
            // characteristic the shop has taken out of the specifications must
            // not come back in the .md rendition or in the JSON-LD, which would
            // be a disclosure that differs from the HTML page only by an
            // Accept header.
            if ($assignment->feature?->shows('specs') && ($value = $assignment->display()) !== null) {
                $rows[] = ['type' => 'feature', 'label' => (string) $assignment->feature->t('name'), 'value' => $value];
            }
        }

        foreach ($this->product->featureRows() as $row) {
            if ($row['type'] === 'section') {
                $rows[] = ['type' => 'section', 'label' => (string) $row['label']];

                continue;
            }

            $rows[] = [
                'type' => 'feature',
                'label' => (string) ($row['label'] ?? $row['key']),
                'value' => is_scalar($row['value'])
                    ? (string) $row['value']
                    : (string) json_encode($row['value'], JSON_UNESCAPED_UNICODE),
            ];
        }

        return $rows;
    }

    /**
     * The other products this one is a variation of — the same question the
     * page's colour/size pickers answer, so a machine can walk to the variant
     * it was asked about instead of guessing from the name.
     *
     * @return list<array{name: string, url: string}>
     */
    public function variants(): array
    {
        $members = $this->product->variationMembers();

        return $members
            ->reject(fn ($member) => $member->id === $this->product->id)
            ->map(fn ($member) => ['name' => (string) $member->t('name'), 'url' => route('catalog.product', $member->slug)])
            ->values()->all();
    }

    /**
     * Minor units of the shop's books turned into the number a machine should
     * quote, with the ISO code beside it — never the symbol: "1 200 ₴" is one
     * string a program has to parse, and two shops write it differently.
     *
     * @return array{minor: int, amount: string, currency: string}
     */
    protected function money(int $minor): array
    {
        $code = current_storefront()?->default_currency ?? shop_currency();
        $currency = app(Currencies::class)->find($code);

        $amount = $currency
            ? number_format(($minor / 100) * (float) $currency->rate, $currency->decimals, '.', '')
            : number_format($minor / 100, 2, '.', '');

        return ['minor' => $minor, 'amount' => $amount, 'currency' => $code];
    }
}
