<?php

namespace Torgash\Catalog\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;

/**
 * The one line under a product's name: what a person wrote, or the shop's
 * characteristics standing in for it (PLAN §30.10).
 *
 * The case this exists for is the same one Measurements exists for — a
 * catalogue of thousands nobody is going to write blurbs for. What the shop DOES
 * have is characteristics, and "16 ГБ · 15.6″ · SSD 512 ГБ" under the name is
 * worth more to a buyer than a blank line and more to a search engine than a
 * page whose only text is a table.
 *
 * Three decisions, each with a reason:
 *
 *   - the MODE is the same vocabulary as an SEO template's (`fill_empty` /
 *     `override` / `off`), because it is the same question asked of the same
 *     kind of shop, and two vocabularies for "does what is typed win" is how a
 *     screen stops being readable;
 *   - WHICH characteristics compose it is a flag on the characteristic
 *     (`in_short_description`), not a list of codes in a setting — see the
 *     migration;
 *   - the SEPARATOR differs between the product page and a card in a grid,
 *     which is the one thing the usual add-on got exactly right: a card has two
 *     lines and a page has a paragraph.
 *
 * Only GLOBAL characteristics compose it: they are the ones with a row to carry
 * the flag, a name to print, and a code. A free-form JSONB bag has no row to
 * tick, and inventing "every key in the bag" would put the warranty period in
 * the first line of a laptop's page.
 *
 * Request-scoped singleton with a prime(): a grid asks this once per card, and
 * the assignments behind it are one row per product — exactly the shape that
 * turns a page of 12 products into 12 queries if nobody batches it.
 */
class ShortDescription
{
    public const PLACE_PAGE = 'page';

    public const PLACE_CARD = 'card';

    /** @var list<int>|null ids of the characteristics that compose it, once per request */
    protected ?array $featureIds = null;

    /** @var array<int, list<array{name: string, value: string}>> product id => its composing pairs */
    protected array $primed = [];

    /**
     * The language $primed is in. The pairs are words, so a memo kept for one
     * language must not answer another — the ru page and the en page of one
     * application (a test, a persistent worker) would otherwise share them.
     */
    protected ?string $primedLocale = null;

    /**
     * What to print, or '' when there is nothing to say.
     *
     * `$place` only changes the separator and how many pairs are used — never
     * WHICH text answers, so a card and the page it links to never disagree
     * about what the product is.
     */
    public function for(Product $product, string $place = self::PLACE_PAGE): string
    {
        $mode = $this->mode();

        if ($mode === 'off') {
            return (string) ($product->t('short_description') ?? '');
        }

        $written = trim((string) ($product->t('short_description') ?? ''));

        if ($written !== '' && $mode === 'fill_empty') {
            return $written;
        }

        $composed = $this->compose($product, $place);

        // `override` still falls back to what was typed: composing is what the
        // setting asks for, and an empty line is not an improvement on a
        // sentence somebody wrote.
        return $composed !== '' ? $composed : $written;
    }

    /**
     * Announce a grid's products before asking about them one at a time.
     *
     * @param  iterable<Product>  $products
     */
    public function prime(iterable $products): void
    {
        $features = $this->features();

        if ($features === []) {
            return;
        }

        $this->forgetOtherLocale();

        $ids = [];
        foreach ($products as $product) {
            if (! array_key_exists($product->id, $this->primed)) {
                $ids[] = $product->id;
            }
        }

        if ($ids === []) {
            return;
        }

        $locale = app()->getLocale();
        $fallback = content_base_locale();

        // The pair as it will be printed, in the shop's own order of
        // characteristics — a typed value sits in value_text (its other
        // languages in value_translations), a picked one in the value
        // catalogue, and both are translatable with the base locale as the
        // fallback, which is what `t()` would have done one row at a time.
        $rows = DB::table('m_catalog_product_features as pf')
            ->join('m_catalog_features as f', 'f.id', '=', 'pf.feature_id')
            ->leftJoin('m_catalog_feature_values as fv', 'fv.id', '=', 'pf.feature_value_id')
            ->whereIn('pf.feature_id', $features)
            ->whereIn('pf.product_id', $ids)
            ->orderBy('f.sort')
            ->orderBy('f.id')
            ->selectRaw(
                'pf.product_id,
                 coalesce(f.name->>?, f.name->>?) as feature_name,
                 coalesce(pf.value_translations->>?, pf.value_text, fv.value->>?, fv.value->>?, fv.code) as raw_value',
                [$locale, $fallback, $locale, $locale, $fallback],
            )
            ->get();

        // Every id asked for is recorded, empty ones included: a second pass
        // must not re-query for products that simply have no such values.
        foreach ($ids as $id) {
            $this->primed[$id] = [];
        }

        foreach ($rows as $row) {
            if (trim((string) $row->raw_value) === '') {
                continue;
            }

            $this->primed[$row->product_id][] = [
                'name' => (string) $row->feature_name,
                'value' => (string) $row->raw_value,
            ];
        }
    }

    /**
     * The pairs a product's line is made of — for anything that wants them as
     * data rather than as a sentence (the API, an export, a template).
     *
     * @return list<array{name: string, value: string}>
     */
    public function pairs(Product $product): array
    {
        $this->forgetOtherLocale();

        if (! array_key_exists($product->id, $this->primed)) {
            // Not primed: answer correctly rather than not at all, and let the
            // query budget test be what complains about the caller.
            $this->prime([$product]);
        }

        return $this->primed[$product->id] ?? [];
    }

    /** Whether anything is composed at all — a screen asks before drawing a card. */
    public function composes(): bool
    {
        return $this->mode() !== 'off' && $this->features() !== [];
    }

    /**
     * What the characteristics alone would say — regardless of the mode and of
     * what is typed. The product form shows it as the field's placeholder, so
     * that leaving the box empty reads as a decision rather than as an omission.
     */
    public function composedFor(Product $product, string $place = self::PLACE_PAGE): string
    {
        return $this->features() === [] ? '' : $this->compose($product, $place);
    }

    protected function compose(Product $product, string $place): string
    {
        $pairs = $this->pairs($product);

        if ($pairs === []) {
            return '';
        }

        if ($place === self::PLACE_CARD) {
            $limit = (int) setting('torgash.catalog', 'short_description_card_limit', 3);
            if ($limit > 0) {
                $pairs = array_slice($pairs, 0, $limit);
            }
        }

        $separator = (string) setting(
            'torgash.catalog',
            $place === self::PLACE_CARD ? 'short_description_card_separator' : 'short_description_separator',
            $place === self::PLACE_CARD ? ' · ' : ', ',
        );

        $withNames = (bool) setting('torgash.catalog', 'short_description_names', true)
            && $place !== self::PLACE_CARD;

        return collect($pairs)
            ->map(fn (array $pair) => $withNames ? $pair['name'].': '.$pair['value'] : $pair['value'])
            ->implode($separator);
    }

    protected function mode(): string
    {
        $mode = (string) setting('torgash.catalog', 'short_description_mode', 'fill_empty');

        return in_array($mode, ['off', 'fill_empty', 'override'], true) ? $mode : 'off';
    }

    /** @return list<int> */
    protected function features(): array
    {
        if ($this->featureIds !== null) {
            return $this->featureIds;
        }

        if ($this->mode() === 'off') {
            return $this->featureIds = [];
        }

        return $this->featureIds = Feature::query()
            ->where('in_short_description', true)
            ->orderBy('sort')->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function forgetOtherLocale(): void
    {
        if ($this->primedLocale !== app()->getLocale()) {
            $this->primed = [];
            $this->primedLocale = app()->getLocale();
        }
    }

    /** For tests, which change settings between assertions inside one request. */
    public function flush(): void
    {
        $this->featureIds = null;
        $this->primed = [];
    }

    /**
     * The whole grid's lines in one query — what the card partial calls, so a
     * page of products costs the same as a page of one.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, string> product id => its line
     */
    public function forAll(Collection $products, string $place = self::PLACE_CARD): array
    {
        $this->prime($products);

        return $products->mapWithKeys(fn (Product $p) => [$p->id => $this->for($p, $place)])->all();
    }
}
