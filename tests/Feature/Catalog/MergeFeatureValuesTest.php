<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Torgash\Catalog\Features\MergeValues;
use Torgash\Catalog\Features\Spelling;
use Torgash\Catalog\Filters\FilterQuery;
use Torgash\Catalog\Invariants\CatalogInvariants;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\PriceType;
use Torgash\Catalog\Models\PriceTypeMarkupRule;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Collections\Models\Collection;
use Torgash\CommerceMl\Models\Link;
use Torgash\Exim\ImportRunner;
use Torgash\Promotions\Models\Promotion;
use Torgash\Promotions\Models\PromotionTier;

/**
 * Values of one characteristic merged into one (PLAN §60).
 *
 * The first live shop had "тёмно‑синий" (a ё, a NON-BREAKING hyphen) beside
 * "темно-синий", each with its own products, filter tick and filter page —
 * and the only way to make them one was SQL by hand. Everything that holds a
 * value's id has to follow a merge, or a half-merged catalogue points at rows
 * that are gone; and the merged spelling has to be remembered, or the next
 * supplier file brings it straight back.
 */
class MergeFeatureValuesTest extends TestCase
{
    protected Category $category;

    protected Feature $colour;

    /** "тёмно‑синий" — the value that stays. */
    protected FeatureValue $navy;

    /** "темно-синий", with an English name the main value lacks. */
    protected FeatureValue $plain;

    /** "ТЁМНО—СИНИЙ", an em dash and capitals. */
    protected FeatureValue $shouted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => ['en' => 'Cases'], 'slug' => 'merge-cases', 'enabled' => true]);
        $this->colour = Feature::create([
            'name' => ['en' => 'Case colour merge'], 'code' => 'merge_colour', 'purpose' => 'filter', 'input_type' => 'select',
        ]);
        $this->navy = FeatureValue::create(['feature_id' => $this->colour->id, 'value' => ['ru' => "тёмно\u{2011}синий"], 'code' => 'NAVY']);
        $this->plain = FeatureValue::create(['feature_id' => $this->colour->id, 'value' => ['ru' => 'темно-синий', 'en' => 'Dark blue'], 'code' => 'TEMNO-SINIY']);
        $this->shouted = FeatureValue::create(['feature_id' => $this->colour->id, 'value' => ['ru' => "ТЁМНО\u{2014}СИНИЙ"], 'code' => 'SHOUTED']);

        app(FilterQuery::class)->flush();
    }

    public function test_case_yo_dashes_spaces_and_quotes_are_one_spelling_and_nothing_else_is(): void
    {
        $same = ["тёмно\u{2011}синий", 'темно-синий', "  ТЁМНО\u{2013}СИНИЙ ", "темно\u{00AD}-синий", "е\u{0308}лка"];
        $this->assertSame(['темно-синий'], array_values(array_unique(array_map([Spelling::class, 'key'], array_slice($same, 0, 4)))));
        $this->assertSame('елка', Spelling::key($same[4]), 'a decomposed ё is ё');
        $this->assertSame(Spelling::key('6.7"'), Spelling::key("6.7\u{2033}"));
        $this->assertSame(Spelling::key("Levi's"), Spelling::key("Levi\u{2019}s"));
        $this->assertSame(Spelling::key('Wi-Fi 6'), Spelling::key("Wi\u{2011}Fi\u{00A0}\u{00A0}6"));

        // A person's decision, not a spelling.
        $this->assertNotSame(Spelling::key('золотой'), Spelling::key('золотистый'));
        $this->assertNotSame(Spelling::key('1,5 м'), Spelling::key('1.5 м'));
        $this->assertNotSame(Spelling::key('тёмно синий'), Spelling::key('тёмно-синий'));
    }

    /** The import asks the database, the screen asks PHP: one table of replacements makes both. */
    public function test_php_and_sql_compute_the_same_key(): void
    {
        [$sql, $bindings] = Spelling::sql('t.s');

        foreach (["тёмно\u{2011}синий", "  ТЁМНО\u{2014}СИНИЙ\t", "Levi\u{2019}s", "6.7\u{2033}", "«Ромашка»", "a\u{200B}b\u{FEFF}", "е\u{0308}ж", 'Dark   Blue', 'İ'] as $text) {
            $fromSql = DB::selectOne("select {$sql} as k from (select ?::text as s) t", [...$bindings, $text])->k;

            $this->assertSame(Spelling::key($text), $fromSql, json_encode($text));
        }
    }

    public function test_values_written_alike_are_one_group_and_the_page_says_so(): void
    {
        FeatureValue::create(['feature_id' => $this->colour->id, 'value' => ['ru' => 'чёрный'], 'code' => 'BLACK']);

        $groups = Spelling::groups($this->colour->values()->get());

        $this->assertCount(1, $groups);
        $this->assertEqualsCanonicalizing(
            [$this->navy->id, $this->plain->id, $this->shouted->id],
            array_map(fn (FeatureValue $value) => $value->id, $groups[0]),
        );

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/catalog/features/'.$this->colour->id.'/edit')->assertOk()
            ->assertSee('Written alike — each holds only its own products:')
            ->assertSee('id="modal-merge-values"', false)
            ->assertSee('action="http://localhost/admin/catalog/features/'.$this->colour->id.'/values/merge"', false);
    }

    public function test_merging_moves_the_products_and_keeps_the_spellings(): void
    {
        $a = $this->product('MRG-1', $this->navy);
        $b = $this->product('MRG-2', $this->plain);
        $c = $this->product('MRG-3', $this->shouted);

        $done = app(MergeValues::class)->merge($this->navy, [$this->plain, $this->shouted->id]);

        $this->assertSame(['values' => 2, 'products' => 2, 'filter_pages' => 0, 'labels' => 0, 'markup_rules' => 0], $done);
        foreach ([$a, $b, $c] as $product) {
            $this->assertSame($this->navy->id, (int) ProductFeature::where('product_id', $product->id)->value('feature_value_id'));
        }
        $this->assertFalse(FeatureValue::whereKey([$this->plain->id, $this->shouted->id])->exists());

        $navy = $this->navy->fresh();
        $this->assertEquals(['ru' => "тёмно\u{2011}синий", 'en' => 'Dark blue'], $navy->value, 'a language it had none in is taken, its own is kept');
        $this->assertSame('NAVY', $navy->code);
        $this->assertEqualsCanonicalizing(['TEMNO-SINIY', 'SHOUTED'], array_column($navy->aliases, 'code'));
        $this->assertSame(['темно-синий', "ТЁМНО\u{2014}СИНИЙ"], array_map(fn (array $alias) => $alias['names']['ru'], $navy->aliases));
    }

    public function test_the_import_finds_the_value_by_a_merged_or_differently_written_spelling(): void
    {
        $this->colour->update(['name' => ['en' => 'Merge test colour']]);
        $dusk = FeatureValue::create(['feature_id' => $this->colour->id, 'value' => ['ru' => 'сумеречный синий'], 'code' => 'DUSK']);
        app(MergeValues::class)->merge($this->navy, [$dusk]);
        $values = FeatureValue::where('feature_id', $this->colour->id)->count();

        $this->product('MRG-IMP-1', null);
        $this->product('MRG-IMP-2', null);
        $this->product('MRG-IMP-3', null);

        $path = tempnam(sys_get_temp_dir(), 'mrg').'.csv';
        file_put_contents($path, "Product code;Features\n"
            // A spelling merged into it, written in another case and spacing.
            ."MRG-IMP-1;Merge test colour: S[Сумеречный  синий]\n"
            // The value's own name with an en dash in place of its
            // non-breaking hyphen — nobody merged anything for this one.
            ."MRG-IMP-2;Merge test colour: S[ТЁМНО–СИНИЙ]\n"
            // An existing twin keeps what is spelled like it.
            ."MRG-IMP-3;Merge test colour: S[темно-синий]\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $runner = app(ImportRunner::class);
        $runner->commit('product', $runner->rowsOf($path, 'product'));

        foreach (['MRG-IMP-1' => $this->navy, 'MRG-IMP-2' => $this->navy, 'MRG-IMP-3' => $this->plain] as $sku => $value) {
            $this->assertSame($value->id, (int) ProductFeature::where('product_id', Product::where('sku', $sku)->value('id'))
                ->where('feature_id', $this->colour->id)->value('feature_value_id'), $sku);
        }
        $this->assertSame($values, FeatureValue::where('feature_id', $this->colour->id)->count(), 'no spelling became a value again');
    }

    /** A real value always beats another value's remembered spelling. */
    public function test_a_value_of_its_own_wins_over_a_merged_spelling(): void
    {
        $dusk = FeatureValue::create(['feature_id' => $this->colour->id, 'value' => ['ru' => 'сумеречный синий'], 'code' => 'DUSK']);
        app(MergeValues::class)->merge($this->navy, [$dusk]);
        $this->assertSame($this->navy->id, Spelling::find($this->colour->id, 'Сумеречный синий')?->id);

        $again = FeatureValue::create(['feature_id' => $this->colour->id, 'value' => ['ru' => 'Сумеречный синий'], 'code' => 'AGAIN']);

        $this->assertSame($again->id, Spelling::find($this->colour->id, 'сумеречный синий')?->id);
        $this->assertNull(Spelling::find($this->colour->id, 'голубой'));
    }

    public function test_an_old_filtered_address_finds_the_merged_value(): void
    {
        $this->product('MRG-URL-1', $this->plain);
        app(MergeValues::class)->merge($this->navy, [$this->plain]);
        app(FilterQuery::class)->flush();

        $this->get('http://localhost/catalog?merge_colour=temno-siniy')->assertOk()->assertSee('Case MRG-URL-1');
        $this->get('http://localhost/catalog?merge_colour=navy')->assertOk()->assertSee('Case MRG-URL-1');
        $this->get('http://localhost/catalog?merge_colour=nobody')->assertOk()->assertDontSee('Case MRG-URL-1');
    }

    public function test_filter_pages_follow_and_a_twin_leaves_a_redirect(): void
    {
        $this->product('MRG-FP-1', $this->navy);
        $this->product('MRG-FP-2', $this->plain);
        $other = Category::create(['name' => ['en' => 'Covers'], 'slug' => 'merge-covers', 'enabled' => true]);

        $navyPage = $this->filterPage($this->category, 'navy', [$this->navy->id]);
        $plainPage = $this->filterPage($this->category, 'plain', [$this->plain->id], ['description' => ['en' => 'Typed by hand']]);
        $elsewhere = $this->filterPage($other, 'shouted', [$this->shouted->id]);
        $both = $this->filterPage($other, 'any-blue', [$this->navy->id, $this->plain->id]);

        $done = app(MergeValues::class)->merge($this->navy, [$this->plain, $this->shouted]);

        $this->assertSame(3, $done['filter_pages'], 'the main value\'s own page is not one of them');
        $this->assertNull(FilterPage::find($plainPage->id), 'a twin of the main value\'s page goes');
        $this->assertSame('/catalog/merge-cases/navy', DB::table('redirects')->where('from_path', '/catalog/merge-cases/plain')->value('to_path'));

        $navyPage->refresh();
        $this->assertSame(['en' => 'Typed by hand'], $navyPage->description, 'empty texts are filled from the page that went');
        $this->assertSame(2, $navyPage->products_count);

        // In another category the page is about the main value now — and the
        // one that named both spellings names it once, which makes it the
        // first one's twin there.
        $this->assertSame([$this->colour->id => [$this->navy->id]], FilterPage::find($elsewhere->id)->valueSet());
        $this->assertNull(FilterPage::find($both->id));
        $this->assertSame('/catalog/merge-covers/shouted', DB::table('redirects')->where('from_path', '/catalog/merge-covers/any-blue')->value('to_path'));
        $this->assertSame(FilterPage::find($elsewhere->id)->fingerprint(), FilterPage::find($elsewhere->id)->fingerprint);
    }

    /** The shop's live address is the one to keep. */
    public function test_a_published_page_outlives_a_switched_off_twin(): void
    {
        $off = $this->filterPage($this->category, 'navy', [$this->navy->id], ['enabled' => false]);
        $live = $this->filterPage($this->category, 'plain', [$this->plain->id]);

        app(MergeValues::class)->merge($this->navy, [$this->plain]);

        $this->assertNull(FilterPage::find($off->id));
        $this->assertSame([$this->colour->id => [$this->navy->id]], FilterPage::find($live->id)->valueSet());
        $this->assertSame('/catalog/merge-cases/plain', DB::table('redirects')->where('from_path', '/catalog/merge-cases/navy')->value('to_path'));
    }

    public function test_labels_markup_rules_collections_promotions_and_1c_links_follow(): void
    {
        $label = Label::create(['name' => ['en' => 'Navy'], 'name_for_admin' => 'merge navy', 'conditions' => ['rules' => [
            ['field' => 'feature', 'operator' => 'eq', 'feature_id' => (string) $this->colour->id, 'value' => (string) $this->plain->id],
            ['field' => 'price', 'operator' => 'gte', 'value' => '10'],
        ]]]);
        $priceType = PriceType::create(['code' => 'MERGE-WHOLESALE', 'name' => ['en' => 'Merge wholesale']]);
        $rule = PriceTypeMarkupRule::create([
            'price_type_id' => $priceType->id, 'markup_type' => 'percent', 'markup_value' => 10, 'rounding_mode' => 'none', 'round_to' => 0,
        ]);
        $rule->featureValues()->sync([$this->navy->id, $this->plain->id]);
        $collection = Collection::create(['name' => ['en' => 'Blues'], 'slug' => 'merge-blues', 'conditions' => [
            'features' => [$this->colour->id => [$this->plain->id, $this->shouted->id]],
        ]]);
        $promotion = Promotion::create(['name' => ['en' => 'Navy week'], 'zone' => 'catalog']);
        $tier = PromotionTier::create(['promotion_id' => $promotion->id, 'conditions' => ['match' => 'all', 'rules' => [
            ['kind' => 'feature', 'operator' => 'feature_is', 'feature_id' => $this->colour->id, 'value' => $this->shouted->id],
        ]]]);
        $link = Link::create(['kind' => Link::KIND_FEATURE_VALUE, 'guid' => 'merge-guid#1', 'decision' => Link::LINK, 'local_id' => $this->plain->id]);

        $done = app(MergeValues::class)->merge($this->navy, [$this->plain, $this->shouted]);

        $this->assertSame(1, $done['labels']);
        $this->assertSame(1, $done['markup_rules']);
        $this->assertSame((string) $this->navy->id, $label->fresh()->conditions['rules'][0]['value']);
        $this->assertSame('10', $label->fresh()->conditions['rules'][1]['value'], 'other rules are untouched');
        $this->assertSame([$this->navy->id], $rule->featureValues()->pluck('m_catalog_feature_values.id')->all());
        $this->assertSame([$this->navy->id], $collection->fresh()->conditions['features'][$this->colour->id]);
        $this->assertSame($this->navy->id, $tier->fresh()->conditions['rules'][0]['value']);
        $this->assertSame($this->navy->id, (int) $link->fresh()->local_id);
    }

    public function test_a_brand_keeps_its_page_address_and_logo(): void
    {
        Storage::fake('public');
        $brand = Feature::create(['name' => ['en' => 'Merge make'], 'code' => 'merge_make', 'purpose' => 'brand', 'input_type' => 'select']);
        $kept = FeatureValue::create(['feature_id' => $brand->id, 'value' => ['en' => 'Quokka']]);
        $addressed = FeatureValue::create(['feature_id' => $brand->id, 'value' => ['en' => 'quokka'], 'slug' => 'merge-quokka', 'meta_title' => ['en' => 'Quokka phones']]);
        $addressed->addMedia(UploadedFile::fake()->image('logo.png', 12, 12))->toMediaCollection('logo');
        $alsoAddressed = FeatureValue::create(['feature_id' => $brand->id, 'value' => ['en' => 'QUOKKA'], 'slug' => 'merge-quokka-inc']);

        app(MergeValues::class)->merge($kept, [$addressed, $alsoAddressed]);

        $kept->refresh();
        $this->assertSame('merge-quokka', $kept->slug, 'the address with a history goes on being the page');
        $this->assertSame(['en' => 'Quokka phones'], $kept->meta_title);
        $this->assertNotNull($kept->getFirstMedia('logo'), 'the logo came with it');
        $this->assertSame('/brand/merge-quokka', DB::table('redirects')->where('from_path', '/brand/merge-quokka-inc')->value('to_path'));
    }

    public function test_merging_refuses_to_make_two_variations_one(): void
    {
        $shade = Feature::create(['name' => ['en' => 'Merge shade'], 'code' => 'merge_shade', 'purpose' => Feature::PURPOSE_VARIATION, 'input_type' => 'select']);
        $gold = FeatureValue::create(['feature_id' => $shade->id, 'value' => ['ru' => 'золотой']]);
        $golden = FeatureValue::create(['feature_id' => $shade->id, 'value' => ['ru' => 'золотистый']]);
        $one = $this->product('MRG-VAR-1', null, ['variation_group' => 'merge-phone']);
        $two = $this->product('MRG-VAR-2', null, ['variation_group' => 'merge-phone']);
        ProductFeature::create(['product_id' => $one->id, 'feature_id' => $shade->id, 'feature_value_id' => $gold->id]);
        ProductFeature::create(['product_id' => $two->id, 'feature_id' => $shade->id, 'feature_value_id' => $golden->id]);

        try {
            app(MergeValues::class)->merge($gold, [$golden]);
            $this->fail('two variations of one product were made the same');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('MRG-VAR-1', $e->errors()['merge'][0]);
            $this->assertStringContainsString('MRG-VAR-2', $e->errors()['merge'][0]);
        }

        $this->assertNotNull($golden->fresh(), 'nothing was changed');
        $this->assertSame($golden->id, (int) ProductFeature::where('product_id', $two->id)->value('feature_value_id'));

        // Told apart by another axis as well, they stay two variations.
        $memory = Feature::create(['name' => ['en' => 'Merge memory'], 'code' => 'merge_memory', 'purpose' => Feature::PURPOSE_VARIATION, 'input_type' => 'select']);
        ProductFeature::create(['product_id' => $one->id, 'feature_id' => $memory->id, 'feature_value_id' => FeatureValue::create(['feature_id' => $memory->id, 'value' => ['en' => '128 GB']])->id]);
        ProductFeature::create(['product_id' => $two->id, 'feature_id' => $memory->id, 'feature_value_id' => FeatureValue::create(['feature_id' => $memory->id, 'value' => ['en' => '256 GB']])->id]);

        app(MergeValues::class)->merge($gold, [$golden]);
        $this->assertSame($gold->id, (int) ProductFeature::where('product_id', $two->id)->where('feature_id', $shade->id)->value('feature_value_id'));
    }

    public function test_the_admin_merges_from_the_characteristic_page(): void
    {
        $this->product('MRG-ADM-1', $this->plain);
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->post('http://localhost/admin/catalog/features/'.$this->colour->id.'/values/merge', [
            'into' => $this->navy->id,
            'values' => [$this->plain->id, $this->shouted->id],
        ])->assertRedirect('http://localhost/admin/catalog/features/'.$this->colour->id.'/edit')
            ->assertSessionHas('status', fn (string $status) => str_contains($status, '2 values merged into') && str_contains($status, 'Products moved: 1'));

        $this->assertFalse(FeatureValue::whereKey($this->plain->id)->exists());

        // A value of another characteristic is not this page's to merge.
        $stranger = FeatureValue::create(['feature_id' => Feature::create(['name' => ['en' => 'Merge other'], 'purpose' => 'filter', 'input_type' => 'select'])->id, 'value' => ['en' => 'x']]);
        $this->actingAs($admin)->post('http://localhost/admin/catalog/features/'.$this->colour->id.'/values/merge', [
            'into' => $this->navy->id,
            'values' => [$stranger->id],
        ])->assertSessionHasErrors('merge');
        $this->assertNotNull($stranger->fresh());
    }

    public function test_a_new_value_written_like_another_is_refused_and_old_twins_still_save(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $rows = fn (array $extra = []) => json_encode([
            ['id' => $this->navy->id, 'value' => $this->navy->value, 'code' => 'NAVY', 'position' => 0],
            ['id' => $this->plain->id, 'value' => $this->plain->value, 'code' => 'TEMNO-SINIY', 'position' => 1],
            ...$extra,
        ]);
        $save = fn (string $json) => $this->actingAs($admin)->put('http://localhost/admin/catalog/features/'.$this->colour->id, [
            'name' => 'Case colour merge', 'code' => 'merge_colour', 'purpose' => 'filter', 'input_type' => 'select',
            'shown_in_set' => '1', 'shown_in' => ['filters'], 'values_json' => $json,
        ]);

        $save($rows([['id' => null, 'value' => ['en' => 'Dark  BLUE'], 'code' => '', 'position' => 2]]))->assertSessionHasErrors('values');
        $this->assertFalse(FeatureValue::where('feature_id', $this->colour->id)->where('value->en', 'Dark  BLUE')->exists());

        // The two that were already alike save as they are (the third one,
        // left out of the form, is the editor's ordinary "removed").
        $save($rows())->assertSessionHasNoErrors();
        $this->assertNotNull($this->plain->fresh());
    }

    public function test_a_merged_spelling_can_be_let_go(): void
    {
        app(MergeValues::class)->merge($this->navy, [$this->plain, $this->shouted]);
        $navy = $this->navy->fresh();
        [$keep, $drop] = $navy->aliases;

        $this->actingAs(User::role('admin')->firstOrFail())->put('http://localhost/admin/catalog/features/'.$this->colour->id, [
            'name' => 'Case colour merge', 'code' => 'merge_colour', 'purpose' => 'filter', 'input_type' => 'select',
            'shown_in_set' => '1', 'shown_in' => ['filters'],
            'values_json' => json_encode([['id' => $navy->id, 'value' => $navy->value, 'code' => 'NAVY', 'position' => 0, 'aliases' => [$keep]]]),
        ])->assertSessionHasNoErrors();

        $this->assertSame([$keep], $navy->fresh()->aliases);
        $this->assertNotSame($drop, $keep);
    }

    public function test_the_invariant_names_a_characteristic_with_values_written_alike(): void
    {
        $check = (new CatalogInvariants)->register()['catalog.values_spelled_once']['check'];

        $finding = collect($check(500))->firstWhere('id', (string) $this->colour->id);
        $this->assertNotNull($finding);
        $this->assertStringContainsString("ТЁМНО\u{2014}СИНИЙ", $finding['detail'][1]['values']);

        app(MergeValues::class)->merge($this->navy, [$this->plain, $this->shouted]);
        $this->assertNull(collect($check(500))->firstWhere('id', (string) $this->colour->id));
    }

    protected function product(string $sku, ?FeatureValue $value, array $attributes = []): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => 'Case '.$sku], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 4, 'status' => 'active', ...$attributes,
        ]);
        $product->categories()->attach($this->category->id);

        if ($value) {
            ProductFeature::create(['product_id' => $product->id, 'feature_id' => $value->feature_id, 'feature_value_id' => $value->id]);
        }

        return $product;
    }

    protected function filterPage(Category $category, string $slug, array $valueIds, array $attributes = []): FilterPage
    {
        $values = [$this->colour->id => $valueIds];

        return FilterPage::create([
            'category_id' => $category->id,
            'slug' => $slug,
            'name' => ['en' => ucfirst($slug)],
            'values' => FilterPage::canonicalValues($values),
            'fingerprint' => FilterPage::fingerprintFor($category->id, $values),
            'enabled' => true,
            'in_sitemap' => true,
            ...$attributes,
        ]);
    }
}
