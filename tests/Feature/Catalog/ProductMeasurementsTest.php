<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Measurements;

/**
 * Where a product's weight comes from, and what "no weight" means.
 *
 * The case behind this is a catalogue imported with the weight sitting in a
 * spec: without the resolver every cart weighs nothing and every weight band
 * charges its first row, with no error anywhere. The other half is that an
 * unmeasured product must stay UNMEASURED — null, not zero — because that is
 * the signal a shipping method acts on.
 */
class ProductMeasurementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('core', 'weight_unit', 'kg');
        app(SettingsManager::class)->set('core', 'length_unit', 'cm');
    }

    public function test_the_column_answers_when_it_is_filled(): void
    {
        $product = $this->product(['weight' => 800]);

        $this->assertSame(800, $this->measurements()->weightOf($product));
    }

    public function test_a_per_product_characteristic_answers_when_the_column_is_empty(): void
    {
        $this->mapping('Вес', 'kg');
        $product = $this->product(['features' => ['Вес' => '0,65 кг', 'Цвет' => 'синий']]);

        // The number is read out of the text; the unit comes from the setting.
        $this->assertSame(650, $this->measurements()->weightOf($product));
    }

    public function test_the_column_still_wins_over_the_characteristic(): void
    {
        $this->mapping('Вес', 'kg');
        $product = $this->product(['weight' => 900, 'features' => ['Вес' => '0.65']]);

        $this->assertSame(900, $this->measurements()->weightOf($product));
    }

    public function test_a_global_feature_can_hold_it_and_is_loaded_in_one_query(): void
    {
        $feature = Feature::create(['name' => ['en' => 'Weight'], 'code' => 'WEIGHT', 'input_type' => 'text']);
        $this->mapping('weight', 'kg'); // matched case-insensitively by code

        $products = collect([1.2, 0.4, null])->map(function ($value, $i) use ($feature) {
            $product = $this->product(['sku' => "GF-{$i}"]);

            if ($value !== null) {
                DB::table('m_catalog_product_features')->insert([
                    'product_id' => $product->id,
                    'feature_id' => $feature->id,
                    'value_text' => (string) $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $product;
        });

        $measurements = $this->measurements();
        // Warm the "which characteristic holds the weight" lookup, which a real
        // request resolves once; what is being counted here is the batch load.
        $measurements->prime([]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $measurements->prime($products);
        $weights = $products->map(fn ($p) => $measurements->weightOf($p))->all();
        $queries = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        $this->assertSame([1200, 400, null], $weights);
        // One for the whole set: this is the N+1 the resolver exists to avoid.
        $this->assertSame(1, $queries, "resolving three products ran {$queries} queries");
    }

    public function test_nothing_measured_is_null_and_not_zero(): void
    {
        $this->assertNull($this->measurements()->weightOf($this->product()));
    }

    public function test_a_cart_weighs_what_the_resolver_says_and_admits_when_it_cannot(): void
    {
        $this->mapping('Вес', 'kg');

        $measured = $this->product(['weight' => 500]);
        $fromSpec = $this->product(['features' => ['Вес' => '1,2 кг']]);
        $unmeasured = $this->product();

        // Through the manager, not the session: a cart is a row addressed by
        // a token now, and its storage shape was never a contract.
        $cart = app(\Torgash\Cart\CartManager::class);
        $cart->add($measured->id, 2);
        $cart->add($fromSpec->id, 1);

        // 2 × 500 g plus the 1.2 kg that only the characteristic knows about.
        $this->assertSame(2200, $cart->weight());
        $this->assertTrue($cart->weightIsKnown());

        $cart->clear();
        $cart->add($measured->id, 1);
        $cart->add($unmeasured->id, 1);

        // The unmeasured line adds nothing, and says so: this false is what
        // makes a method quote by hand instead of charging for a weightless
        // parcel (PLAN §12.4).
        $this->assertSame(500, $cart->weight());
        $this->assertFalse($cart->weightIsKnown());
    }

    public function test_the_form_saves_what_the_shop_types_as_grams_and_millimetres(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://localhost/admin/catalog/products', [
            'sku' => 'DIMS-1',
            'name' => 'Boxed thing',
            'price' => '10',
            'stock' => '1',
            'status' => Product::STATUS_ACTIVE,
            'weight' => '1.25',
            'length' => '20',
            'width' => '15',
            'height' => '10.5',
        ])->assertRedirect();

        $product = Product::firstWhere('sku', 'DIMS-1');

        $this->assertSame([1250, 200, 150, 105], [$product->weight, $product->length, $product->width, $product->height]);
    }

    public function test_clearing_the_weight_field_means_unmeasured_again(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product(['weight' => 500, 'length' => 100]);

        $this->put("http://localhost/admin/catalog/products/{$product->id}", [
            'sku' => $product->sku,
            'name' => 'Boxed thing',
            'price' => '10',
            'stock' => '1',
            'status' => Product::STATUS_ACTIVE,
            'weight' => '',
            'length' => '',
            'width' => '',
            'height' => '',
        ])->assertRedirect();

        $product->refresh();

        $this->assertNull($product->weight);
        $this->assertNull($product->length);
    }

    protected function mapping(string $key, string $unit): void
    {
        $settings = app(SettingsManager::class);
        $settings->set('torgash.catalog', 'weight_feature', $key);
        $settings->set('torgash.catalog', 'weight_feature_unit', $unit);
    }

    /** A fresh resolver per call — the real one memoises the mapping per request. */
    protected function measurements(): Measurements
    {
        return new Measurements;
    }

    protected function product(array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create($attributes + [
            'sku' => "MEAS-{$n}",
            'name' => ['en' => "Measured {$n}"],
            'slug' => "measured-{$n}",
            'price' => 1000,
            'stock' => 5,
            'status' => Product::STATUS_ACTIVE,
        ]);
    }

    protected function admin(): \App\Models\User
    {
        $user = \App\Models\User::firstWhere('email', 'measurements@test');

        if (! $user) {
            $user = \App\Models\User::create([
                'name' => 'Admin', 'email' => 'measurements@test', 'password' => bcrypt('x'),
            ]);
            $user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin'));
        }

        return $user;
    }
}
