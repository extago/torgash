<?php

namespace Tests\Feature\Search;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Search\Engine;
use Torgash\Search\Models\Exclusion;
use Torgash\Search\Models\Phrase;
use Torgash\Search\Models\Synonym;
use Torgash\Search\Rules;

/**
 * Where the shop steers its search by hand (PLAN §34, step 4): synonyms,
 * exclusions, pinned phrases — and the screens they are written on.
 */
class SearchRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('m_search_documents')->delete();
        DB::table('m_search_words')->delete();
    }

    public function test_a_synonym_finds_what_the_catalogue_calls_it_as_well(): void
    {
        $phone = $this->product('RUL-1', 'Rulephone Orbit');
        $slang = $this->product('RUL-2', 'Mobila sticker');
        Synonym::create(['phrases' => ['mobila'], 'target' => 'rulephone', 'mode' => 'also']);

        $ids = $this->search('mobila');

        // What was typed counts a little more than the rewrite.
        $this->assertSame([$slang->id, $phone->id], $ids);
    }

    public function test_an_instead_synonym_does_not_search_what_was_typed(): void
    {
        $phone = $this->product('RUL-3', 'Rulephone Orbit');
        $this->product('RUL-4', 'Mobila sticker');
        Synonym::create(['phrases' => ['mobila'], 'target' => 'rulephone', 'mode' => 'instead']);

        $this->assertSame([$phone->id], $this->search('mobila'));
    }

    /** Whole words, in sequence, anywhere in the query. */
    public function test_a_synonym_rewrites_only_its_words(): void
    {
        $orbit = $this->product('RUL-5', 'Rulephone Orbit');
        $this->product('RUL-6', 'Rulephone Nova');
        Synonym::create(['phrases' => ['mobila'], 'target' => 'rulephone', 'mode' => 'instead']);

        $this->assertSame([$orbit->id], $this->search('mobila orbit'));
        $this->assertSame([], $this->search('mobilas'), 'a longer word is a different word');
    }

    /** "мобила" is the shop's word by rule — the speller must not "correct" it. */
    public function test_a_synonym_word_is_never_corrected(): void
    {
        $this->product('RUL-7', 'Mobile charger');
        Synonym::create(['phrases' => ['mobila'], 'target' => 'charger', 'mode' => 'instead']);

        $result = app(Engine::class)->search('mobila');

        $this->assertNull($result->how);
        $this->assertNull($result->didYouMean);
    }

    public function test_an_exclusion_by_a_word_of_the_name(): void
    {
        $case = $this->product('RUL-8', 'Rulecase for Orbit');
        $this->product('RUL-9', 'Orbit rulephone');
        Exclusion::create(['when_words' => ['rulecas'], 'exclude_words' => ['rulephone']]);

        $this->assertCount(2, $this->search('orbit'), 'the rule is about queries holding the trigger');
        $this->assertSame([$case->id], $this->search('rulecase orbit'));
    }

    /** A category is its subcategories; the surer target — a case "for a phone" names phones. */
    public function test_an_exclusion_by_category_takes_everything_under_it(): void
    {
        $phones = Category::create(['name' => ['en' => 'Rule phones'], 'slug' => 'rule-phones', 'enabled' => true]);
        $flagship = Category::create(['name' => ['en' => 'Rule flagships'], 'slug' => 'rule-flagships', 'enabled' => true, 'parent_id' => $phones->id]);
        $case = $this->product('RUL-10', 'Rulecase for rulephone');
        $phone = $this->product('RUL-11', 'Rulephone with rulecase in the box');
        $phone->categories()->attach($flagship->id);
        Exclusion::create(['when_words' => ['rulecase'], 'exclude_category_id' => $phones->id]);

        $this->assertSame([$case->id], $this->search('rulecase'));
    }

    public function test_a_phrase_puts_its_products_first_in_its_order_even_unmentioned(): void
    {
        $gift = $this->product('RUL-12', 'Gift for mum');
        $kettle = $this->product('RUL-13', 'Plain kettle');
        $hidden = $this->product('RUL-14', 'Hidden thing', ['status' => 'disabled']);
        Phrase::create(['phrase' => 'gift for mum', 'product_ids' => [$kettle->id, $hidden->id]]);

        $this->assertSame([$kettle->id, $gift->id], $this->search('Gift for mum'), 'only what the visitor may be shown');
        $this->assertSame([$gift->id], $this->search('gift'), 'only when it IS the query');
    }

    public function test_phrases_are_offered_in_the_window_by_priority(): void
    {
        Phrase::create(['phrase' => 'gift for mum', 'priority' => 1]);
        Phrase::create(['phrase' => 'gift for dad', 'priority' => 5]);
        Phrase::create(['phrase' => 'birthday gift', 'priority' => 3, 'enabled' => false]);

        $html = $this->get('http://localhost/search/suggest?q=gif')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'for mum'), strpos($html, 'for dad'));
        $this->assertStringNotContainsString('birthday', $html);
        $this->assertStringContainsString(e(route('catalog.search', ['q' => 'gift for dad', 'scope' => 'catalog'])), $html);
    }

    public function test_the_screens_write_rules_in_the_words_a_query_is_made_of(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/search-engine/rules/synonyms')->assertOk();
        $this->actingAs($admin)->post('http://localhost/admin/search-engine/rules/synonyms', [
            'phrases' => "Мобила\n  НОУТ , мобилка", 'target' => 'Смартфон!', 'mode' => 'also', 'enabled' => '1',
        ])->assertRedirect();

        $synonym = Synonym::latest('id')->first();
        $this->assertSame(['мобила', 'ноут', 'мобилка'], $synonym->phrases);
        $this->assertSame('смартфон', $synonym->target);

        $this->actingAs($admin)->post('http://localhost/admin/search-engine/rules/exclusions', ['when_words' => 'чехл'])
            ->assertSessionHasErrors('exclude_words');

        $product = $this->product('RUL-15', 'Plain kettle');
        $this->actingAs($admin)->post('http://localhost/admin/search-engine/rules/phrases', [
            'phrase' => 'Подарок  маме', 'priority' => 3, 'product_ids' => [$product->id], 'enabled' => '1',
        ])->assertRedirect();
        $this->assertSame('подарок маме', Phrase::latest('id')->first()->phrase);
        $this->actingAs($admin)->post('http://localhost/admin/search-engine/rules/phrases', ['phrase' => 'подарок маме'])
            ->assertSessionHasErrors('phrase');

        $this->actingAs($admin)->get('http://localhost/admin/search-engine/rules/phrases')->assertOk()->assertSee('подарок маме');
        $this->actingAs($admin)->delete('http://localhost/admin/search-engine/rules/synonyms/'.$synonym->id)->assertRedirect();
        $this->assertNull(Synonym::find($synonym->id));

        $this->actingAs(User::factory()->create())->get('http://localhost/admin/search-engine/rules/synonyms')->assertForbidden();
        $this->actingAs($admin)->get('http://localhost/admin/search-engine/rules/nonsense')->assertNotFound();
    }

    /** The trial on the engine screen says which rule shaped the answer. */
    public function test_the_trial_names_the_rules_that_fired(): void
    {
        $this->product('RUL-16', 'Rulephone Orbit');
        Synonym::create(['phrases' => ['mobila'], 'target' => 'rulephone', 'mode' => 'instead']);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/search-engine?q=mobila')->assertOk()
            ->assertSee('Synonym: «mobila» → «rulephone»');
    }

    /** @return list<int> */
    protected function search(string $q): array
    {
        app()->forgetInstance(Rules::class);

        return app(Engine::class)->search($q)->ids;
    }

    /** @param  array<string, mixed>  $extra */
    protected function product(string $sku, string $name, array $extra = []): Product
    {
        return Product::create($extra + [
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 10, 'status' => 'active',
        ]);
    }
}
