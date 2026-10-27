<?php

namespace Tests\Feature\Search;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Search\Indexer;

/**
 * A product that reached the table past its model's events has no search
 * document — five demo products were unfindable this way. The nightly
 * `search:index-missing` finds and writes them.
 */
class MissingDocumentsTest extends TestCase
{
    public function test_a_product_inserted_past_the_model_is_found_missing_and_then_written(): void
    {
        $id = DB::table('m_catalog_products')->insertGetId([
            'sku' => 'RAW-1', 'name' => json_encode(['en' => 'Raw insert router']), 'slug' => 'raw-1',
            'price' => 10_00, 'stock' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $indexer = app(Indexer::class);
        $this->assertContains($id, $indexer->missing());

        $indexer->products($indexer->missing());

        $this->assertNotContains($id, $indexer->missing());
        $this->assertTrue(DB::table('m_search_documents')->where('product_id', $id)->exists());
    }
}
