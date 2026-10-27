<?php

namespace Tests\Feature\Cart;

use App\Core\Settings\SettingsManager;
use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Options\Groups;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;

/**
 * Buying from a list (a catalogue card): a plain product is added where it
 * stands, with a quantity; one that must be CHOSEN first (it asks something,
 * or it is one of several variations) opens the choice window instead of
 * dropping a guess into the basket; and what the customer is shown after an
 * add is the shop's setting.
 */
class ListBuyTest extends TestCase
{
    use MakesVariationAxes;

    public function test_a_card_carries_a_buy_form_with_a_quantity(): void
    {
        $this->product('LB-1', 'Listbuy kettle');

        $html = $this->catalogue();

        $this->assertMatchesRegularExpression('/<form hx-post="'.preg_quote(route('cart.add', 'lb-1'), '/').'"[^>]*data-list-buy-add/', $html);
        $this->assertStringContainsString('name="qty" x-model.number="qty"', $html);
    }

    public function test_the_shop_can_switch_the_button_and_the_quantity_off(): void
    {
        $this->product('LB-2', 'Listbuy kettle');

        $this->setting('card_quantity', false);
        $html = $this->catalogue();
        $this->assertStringContainsString(route('cart.add', 'lb-2'), $html);
        $this->assertStringNotContainsString('x-model.number="qty"', $html);

        $this->setting('card_buttons', false);
        $this->assertStringNotContainsString(route('cart.add', 'lb-2'), $this->catalogue());
    }

    public function test_nothing_to_buy_draws_no_button(): void
    {
        $this->product('LB-3', 'Listbuy kettle', ['stock' => 0, 'availability_status' => 'out_of_stock']);

        $this->assertStringNotContainsString(route('cart.add', 'lb-3'), $this->catalogue());
    }

    public function test_a_product_in_the_basket_is_marked_on_its_card(): void
    {
        $this->product('LB-4', 'Listbuy kettle');
        $this->assertDoesNotMatchRegularExpression('/data-list-buy-add\s+data-in-cart/', $this->catalogue());

        $this->post(route('cart.add', 'lb-4'), ['qty' => 2])->assertOk();

        $this->assertMatchesRegularExpression('/data-list-buy-add\s+data-in-cart/', $this->catalogue());
    }

    /** A card never answers a question for the customer. */
    public function test_a_product_that_asks_something_opens_the_choice_window(): void
    {
        $product = $this->product('LB-5', 'Listbuy server');
        $group = OptionGroup::create([
            'product_id' => $product->id, 'admin_name' => 'lb-warranty', 'name' => ['en' => 'Listbuy warranty'],
            'type' => 'radio', 'required' => false, 'enabled' => true,
        ]);
        OptionChoice::create(['group_id' => $group->id, 'name' => ['en' => 'Two years'], 'enabled' => true]);
        app(Groups::class)->flush();

        $html = $this->catalogue();
        $this->assertStringNotContainsString(route('cart.add', 'lb-5'), $html);
        $this->assertStringContainsString('hx-get="'.route('cart.choose', 'lb-5').'"', $html);

        $window = $this->get(route('cart.choose', 'lb-5'))->assertOk();
        // The product page's own buy box — its fields, its refusals.
        $window->assertSee('id="buy-box-'.$product->id.'"', false)
            ->assertSee('Listbuy warranty')
            ->assertSee('hx-post="'.route('cart.add', 'lb-5').'"', false);
    }

    public function test_one_of_several_variations_opens_the_choice_window_with_its_siblings(): void
    {
        [, $sizes] = $this->variationAxis('Jacket size', ['S', 'L']);
        $small = $this->product('LB-S', 'Listbuy jacket S', ['variation_group' => 'lb-jacket']);
        $large = $this->product('LB-L', 'Listbuy jacket L', ['variation_group' => 'lb-jacket']);
        $this->answer($small, $sizes['S']);
        $this->answer($large, $sizes['L']);

        $html = $this->catalogue();
        $this->assertStringContainsString('hx-get="'.route('cart.choose', 'lb-s').'"', $html);
        $this->assertStringNotContainsString(route('cart.add', 'lb-s'), $html);

        $this->get(route('cart.choose', $small->slug))->assertOk()
            ->assertSee('Jacket size')
            // The other size redraws the window for ITS product.
            ->assertSee('hx-get="'.route('cart.choose', 'lb-l').'"', false);
    }

    public function test_a_product_nobody_may_reach_has_no_window(): void
    {
        $this->product('LB-6', 'Listbuy hidden', ['status' => 'disabled']);

        $this->get(route('cart.choose', 'lb-6'))->assertNotFound();
    }

    /** The answer to an add: the badge, plus what the shop wants shown, out of band. */
    public function test_after_an_add_the_shop_decides_what_is_shown(): void
    {
        $this->product('LB-7', 'Listbuy kettle');

        $dialog = $this->post(route('cart.add', 'lb-7'), ['qty' => 2])->assertOk()->getContent();
        $this->assertStringContainsString('id="cart-badge" class="btn btn-ghost btn-sm gap-5 px-3" title="Basket"', $dialog);
        $this->assertStringContainsString('hx-swap-oob="true"', $dialog);
        $this->assertStringContainsString('id="torgash-cart-dialog-body" hx-swap-oob="innerHTML"', $dialog);
        $this->assertStringContainsString('Added to your basket', $dialog);
        $this->assertStringContainsString('2 ×', $dialog);

        $this->setting('after_add', 'toast');
        $toast = $this->post(route('cart.add', 'lb-7'))->getContent();
        $this->assertStringContainsString('id="torgash-notices" hx-swap-oob="afterbegin"', $toast);
        $this->assertStringNotContainsString('torgash-cart-dialog-body', $toast);

        // A form may ask for another answer (the search window does)…
        $none = $this->post(route('cart.add', 'lb-7'), ['after_add' => 'nothing'])->getContent();
        $this->assertStringNotContainsString('torgash-cart-dialog-body', $none);
        $this->assertStringNotContainsString('torgash-notices', $none);
        // …but only in the three known words.
        $this->assertStringContainsString('torgash-notices', $this->post(route('cart.add', 'lb-7'), ['after_add' => '<script>'])->getContent());
    }

    public function test_the_shared_dialog_is_on_every_storefront_page(): void
    {
        $this->get('http://localhost/')->assertOk()
            ->assertSee('id="torgash-cart-dialog"', false)
            ->assertSee('/modules/torgash.cart/assets/cart.js', false);
    }

    protected function catalogue(): string
    {
        return $this->get('http://localhost/catalog?q=&sort=newest')->assertOk()->getContent();
    }

    protected function setting(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('torgash.cart', $key, $value);
    }

    /** @param  array<string, mixed>  $extra */
    protected function product(string $sku, string $name, array $extra = []): Product
    {
        return Product::create($extra + [
            'sku' => $sku,
            'name' => ['en' => $name],
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 10,
            'status' => 'active',
            'created_at' => now()->addYear(),
        ]);
    }
}
