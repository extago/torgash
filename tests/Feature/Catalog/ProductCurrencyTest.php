<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Core\Support\Currencies;
use App\Models\Currency;
use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * A product priced in a currency that is not the shop's own.
 *
 * The point of it is that the TYPED number is the one that stays still: a
 * product agreed with its supplier at ₴42 000 is ₴42 000 next month too, and
 * what moves is its dollar equivalent. The shop's books column beside it is
 * derived — which is what lets every query, sort, filter and module go on
 * reading `price` and knowing nothing about any of this.
 */
class ProductCurrencyTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::updateOrCreate(['code' => 'UAH'], [
            'symbol' => '₴', 'rate' => 41, 'decimals' => 0, 'enabled' => true,
        ]);
        app(Currencies::class)->flush();

        foreach (Storefront::all() as $storefront) {
            $storefront->update(['currencies' => [...($storefront->currencies ?? []), 'UAH']]);
        }

        $this->admin = User::role('admin')->firstOrFail();
        app(SettingsManager::class)->set('torgash.catalog', 'prices_in_other_currencies', true);
    }

    /**
     * The picker is on the price field, and only when the shop has said its
     * products may be priced in something other than its own currency.
     *
     * Both halves are the point: a catalogue priced entirely in one currency
     * should not grow a select beside every price, and a shop that turned the
     * setting on should not have to wonder where the switch went.
     */
    public function test_the_price_field_gets_a_currency_picker_only_when_the_shop_allows_it(): void
    {
        $product = Product::create([
            'sku' => 'PICK-1', 'name' => ['en' => 'Picker'], 'slug' => 'picker',
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);

        $url = 'http://localhost/admin/catalog/products/'.$product->id.'/edit';

        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('name="price_currency"', false);

        app(SettingsManager::class)->set('torgash.catalog', 'prices_in_other_currencies', false);

        $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSee('name="price_currency"', false);
    }

    /** Typed in hryvnia, stored in both: the number agreed, and the books. */
    public function test_a_price_typed_in_another_currency_is_kept_as_typed_and_derived_into_the_books(): void
    {
        $product = $this->save(['price' => '42000', 'price_currency' => 'UAH', 'cost_price' => '30000']);

        $this->assertSame('UAH', $product->price_currency);
        $this->assertSame(4200000, $product->price_original);
        // 42 000 ₴ / 41 = $1 024.39, in cents.
        $this->assertSame(102439, $product->price);
        $this->assertSame(3000000, $product->cost_price_original);
        $this->assertSame(73171, $product->cost_price);
    }

    /**
     * The rate moves; the hryvnia price does not.
     *
     * Announced by core from the model, answered by the catalogue — so a
     * nightly rate job would do the same thing without anybody wiring it up.
     */
    public function test_a_rate_change_moves_the_books_price_and_leaves_the_typed_one_alone(): void
    {
        $product = $this->save(['price' => '42000', 'price_currency' => 'UAH']);

        Currency::where('code', 'UAH')->firstOrFail()->update(['rate' => 50]);
        app(Currencies::class)->flush();

        $product->refresh();

        $this->assertSame(4200000, $product->price_original);
        // 42 000 ₴ / 50 = $840.00.
        $this->assertSame(84000, $product->price);
        // ...and the storefront, asked in hryvnia, still says 42 000.
        $this->assertSame("42\u{00A0}000\u{00A0}₴", format_price($product->price, 'UAH'));
    }

    /** A product priced in the shop's own currency writes nothing extra down. */
    public function test_the_shops_own_currency_is_not_a_conversion(): void
    {
        $product = $this->save(['price' => '1000', 'price_currency' => shop_currency()]);

        $this->assertNull($product->price_currency);
        $this->assertNull($product->price_original);
        $this->assertSame(100000, $product->price);
    }

    /**
     * The inline edit on the list screen has no currency picker, so a number
     * typed there means "the same currency as last time" — never the shop's.
     */
    public function test_a_quick_edit_stays_in_the_products_own_currency(): void
    {
        $product = $this->save(['price' => '42000', 'price_currency' => 'UAH']);

        $this->actingAs($this->admin)->patch(
            'http://localhost/admin/catalog/products/'.$product->id.'/quick',
            ['sku' => $product->sku, 'price' => '45000', 'stock' => 5],
        )->assertOk();

        $product->refresh();

        $this->assertSame('UAH', $product->price_currency);
        $this->assertSame(4500000, $product->price_original);
        $this->assertSame(109756, $product->price);
    }

    /** With the setting off, the picker is not there and a crafted request is ignored. */
    public function test_the_feature_is_off_by_default(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'prices_in_other_currencies', false);

        $product = $this->save(['price' => '42000', 'price_currency' => 'UAH']);

        $this->assertNull($product->price_currency);
        $this->assertSame(4200000, $product->price);
    }

    /**
     * Deleting the currency does not leave a product pointing at nothing: the
     * price it last had, in the books, is what is left.
     */
    public function test_deleting_a_currency_leaves_the_last_agreed_price_behind(): void
    {
        $product = $this->save(['price' => '42000', 'price_currency' => 'UAH']);

        Currency::where('code', 'UAH')->firstOrFail()->delete();
        app(Currencies::class)->flush();

        $product->refresh();

        $this->assertNull($product->price_currency);
        $this->assertNull($product->price_original);
        $this->assertSame(102439, $product->price);
    }

    /** @param array<string, mixed> $overrides */
    protected function save(array $overrides): Product
    {
        $sku = 'PCUR-'.random_int(1000, 9999);

        $this->actingAs($this->admin)
            ->post('http://localhost/admin/catalog/products', $overrides + [
                'sku' => $sku,
                'name' => 'Currency widget',
                'price' => '1000',
                'stock' => 5,
                'status' => 'active',
            ])
            ->assertRedirect();

        return Product::where('sku', $sku)->firstOrFail();
    }
}
