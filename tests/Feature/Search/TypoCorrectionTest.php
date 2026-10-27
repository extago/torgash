<?php

namespace Tests\Feature\Search;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Search\Engine;
use Torgash\Search\Pending;
use Torgash\Search\Speller;

/**
 * The catalogue's own dictionary and what it corrects (PLAN §34, step 3): a
 * typo is corrected to a word the shop's products are written in, by edit
 * distance rather than by resemblance — so a false friend is not a typo —
 * and a correction that would find more is offered, not substituted.
 */
class TypoCorrectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('m_search_documents')->delete();
        DB::table('m_search_words')->delete();
    }

    public function test_the_dictionary_holds_the_words_of_names_and_texts_and_follows_edits(): void
    {
        $product = $this->product('DICT-1', ['ru' => 'Ноутбук Зенит'], ['short_description' => ['ru' => 'Лёгкий и тихий']]);

        $this->assertSame(['products' => 1, 'mentions' => 0], $this->counts('ноутбук'));
        $this->assertSame(['products' => 0, 'mentions' => 1], $this->counts('тихий'));
        $this->assertSame(['products' => 1, 'mentions' => 0], $this->counts('dict1'), 'the SKU run together');

        $product->update(['name' => ['ru' => 'Нетбук Зенит']]);

        $this->assertNull($this->counts('ноутбук'), 'a word no product carries any more leaves');
        $this->assertSame(['products' => 1, 'mentions' => 0], $this->counts('нетбук'));

        $product->forceDelete();

        $this->assertNull($this->counts('зенит'));
    }

    public function test_a_full_rebuild_recounts_from_the_documents(): void
    {
        $this->product('DICT-2', ['en' => 'Walnut desk']);
        $this->product('DICT-3', ['en' => 'Walnut shelf']);
        DB::table('m_search_words')->update(['products' => 99]);

        $this->artisan('search:reindex')->assertSuccessful();

        $this->assertSame(['products' => 2, 'mentions' => 0], $this->counts('walnut'));
    }

    public function test_a_misspelt_word_is_corrected_and_the_page_says_so(): void
    {
        $laptop = $this->product('DICT-4', ['ru' => 'Ноутбук Зенит']);

        $result = app(Engine::class)->search('ноутбк');

        $this->assertSame([$laptop->id], $result->ids);
        $this->assertSame('typo', $result->how);
        $this->assertSame('ноутбук', $result->readAs);

        $this->get('http://localhost/search?q='.urlencode('ноутбк'))->assertOk()
            ->assertSee('Ноутбук Зенит')
            ->assertSee('«ноутбук»', false);
    }

    /** Two letters swapped is one edit; a word still being typed is compared with the START of one. */
    public function test_a_swap_and_an_unfinished_word_are_corrected(): void
    {
        $this->product('DICT-5', ['ru' => 'Адаптер питания']);

        $this->assertSame('адаптер', app(Speller::class)->correct(new \Torgash\Search\SearchQuery('адапетр')));
        $this->assertSame('адаптер', app(Speller::class)->correct(new \Torgash\Search\SearchQuery('адпт')));
    }

    /** Resemblance is not a typo: "айфон" looks like "смартфон" to trigrams and is five edits from it. */
    public function test_a_false_friend_is_not_corrected(): void
    {
        $this->product('DICT-6', ['ru' => 'Смартфон Орбита']);

        $result = app(Engine::class)->search('айфон');

        $this->assertSame([], $result->ids);
        $this->assertNull($result->how);
    }

    /** Numbers are models, not typos; a short word is too short to guess at. */
    public function test_numbers_and_short_words_are_left_alone(): void
    {
        $this->product('DICT-7', ['en' => 'Router 2001 kit']);
        $speller = app(Speller::class);

        $this->assertNull($speller->correct(new \Torgash\Search\SearchQuery('2002')));
        $this->assertNull($speller->correct(new \Torgash\Search\SearchQuery('kot')));
    }

    /** A known word is never "corrected" into another one, whatever else in the query is wrong. */
    public function test_only_unknown_words_are_corrected(): void
    {
        $this->product('DICT-8', ['en' => 'Walnut desk']);
        $this->product('DICT-9', ['en' => 'Walnut chair']);

        $this->assertSame('walnut desk', app(Speller::class)->correct(new \Torgash\Search\SearchQuery('walnut dessk')));
        $this->assertNull(app(Speller::class)->correct(new \Torgash\Search\SearchQuery('walnut chair')));
    }

    /**
     * Found as typed — the case mentions the misspelling in its description —
     * but the corrected query finds more: offered as "did you mean", never
     * substituted, and a single result is then not opened as the answer.
     */
    public function test_a_correction_that_finds_more_is_offered_not_applied(): void
    {
        $case = $this->product('DICT-10', ['en' => 'Phone case'], ['description' => ['en' => 'Fits every smarphone']]);
        $this->product('DICT-11', ['en' => 'Smartphone Orbit']);
        $this->product('DICT-12', ['en' => 'Smartphone Nova']);

        $result = app(Engine::class)->search('smarphone');

        $this->assertSame([$case->id], $result->ids);
        $this->assertNull($result->how);
        $this->assertSame('smartphone', $result->didYouMean);

        $this->get('http://localhost/search?q=smarphone')->assertOk()
            ->assertSee('Did you mean')
            ->assertSee('smartphone');
        $this->get('http://localhost/search/suggest?q=smarphone')->assertOk()
            ->assertSee('Did you mean')
            ->assertSee(route('catalog.search', ['q' => 'smartphone']), false);
    }

    /** A correction finding the same products is not a better question. */
    public function test_a_correction_that_finds_nothing_more_is_not_offered(): void
    {
        $this->product('DICT-13', ['en' => 'Smartphone Orbit'], ['description' => ['en' => 'Better than any smarphone']]);

        $this->assertNull(app(Engine::class)->search('smarphone')->didYouMean);
    }

    /** @return array{products: int, mentions: int}|null */
    protected function counts(string $word): ?array
    {
        $row = DB::table('m_search_words')->where('word', $word)->first();

        return $row ? ['products' => (int) $row->products, 'mentions' => (int) $row->mentions] : null;
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, mixed>  $extra
     */
    protected function product(string $sku, array $name, array $extra = []): Product
    {
        $product = Product::create($extra + [
            'sku' => $sku,
            'name' => $name,
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 10,
            'status' => 'active',
        ]);
        app(Pending::class)->flush();

        return $product;
    }
}
