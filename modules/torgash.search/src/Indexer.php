<?php

namespace Torgash\Search;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;

/**
 * Builds the searchable document of a product and writes it.
 *
 * Four weights, the order a person would rank a match in:
 *   A — the name (in every language it has), the SKU, the brand;
 *   B — characteristics the shop marked "search", and the free-form ones;
 *   C — the short description and the page title;
 *   D — the description, if the shop searches descriptions at all.
 *
 * Every piece is written twice: as it stands (`simple` — so the START of a
 * word finds it: "ноут" → "ноутбук") and, where Postgres has a stemmer for its
 * language, reduced to stems ("ноутбуки" and "ноутбук" are one word). A
 * translation is stemmed by its OWN language: the Russian name through
 * `russian`, the English one through `english`.
 *
 * Every word of it also goes into the catalogue's dictionary (`Dictionary`),
 * which is what a typo is corrected to: the words of weights A and B as words
 * that NAME products, those of C and D as words that are merely written about
 * them.
 *
 * Called on every product save — one product is a handful of milliseconds —
 * and in chunks for the whole catalogue by `Jobs\Reindex` / `search:reindex`.
 */
class Indexer
{
    public const CHUNK = 200;

    /** Inside `all()`: the dictionary is recounted once at the end, not moved per product. */
    protected bool $recounting = false;

    public function __construct(protected Dictionary $dictionary) {}

    /** @param  iterable<int>|int  $productIds */
    public function products(iterable|int $productIds): int
    {
        $ids = array_values(array_unique(array_map('intval', is_int($productIds) ? [$productIds] : [...$productIds])));
        $written = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $products = Product::withTrashed()->whereIn('id', $chunk)->get();
            $features = $this->globalFeatures($chunk);

            foreach ($products as $product) {
                $this->write($product, $features[$product->id] ?? []);
                $written++;
            }
        }

        return $written;
    }

    /**
     * The whole catalogue, in chunks. `$onChunk` hears how many are done.
     *
     * @param  ?callable(int): void  $onChunk
     */
    public function all(?callable $onChunk = null): int
    {
        $done = 0;
        $this->recounting = true;

        try {
            Product::withTrashed()->select('id')->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use (&$done, $onChunk) {
                $done += $this->products($chunk->pluck('id')->all());

                if ($onChunk) {
                    $onChunk($done);
                }
            });
        } finally {
            $this->recounting = false;
        }

        $this->dictionary->rebuild();

        return $done;
    }

    public function count(): int
    {
        return (int) DB::table('m_search_documents')->count();
    }

    /**
     * Products with no document at all — written past the model (a raw insert,
     * a restore from a dump, an import that bypassed events), so nothing ever
     * marked them. The search screen shows the shortfall; the nightly run
     * (SearchServiceProvider::statistics) writes them.
     *
     * @return list<int>
     */
    public function missing(): array
    {
        return Product::withTrashed()
            ->whereNotExists(fn ($q) => $q->from('m_search_documents')->whereColumn('m_search_documents.product_id', 'm_catalog_products.id'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The pieces of one product's document: weight, language (or null when a
     * piece has none — a SKU, a number) and text.
     *
     * @param  list<array{purpose: string, searchable: bool, text: array<string, string>}>  $features
     * @return list<array{0: string, 1: ?string, 2: string}>
     */
    public function pieces(Product $product, array $features): array
    {
        $pieces = [];
        $add = function (string $weight, ?string $locale, mixed $text) use (&$pieces) {
            $text = trim(preg_replace('/\s+/u', ' ', self::separate(strip_tags((string) $text))));

            if ($text !== '') {
                $pieces[] = [$weight, $locale, $text];
            }
        };

        foreach ((array) $product->name as $locale => $name) {
            $add('A', (string) $locale, $name);
        }

        $add('A', null, $product->sku);
        // "AB-100" is also typed "ab100": the SKU run together is a word too.
        $add('A', null, preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $product->sku));

        foreach ($features as $feature) {
            $weight = $feature['purpose'] === 'brand' ? 'A' : ($feature['searchable'] ? 'B' : null);

            if ($weight === null) {
                continue;
            }

            foreach ($feature['text'] as $locale => $text) {
                $add($weight, $locale === '' ? null : $locale, $text);
            }
        }

        // The free-form bag ("color": "white") — a shop's own words about the
        // product, with no row to tick, so it is searched as a characteristic.
        foreach ((array) ($product->features ?? []) as $value) {
            if (is_scalar($value) && ! is_bool($value)) {
                $add('B', null, $value);
            }
        }

        foreach ((array) ($product->short_description ?? []) as $locale => $text) {
            $add('C', (string) $locale, $text);
        }

        foreach ((array) ($product->meta_title ?? []) as $locale => $text) {
            $add('C', (string) $locale, $text);
        }

        if (setting('torgash.search', 'search_descriptions')) {
            foreach ((array) ($product->description ?? []) as $locale => $text) {
                $add('D', (string) $locale, $text);
            }
        }

        return $pieces;
    }

    /**
     * A hyphen before a digit is a separator, not a minus sign. Postgres's
     * parser reads "AB-100" as `ab` and `-100` — a negative number — so the
     * SKU typed as "ab 100" or "100" never matched it (measured: the product
     * whose SKU it was went unfound while one merely mentioning it was).
     */
    public static function separate(string $text): string
    {
        return (string) preg_replace('/(?<=[\p{L}\p{N}])-(?=\p{N})/u', ' ', $text);
    }

    /** @param  list<array{purpose: string, searchable: bool, text: array<string, string>}>  $features */
    protected function write(Product $product, array $features): void
    {
        $pieces = $this->pieces($product, $features);
        $parts = [];
        $bindings = [];

        foreach ($pieces as [$weight, $locale, $text]) {
            $parts[] = "setweight(to_tsvector('simple', ?), '{$weight}')";
            $bindings[] = $text;

            if ($locale !== null && ($stemmer = Languages::stemmer($locale))) {
                $parts[] = "setweight(to_tsvector('{$stemmer}', ?), '{$weight}')";
                $bindings[] = $text;
            }
        }

        $document = $parts === [] ? "''::tsvector" : implode(' || ', $parts);
        $title = mb_strtolower(implode(' · ', array_filter(array_map('strval', (array) $product->name))));
        $words = $this->wordsOf($pieces);
        $before = $this->recounting ? null : $this->wordsBefore($product->id);

        DB::statement(
            "insert into m_search_documents (product_id, document, title, sku, words, text_words, updated_at)
             values (?, {$document}, ?, ?, ?::jsonb, ?::jsonb, now())
             on conflict (product_id) do update set
                document = excluded.document, title = excluded.title, sku = excluded.sku,
                words = excluded.words, text_words = excluded.text_words, updated_at = excluded.updated_at",
            [
                $product->id, ...$bindings, $title, mb_strtolower((string) $product->sku),
                json_encode($words['words'], JSON_UNESCAPED_UNICODE), json_encode($words['text_words'], JSON_UNESCAPED_UNICODE),
            ],
        );

        if ($before !== null) {
            $this->dictionary->apply($before, $words);
        }
    }

    /**
     * The dictionary's share of a document: words that name the product
     * (weights A and B) and words only written about it (C and D) — a word in
     * both is the first kind.
     *
     * @param  list<array{0: string, 1: ?string, 2: string}>  $pieces
     * @return array{words: list<string>, text_words: list<string>}
     */
    public function wordsOf(array $pieces): array
    {
        $naming = [];
        $text = [];

        foreach ($pieces as [$weight, , $piece]) {
            foreach (Dictionary::words($piece) as $word) {
                if ($weight === 'A' || $weight === 'B') {
                    $naming[$word] = true;
                } else {
                    $text[$word] = true;
                }
            }
        }

        return [
            'words' => array_map('strval', array_keys($naming)),
            'text_words' => array_map('strval', array_keys(array_diff_key($text, $naming))),
        ];
    }

    /** @return array{words: list<string>, text_words: list<string>} */
    protected function wordsBefore(int $productId): array
    {
        $row = DB::table('m_search_documents')->where('product_id', $productId)->first(['words', 'text_words']);

        return [
            'words' => $row ? array_map('strval', (array) json_decode((string) $row->words, true)) : [],
            'text_words' => $row ? array_map('strval', (array) json_decode((string) $row->text_words, true)) : [],
        ];
    }

    /** A product leaving for good takes its words out of the dictionary. */
    public function forget(int $productId): void
    {
        $this->dictionary->apply($this->wordsBefore($productId), ['words' => [], 'text_words' => []]);
    }

    /**
     * The catalogue's own characteristics of these products, with whether
     * each is a brand and whether the shop marked it for search, and its
     * value's text per language (a typed value has no language).
     *
     * @param  list<int>  $productIds
     * @return array<int, list<array{purpose: string, searchable: bool, text: array<string, string>}>>
     */
    protected function globalFeatures(array $productIds): array
    {
        $rows = DB::table('m_catalog_product_features as pf')
            ->join('m_catalog_features as f', 'f.id', '=', 'pf.feature_id')
            ->leftJoin('m_catalog_feature_values as fv', 'fv.id', '=', 'pf.feature_value_id')
            ->whereIn('pf.product_id', $productIds)
            ->get(['pf.product_id', 'f.purpose', 'f.shown_in', 'fv.value', 'pf.value_text', 'pf.value_translations']);

        $byProduct = [];

        foreach ($rows as $row) {
            $shownIn = json_decode((string) $row->shown_in, true);
            // A typed value: the base word for every language, plus its own
            // word in each language that has one.
            $text = $row->value !== null
                ? (array) json_decode($row->value, true)
                : ['' => (string) $row->value_text] + (array) json_decode((string) $row->value_translations, true);

            $byProduct[(int) $row->product_id][] = [
                'purpose' => (string) $row->purpose,
                'searchable' => is_array($shownIn) && in_array('search', $shownIn, true),
                'text' => array_filter(array_map('strval', $text), fn ($t) => trim($t) !== ''),
            ];
        }

        return $byProduct;
    }
}
