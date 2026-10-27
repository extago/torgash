<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Catalog\Features\OwnRows;
use Torgash\Catalog\Invariants\CatalogInvariants;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Exim\ImportRunner;

/**
 * A product's characteristics live in two stores, and neither is a copy of the
 * other (catalog.md, "Two stores of characteristics"): the shop's
 * characteristics, which filters, variations and placeholders read, and the
 * product's own rows, which only the page prints.
 *
 * From the first live shop's report: its agent changed a colour in both places
 * and could not tell which one counted. The API had shown it the own rows and
 * nothing else, and nothing said that the page printed the two side by side.
 * What is pinned here: every writer puts a fact in ONE store, every reader can
 * see both, and a page that prints one thing twice is pointed out — but not
 * refused, because "16 GB DDR4, two slots" beside the filter's "16 GB" is
 * sometimes exactly what the shop means.
 */
class CharacteristicStoresTest extends TestCase
{
    protected Feature $ram;

    protected FeatureValue $sixteen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ram = Feature::create([
            'name' => ['en' => 'Quokka RAM', 'ru' => 'Память квокки'],
            'purpose' => 'filter',
            'input_type' => 'select',
            'shown_in' => ['filters', 'specs'],
        ]);
        $this->sixteen = FeatureValue::create(['feature_id' => $this->ram->id, 'value' => ['en' => '16 GB', 'ru' => '16 ГБ']]);
    }

    // ------------------------------------------------------------ writers

    /**
     * The characteristics cell is either a typed cell (the shop's
     * characteristics) or a JSON table (the product's own rows) — never both —
     * and a column named after a characteristic is that characteristic. An own
     * row called like one stays an own row: an import routes nothing into the
     * shop's vocabulary on a guess.
     */
    public function test_an_import_writes_each_cell_into_one_store(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $counts = $this->import([
            ['Product code', 'Product name', 'Features', 'Quokka RAM'],
            ['TWO-A', 'Alpha', 'Quokka shade: S[Teal]', '16 GB'],
            ['TWO-B', 'Beta', '[{"type":"section","label":"Body"},{"key":"Quokka RAM","value":"16 GB DDR4, two slots"}]', '16 GB'],
        ]);

        $this->assertSame(2, $counts['created']);
        $alpha = Product::where('sku', 'TWO-A')->sole();
        $beta = Product::where('sku', 'TWO-B')->sole();

        // The typed cell and the named column: the shop's store only.
        $this->assertSame([], (array) $alpha->features);
        $shade = Feature::whereRaw('name->>? = ?', [content_base_locale(), 'Quokka shade'])->sole();
        $this->assertEqualsCanonicalizing([$shade->id, $this->ram->id], $alpha->featureAssignments()->pluck('feature_id')->all());

        // The JSON table: the own store only, as written.
        $this->assertSame(['Quokka RAM' => '16 GB DDR4, two slots'], $beta->features);
        $this->assertSame([$this->ram->id], $beta->featureAssignments()->pluck('feature_id')->all(), 'only the column wrote the shop\'s RAM');
        $this->assertSame($this->sixteen->id, $beta->featureAssignments()->sole()->feature_value_id);
        $this->assertFalse(FeatureValue::where('feature_id', $this->ram->id)->whereRaw("value::text like '%DDR4%'")->exists(), 'an own row never becomes a filter value');
    }

    /**
     * A supplier's file writes `[]` for a product with no specs. The column is
     * NOT NULL, and the import is one transaction — so that one cell used to
     * roll the whole file back.
     */
    public function test_an_empty_json_table_is_an_empty_table(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $counts = $this->import([
            ['Product code', 'Product name', 'Features'],
            ['TWO-E', 'Empty', '[]'],
            ['TWO-F', 'Full', '[{"key":"Shell","value":"Steel"}]'],
        ]);

        $this->assertSame(2, $counts['created']);
        $this->assertSame([], (array) Product::where('sku', 'TWO-E')->sole()->features);
        $this->assertSame(['Shell' => 'Steel'], Product::where('sku', 'TWO-F')->sole()->features);
    }

    // ------------------------------------------------------------ readers

    /** The admin half shows both stores, each under its own name. */
    public function test_the_admin_api_shows_both_stores(): void
    {
        $product = $this->product(['Detail' => 'Brushed']);
        [, $token] = ApiToken::issue(User::role('admin')->firstOrFail(), 'Tests');

        $data = $this->json('GET', 'http://localhost/api/v2/admin/products/'.$product->sku, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->json('data');

        $this->assertSame([[
            'code' => $this->ram->code,
            'name' => ['en' => 'Quokka RAM', 'ru' => 'Память квокки'],
            'purpose' => 'filter',
            'value' => ['en' => '16 GB', 'ru' => '16 ГБ'],
            'value_code' => $this->sixteen->code,
        ]], $data['characteristics']);
        $this->assertSame([['type' => 'feature', 'key' => 'Detail', 'value' => 'Brushed', 'label' => 'Detail']], $data['features']);
    }

    /** A visitor sees what the page's specifications show, and no more. */
    public function test_the_shop_api_shows_what_the_page_shows(): void
    {
        $hidden = Feature::create(['name' => ['en' => 'Quokka batch'], 'purpose' => 'filter', 'input_type' => 'text', 'shown_in' => ['filters']]);
        $product = $this->product();
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $hidden->id, 'value_text' => 'B-7']);

        $characteristics = $this->getJson('http://localhost/api/v2/shop/products/'.$product->sku)->assertOk()->json('data.characteristics');

        $this->assertSame([$this->ram->code], array_column($characteristics, 'code'));
        $this->assertSame($this->sixteen->code, $characteristics[0]['value_code']);
        $this->assertIsString($characteristics[0]['name']);
        $this->assertIsString($characteristics[0]['value']);
    }

    // ------------------------------------------------------- printed twice

    /**
     * Printed twice = the product HAS the shop's value, the specifications show
     * it, and an own row is called like it — in any language, whatever the
     * case. Not by the code: that is a machine's word.
     */
    public function test_a_row_printed_twice_is_found_by_any_name_and_not_by_code(): void
    {
        $rows = new OwnRows;

        $this->assertSame(['quokka ram ' => $this->ram->id], $this->ids($rows->printedTwice($this->product(['quokka ram ' => '16 GB DDR4']))));
        $this->assertSame(['Память квокки' => $this->ram->id], $this->ids($rows->printedTwice($this->product(['Память квокки' => '16 ГБ DDR4']))));
        $this->assertSame([], $rows->printedTwice($this->product([$this->ram->code => '16 GB DDR4'])));
    }

    /** Filter-only, or no value: the own row is the only line the page prints. */
    public function test_a_longer_wording_beside_a_filter_only_value_is_fine(): void
    {
        $rows = new OwnRows;
        $noValue = Product::create($this->attributes(['Quokka RAM' => '16 GB DDR4']));
        $this->assertSame([], $rows->printedTwice($noValue));

        $this->ram->update(['shown_in' => ['filters']]);
        $this->assertSame([], $rows->printedTwice($this->product(['Quokka RAM' => '16 GB DDR4'])));
    }

    /** The nightly sweep asks the same question in SQL and gets the same answer. */
    public function test_the_invariant_agrees_with_the_product(): void
    {
        $twice = $this->product(['Quokka RAM' => '16 GB DDR4']);
        $russian = $this->product(['Память квокки' => '16 ГБ DDR4']);
        $byCode = $this->product([$this->ram->code => '16 GB DDR4']);
        $other = $this->product(['Shell' => 'Steel']);
        $noValue = Product::create($this->attributes(['Quokka RAM' => '16 GB DDR4']));
        // An emptied map was stored as a list for years; jsonb_object_keys() refuses one.
        $emptied = Product::create($this->attributes([]));

        $mine = [$twice->id, $russian->id, $byCode->id, $other->id, $noValue->id, $emptied->id];
        $found = fn () => collect(app(CatalogInvariants::class)->register()['catalog.characteristics_in_one_place']['check'](500))
            ->pluck('id')->map(fn ($id) => (int) $id)->intersect($mine)->sort()->values()->all();

        $this->assertSame([$twice->id, $russian->id], $found());

        $this->ram->update(['shown_in' => ['filters']]);
        $this->assertSame([], $found());
    }

    /**
     * The form says so above the own rows — and saves anyway: which of the two
     * the shop means is the shop's to say.
     */
    public function test_the_form_points_it_out_and_still_saves(): void
    {
        $product = $this->product(['Quokka RAM' => '16 GB DDR4']);
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertSee('The page shows these twice', false);

        $this->put('http://localhost/admin/catalog/products/'.$product->id, [
            'sku' => $product->sku,
            'name' => 'Two stores',
            'price' => '10',
            'stock' => '1',
            'status' => 'active',
            'features' => json_encode(['Quokka RAM' => '16 GB DDR4, two slots']),
            'global_features' => [$this->ram->id => $this->sixteen->id],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(['Quokka RAM' => '16 GB DDR4, two slots'], $product->fresh()->features);
        $this->assertSame($this->sixteen->id, $product->featureAssignments()->where('feature_id', $this->ram->id)->sole()->feature_value_id);
    }

    // ------------------------------------------------------------ helpers

    /** A product with the shop's RAM = 16 GB and these own rows. */
    protected function product(array $own = []): Product
    {
        $product = Product::create($this->attributes($own));
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $this->ram->id, 'feature_value_id' => $this->sixteen->id]);

        return $product->load('featureAssignments.feature', 'featureAssignments.featureValue');
    }

    protected function attributes(array $own): array
    {
        $n = random_int(10000, 99999);

        return [
            'sku' => 'TWO-'.$n,
            'slug' => 'two-stores-'.$n,
            'name' => ['en' => 'Two stores'],
            'price' => 10_00,
            'stock' => 1,
            'status' => 'active',
            'features' => $own,
        ];
    }

    /** @param  array<string, Feature>  $named */
    protected function ids(array $named): array
    {
        return array_map(fn (Feature $f) => $f->id, $named);
    }

    /** @param  list<list<string>>  $rows */
    protected function import(array $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'two').'.csv';
        file_put_contents($path, implode("\n", array_map(
            fn ($row) => implode(';', array_map(fn ($cell) => '"'.str_replace('"', '""', $cell).'"', $row)),
            $rows,
        ))."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $runner = app(ImportRunner::class);

        return $runner->commit('product', $runner->rowsOf($path, 'product'));
    }
}
