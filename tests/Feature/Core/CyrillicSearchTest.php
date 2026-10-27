<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * The shop's base languages are English and Russian, so search has to work in
 * both. It did not: the database had been created with LC_CTYPE=C, under which
 * Postgres does not consider Cyrillic to be letters at all.
 *
 * Nothing in the application code was wrong — the queries are the same ones
 * that work perfectly in English — which is exactly why this is pinned here:
 * the defect lives in how the database was created, so only a test that types
 * Russian at it can see it. It is checked at two levels: the primitives, so a
 * failure says WHAT is broken, and the real search, so it says what it costs.
 */
class CyrillicSearchTest extends TestCase
{
    public function test_the_database_folds_cyrillic_case(): void
    {
        $row = DB::selectOne("select lower('ШЕВЧЕНКОВЕ') as lowered, ('Ноутбук' ilike '%ноутбук%') as folded");

        $this->assertSame('шевченкове', $row->lowered, 'lower() left Cyrillic alone — the database locale is C');
        $this->assertTrue((bool) $row->folded, 'ILIKE did not fold Cyrillic case');
    }

    public function test_trigram_search_sees_cyrillic_as_letters(): void
    {
        // Under LC_CTYPE=C this returns an empty array, which silently turns
        // every typo-tolerant fallback into "no results" for Russian.
        $row = DB::selectOne("select cardinality(show_trgm('Ноутбук')) as trigrams, similarity('Ноутбук','ноутбук') as sim");

        $this->assertGreaterThan(0, $row->trigrams, 'pg_trgm produced no trigrams for a Russian word');
        $this->assertEqualsWithDelta(1.0, (float) $row->sim, 0.001, 'the same word in another case scored as different');
    }

    public function test_full_text_search_lowercases_cyrillic(): void
    {
        $row = DB::selectOne("select to_tsvector('simple','Ноутбук') @@ websearch_to_tsquery('simple','ноутбук') as matched");

        $this->assertTrue((bool) $row->matched, 'a capitalised Russian word did not match its lowercase query');
    }

    public function test_a_russian_product_is_found_whatever_case_the_customer_types(): void
    {
        // This test is about the RESULTS page; with one product found the
        // search module would open the product instead (redirect_single).
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.search', 'redirect_single', false);

        Product::create([
            'sku' => 'RU-1',
            'name' => ['en' => 'Laptop Pro 14', 'ru' => 'Ноутбук Про 14'],
            'slug' => 'russian-laptop',
            'price' => 100_00,
            'stock' => 5,
            'status' => 'active',
        ]);

        // Asserted on the SKU, not the name: this storefront renders in
        // English, so the card would show the English title even when the
        // Russian query is what found it.
        foreach (['ноутбук', 'Ноутбук', 'НОУТБУК'] as $query) {
            $this->get('http://localhost/search?q='.urlencode($query))
                ->assertOk()
                ->assertSee('RU-1', false);
        }

        $this->get('http://localhost/ru/search?q='.urlencode('ноутбук'))
            ->assertOk()
            ->assertSee('Ноутбук Про 14', false);
    }
}
