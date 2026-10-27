<?php

namespace Tests\Feature\Kits;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\CouponManager;
use Torgash\Checkout\Models\Coupon;
use Torgash\Checkout\Orders\OrderLines;
use Torgash\Kits\Discounts;
use Torgash\Kits\Kits;
use Torgash\Kits\Models\Kit;
use Torgash\Kits\Models\KitSlot;
use Torgash\Kits\Offer;

/**
 * Kits: separate products that are cheaper together (PLAN §33.5).
 *
 * What is pinned here is what makes a kit a kit rather than an option: the
 * goods stay separate lines, the discount is taken off the LINE (so 1C, a
 * refund and a return all see what each item actually cost), it is only owed
 * while the set is really in the basket, and a slot drawn from a set offers
 * only what FITS the product being looked at.
 */
class KitsTest extends TestCase
{
    use MakesVariationAxes;

    protected Category $phones;

    protected Category $accessories;

    protected Feature $model;

    protected Product $phone;

    protected Product $glass;

    protected Product $case;

    protected function setUp(): void
    {
        parent::setUp();

        app(Kits::class)->flush();
        app(Discounts::class)->flush();

        $this->phones = Category::create(['name' => ['en' => 'Phones'], 'slug' => 'phones', 'enabled' => true]);
        $this->accessories = Category::create(['name' => ['en' => 'Accessories'], 'slug' => 'accessories', 'enabled' => true]);

        $this->model = Feature::create(['name' => ['en' => 'Fits the model'], 'code' => 'FITS', 'purpose' => 'filter', 'input_type' => 'select']);
        $thisModel = FeatureValue::create(['feature_id' => $this->model->id, 'value' => ['en' => 'Model A']]);
        $otherModel = FeatureValue::create(['feature_id' => $this->model->id, 'value' => ['en' => 'Model B']]);

        $this->phone = $this->product('PH-1', 'Phone', 1000_00, $this->phones);
        $this->glass = $this->product('GL-1', 'Glass for A', 100_00, $this->accessories);
        $this->case = $this->product('CS-1', 'Case for A', 200_00, $this->accessories);
        $wrongGlass = $this->product('GL-2', 'Glass for B', 100_00, $this->accessories);

        foreach ([[$this->phone, $thisModel], [$this->glass, $thisModel], [$this->case, $thisModel], [$wrongGlass, $otherModel]] as [$product, $value]) {
            DB::table('m_catalog_product_features')->insert([
                'product_id' => $product->id,
                'feature_id' => $this->model->id,
                'feature_value_id' => $value->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * The compatibility rule, which is what makes one kit row cover a whole
     * category: a glass for the model being looked at, and not the other one.
     */
    public function test_a_slot_offers_only_what_fits_the_main_product(): void
    {
        $kit = $this->kit();
        $slot = $this->slot($kit, ['kind' => 'source', 'source' => 'category:'.$this->accessories->id, 'match_features' => [$this->model->id], 'discount_percent' => 20]);

        $candidates = app(Offer::class)->candidates($slot, $this->phone)->pluck('sku')->all();

        $this->assertEqualsCanonicalizing(['GL-1', 'CS-1'], $candidates);
    }

    /** A product whose model the main one cannot answer fits nothing at all. */
    public function test_a_main_product_with_no_such_characteristic_is_offered_nothing(): void
    {
        $kit = $this->kit();
        $slot = $this->slot($kit, ['kind' => 'source', 'source' => 'category:'.$this->accessories->id, 'match_features' => [$this->model->id]]);
        $anonymous = $this->product('PH-9', 'Phone with no model', 900_00, $this->phones);

        $this->assertTrue(app(Offer::class)->candidates($slot, $anonymous)->isEmpty());
    }

    /**
     * An OUTFIT: the same colour AND the same fabric as the jacket. One
     * condition could not say it, and two slots with one condition each would
     * each offer everything matching their own half.
     */
    public function test_a_slot_can_insist_on_several_characteristics_at_once(): void
    {
        [$colour, $green, $tan] = $this->feature('COLOUR', ['Green', 'Tan']);
        [$fabric, $ripstop, $fleece] = $this->feature('FABRIC', ['Ripstop', 'Fleece']);

        $jacket = $this->product('JK-1', 'Jacket', 500_00, $this->phones);
        $right = $this->product('TR-1', 'Green ripstop trousers', 300_00, $this->accessories);
        $wrongFabric = $this->product('TR-2', 'Green fleece trousers', 300_00, $this->accessories);
        $wrongColour = $this->product('TR-3', 'Tan ripstop trousers', 300_00, $this->accessories);

        $this->assign($jacket, [$green, $ripstop]);
        $this->assign($right, [$green, $ripstop]);
        $this->assign($wrongFabric, [$green, $fleece]);
        $this->assign($wrongColour, [$tan, $ripstop]);

        $kit = $this->kit();
        $slot = $this->slot($kit, [
            'kind' => 'source',
            'source' => 'category:'.$this->accessories->id,
            'match_features' => [$colour->id, $fabric->id],
        ]);

        $candidates = app(Offer::class)->candidates($slot, $jacket)->pluck('sku')->all();

        $this->assertSame(['TR-1'], $candidates, 'all of the conditions, never any of them');
    }

    /**
     * …and a main product that cannot answer ONE of them fits nothing, rather
     * than being quietly matched on the rest: a jacket with no fabric recorded
     * would otherwise be offered trousers of every fabric there is.
     */
    public function test_one_unanswerable_condition_is_enough_to_offer_nothing(): void
    {
        [$colour, $green] = $this->feature('COLOUR', ['Green']);
        [$fabric, $ripstop] = $this->feature('FABRIC', ['Ripstop']);

        $jacket = $this->product('JK-2', 'Jacket with no fabric', 500_00, $this->phones);
        $trousers = $this->product('TR-4', 'Green ripstop trousers', 300_00, $this->accessories);

        $this->assign($jacket, [$green]);
        $this->assign($trousers, [$green, $ripstop]);

        $kit = $this->kit();
        $slot = $this->slot($kit, [
            'kind' => 'source',
            'source' => 'category:'.$this->accessories->id,
            'match_features' => [$colour->id, $fabric->id],
        ]);

        $this->assertTrue(app(Offer::class)->candidates($slot, $jacket)->isEmpty());
    }

    /**
     * The picker shows one card per MODEL, not one per size: a t-shirt in six
     * sizes is six products here, and six cards of the same photograph is a
     * window nobody can read. The size is chosen inside the card.
     */
    public function test_the_picker_lists_a_model_once_however_many_sizes_it_has(): void
    {
        [, $sizes] = $this->variationAxis('T-shirt size', ['S', 'M', 'L']);
        foreach (['S', 'M', 'L'] as $size) {
            $shirt = Product::create([
                'sku' => 'TS-'.$size, 'name' => ['en' => 'T-shirt '.$size], 'slug' => 'ts-'.strtolower($size),
                'price' => 100_00, 'stock' => 5, 'status' => 'active', 'availability_status' => 'in_stock',
                'variation_group' => 'tshirt',
            ]);
            $shirt->categories()->attach($this->accessories->id);
            $this->answer($shirt, $sizes[$size]);
        }

        $kit = $this->kit();
        $slot = $this->slot($kit, ['kind' => 'source', 'source' => 'category:'.$this->accessories->id]);

        $groups = app(Offer::class)->candidateGroups($slot, $this->phone);
        $shirts = $groups->get('tshirt');

        $this->assertNotNull($shirts, 'the three sizes are one entry');
        $this->assertCount(3, $shirts);
        $this->assertEqualsCanonicalizing(
            ['S', 'M', 'L'],
            $shirts->map(fn (Product $p) => Offer::variationLabel($p, app(\Torgash\Catalog\Support\Variations::class)->for($shirts->first())))->all(),
            'and each is labelled by what differs between them',
        );
    }

    /** Two slots from one set fill with two different products. */
    public function test_two_slots_do_not_both_take_the_same_product(): void
    {
        $kit = $this->kit();
        $this->slot($kit, ['kind' => 'source', 'source' => 'category:'.$this->accessories->id, 'match_features' => [$this->model->id], 'discount_percent' => 20, 'sort' => 10]);
        $this->slot($kit, ['kind' => 'source', 'source' => 'category:'.$this->accessories->id, 'match_features' => [$this->model->id], 'discount_percent' => 20, 'sort' => 20]);

        $offer = app(Offer::class)->for($kit->fresh('slots'), $this->phone);

        $this->assertCount(2, $offer['slots']);
        $this->assertNotSame($offer['slots'][0]['product']->id, $offer['slots'][1]['product']->id);
    }

    /**
     * An extended set is ONE basket line whose parts are the rest of it — and
     * the ORDER still has a line per goods, because a part that is a product
     * becomes its own line with `parent_key`. That is what lets a placed order
     * say what the case cost, which a cart-level bundle discount never could.
     */
    public function test_the_set_is_one_line_and_the_order_still_has_one_per_goods(): void
    {
        $kit = $this->kit();
        $slot = $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 25]);

        $cart = app(CartManager::class);
        $cart->add($this->phone->id, 1, $this->bought($kit, [$slot->id => $this->case->id]));

        $line = $cart->items()->firstWhere('product.id', $this->phone->id);

        $this->assertCount(1, $cart->items(), 'the basket holds the set, not its pieces');
        $this->assertSame(1000_00 + 150_00, $line->line_total, 'the phone plus the case at its price inside the set');

        $lines = OrderLines::fromBasket($cart->items());
        $caseLine = collect($lines)->firstWhere('product_id', $this->case->id);

        $this->assertSame(150_00, $caseLine['unit_price'], 'what the case really cost');
        $this->assertSame($line->key, $caseLine['parent_key'], 'and what it was bought as part of');
        $this->assertSame($cart->total(), array_sum(array_column($lines, 'line_total')));
    }

    /**
     * THE HOLE THIS SHAPE CLOSES (measured before the change): one phone and a
     * hundred glasses, every one of them at half price. A part's quantity is
     * its own times its parent's, so there is no separate line to raise — and
     * raising the SET buys a phone for every glass.
     */
    public function test_the_discount_cannot_be_multiplied_without_buying_more_sets(): void
    {
        $kit = $this->kit();
        $slot = $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 50]);

        $cart = app(CartManager::class);
        $cart->add($this->phone->id, 1, $this->bought($kit, [$slot->id => $this->case->id]));

        // A shelf deep enough for a hundred sets: the basket refuses more
        // phones than there are.
        $this->phone->forceFill(['stock' => 100])->saveQuietly();

        $line = $cart->items()->first();
        $cart->update($line->key, 100);

        $lines = OrderLines::fromBasket($cart->items());
        $phoneLine = collect($lines)->firstWhere('product_id', $this->phone->id);
        $caseLine = collect($lines)->firstWhere('product_id', $this->case->id);

        $this->assertSame(100, $phoneLine['qty'], 'a hundred sets are a hundred phones');
        $this->assertSame(100, $caseLine['qty']);
        $this->assertSame(100 * 100_00, $caseLine['line_total'], 'one discounted case per phone, never more');
    }

    /** Take the set out and everything in it goes: it was one line. */
    public function test_removing_the_set_removes_what_was_in_it(): void
    {
        $kit = $this->kit();
        $slot = $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 25]);

        $cart = app(CartManager::class);
        $cart->add($this->phone->id, 1, $this->bought($kit, [$slot->id => $this->case->id]));

        $cart->remove($cart->items()->first()->key);

        $this->assertTrue($cart->items()->isEmpty());
    }

    /** A kit whose dates have run out sells the goods at their own price. */
    public function test_an_offer_that_has_ended_charges_full_price(): void
    {
        $kit = $this->kit(['ends_at' => now()->subDay()]);
        $slot = $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 25]);

        $cart = app(CartManager::class);
        $cart->add($this->phone->id, 1, $this->bought($kit, [$slot->id => $this->case->id]));
        app(Kits::class)->flush();
        app(Discounts::class)->flush();

        // The parts are gone with the offer, so the line is the bare phone.
        $this->assertSame(1000_00, $cart->items()->first()->line_total);
    }

    /**
     * A basket that happens to hold the same goods has not bought the set.
     * This used to need a rule (`auto_assemble`, and a search of the basket
     * for the main product); now it needs nothing — a discount lives on a part
     * of a line, and two ordinary lines have no parts.
     */
    public function test_the_same_goods_bought_separately_are_not_a_set(): void
    {
        $kit = $this->kit();
        $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 25]);

        $cart = app(CartManager::class);
        $cart->add($this->phone->id, 1);
        $cart->add($this->case->id, 1);

        $this->assertSame(200_00, $cart->items()->firstWhere('product.id', $this->case->id)->line_total);
    }

    /**
     * A SIMPLE set is the other half of the split: several ordinary lines the
     * customer can change one by one, and no discount at all — which is what
     * makes "separate lines plus a discount", the hole, unconfigurable.
     */
    public function test_a_simple_set_adds_ordinary_lines_at_their_own_price(): void
    {
        $kit = $this->kit(['type' => 'simple']);
        $slot = $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 25]);

        $this->post('http://localhost/kits/'.$kit->id.'/add', [
            'main' => $this->phone->id,
            'slots' => [$slot->id => $this->case->id],
        ])->assertOk();

        $cart = app(CartManager::class);

        $this->assertCount(2, $cart->items());
        $this->assertSame(200_00, $cart->items()->firstWhere('product.id', $this->case->id)->line_total);
    }

    /** Pressing the card's button buys the set — and only what is ticked. */
    public function test_adding_the_set_puts_every_ticked_item_in_the_cart(): void
    {
        $kit = $this->kit();
        $glassSlot = $this->slot($kit, ['kind' => 'product', 'product_id' => $this->glass->id, 'discount_percent' => 20, 'sort' => 10]);
        $caseSlot = $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 50, 'sort' => 20]);

        $this->post('http://localhost/kits/'.$kit->id.'/add', [
            'main' => $this->phone->id,
            // The case is unticked: a browser sends nothing for an unticked box.
            'slots' => [$glassSlot->id => $this->glass->id],
        ])->assertOk();

        $cart = app(CartManager::class);
        $lines = OrderLines::fromBasket($cart->items());

        // One basket line, and order lines for exactly what was ticked.
        $this->assertCount(1, $cart->items());
        $this->assertEqualsCanonicalizing(
            [$this->phone->id, $this->glass->id],
            array_column($lines, 'product_id'),
        );
        $this->assertSame(80_00, collect($lines)->firstWhere('product_id', $this->glass->id)['line_total']);
    }

    /**
     * One line, one discount (§33.8): a coupon is a percentage of what is not
     * already cheaper for being in a set.
     */
    public function test_a_coupon_does_not_discount_what_the_kit_already_did(): void
    {
        $kit = $this->kit();
        $slot = $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 50]);

        $cart = app(CartManager::class);
        $cart->add($this->phone->id, 1, $this->bought($kit, [$slot->id => $this->case->id]));

        Coupon::create(['code' => 'TEN', 'type' => 'percent', 'value' => 10, 'enabled' => true]);
        $cart->setCouponCode('TEN');

        // Ten per cent of the phone alone (1000.00), never of the case that is
        // already half price inside the set.
        $this->assertSame(100_00, app(CouponManager::class)->discount());
    }

    /**
     * "Show only the whole set": the shop's own question, and a different one
     * from "a required item".
     *
     * Ordinarily an item with nothing to put in it is left out and the rest of
     * the set is offered anyway, which is right for "people also buy these"
     * and wrong for a set that was designed as a set.
     */
    public function test_a_kit_can_refuse_to_be_shown_half_assembled(): void
    {
        $kit = $this->kit();
        $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 10]);
        // Nothing fits this one: the only product in that category is the main
        // product itself, which a kit never offers as part of itself.
        $this->slot($kit, ['kind' => 'source', 'source' => 'category:'.$this->phones->id]);

        // As it stands: the case is offered on its own.
        $offer = app(Offer::class)->for($kit, $this->phone);
        $this->assertNotNull($offer);
        $this->assertCount(1, $offer['slots']);

        $kit->update(['whole_set_only' => true]);
        app(Kits::class)->flush();

        $this->assertNull(
            app(Offer::class)->for($kit->fresh('slots'), $this->phone),
            'a set that cannot be assembled is not advertised as one',
        );
    }

    /** …and unticking an optional item is the customer's own answer, not a gap. */
    public function test_the_whole_set_rule_is_about_what_cannot_be_filled_not_what_was_unticked(): void
    {
        $kit = $this->kit(['whole_set_only' => true]);
        $glass = $this->slot($kit, ['kind' => 'product', 'product_id' => $this->glass->id, 'discount_percent' => 10]);
        $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 10]);

        $offer = app(Offer::class)->for($kit->fresh('slots'), $this->phone, [], [$glass->id]);

        $this->assertNotNull($offer, 'the case was declined, not missing');
        $this->assertCount(1, $offer['slots']);
    }

    /**
     * A kit breaks silently — an item with nothing in it is simply not drawn —
     * so the only way a shop ever hears about it is a statement somebody goes
     * and checks (PLAN §25.2/3).
     */
    public function test_an_item_with_nothing_behind_it_is_reported(): void
    {
        $kit = $this->kit();
        $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 10]);

        $this->assertSame([], $this->findings('kits.slots_stand_for_something'));

        $this->case->update(['status' => 'disabled']);

        $found = $this->findings('kits.slots_stand_for_something');

        $this->assertCount(1, $found);
        $this->assertSame(route('admin.kits.edit', $kit->id), $found[0]['url']);
        $this->assertSame('The product it names is disabled or deleted.', $found[0]['detail'][0]);
    }

    /**
     * And the statement the new tick makes necessary: turning it on is exactly
     * a decision to hide the card, so the shop is told when it fires.
     */
    public function test_a_kit_that_draws_nothing_on_its_own_product_is_reported(): void
    {
        $kit = $this->kit([
            'main_product_id' => $this->phone->id,
            'rules' => null,
            'whole_set_only' => true,
        ]);
        $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 10]);
        $this->slot($kit, ['kind' => 'product', 'product_id' => $this->glass->id, 'discount_percent' => 10]);

        $this->assertSame([], $this->findings('kits.kits_reach_their_page'));

        $this->glass->update(['status' => 'disabled']);

        $found = $this->findings('kits.kits_reach_their_page');

        $this->assertCount(1, $found);
        $this->assertSame('Nothing can be put in it on ":product".', $found[0]['detail'][0]);
    }

    /**
     * Run one of this module's invariants through the guard's own registry, so
     * the registration is under test and not only the method behind it.
     *
     * @return list<array<string, mixed>>
     */
    protected function findings(string $key): array
    {
        app(Kits::class)->flush();

        $check = app(\Torgash\Guard\Invariants\Invariants::class)->find($key);

        $this->assertNotNull($check, 'the check is registered');

        return collect(($check['check'])(50))->values()->all();
    }

    /**
     * The configuration a line carries when it was bought as an extended set:
     * which kit, and which product fills each slot. The cart hashes it into
     * the line's key, so two different builds of one set are two lines.
     *
     * @param  array<int, int>  $slots  slot id => product id
     * @return array<string, mixed>
     */
    protected function bought(Kit $kit, array $slots): array
    {
        return [Discounts::KEY => [
            'id' => (int) $kit->id,
            'slots' => collect($slots)->mapWithKeys(fn ($p, $s) => [(string) $s => (int) $p])->all(),
        ]];
    }

    protected function kit(array $attributes = []): Kit
    {
        $kit = Kit::create($attributes + [
            'admin_name' => 'k'.uniqid(),
            'name' => ['en' => 'Starter set'],
            'main_product_id' => null,
            'rules' => [['kind' => 'product_category', 'operator' => 'in', 'value' => [$this->phones->id]]],
            'rules_match' => 'all',
            'placement' => 'after_buy_box',
            // The kind that may give money away, which is what most of these
            // tests are about (PLAN §33.5 as revised 2026-09-20).
            'type' => 'extended',
            'enabled' => true,
        ]);

        app(Kits::class)->flush();

        return $kit;
    }

    /**
     * The admin's own screens, opened.
     *
     * A smoke test, and it earns its place: a Blade view that names a class it
     * has not imported compiles happily and dies at render, so the whole suite
     * stayed green while `/admin/kits` answered 500 (measured — it shipped).
     * Nothing else here opens these pages, and a screen nobody opens in a test
     * is a screen that breaks on the next rename.
     */
    public function test_the_admin_screens_open(): void
    {
        $kit = $this->kit();
        $this->slot($kit, ['kind' => 'product', 'product_id' => $this->case->id, 'discount_percent' => 25]);

        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/kits')->assertOk();
        $this->actingAs($admin)->get('http://localhost/admin/kits/create')->assertOk();
        $this->actingAs($admin)->get('http://localhost/admin/kits/'.$kit->id.'/edit')->assertOk();
    }

    /** …and saving from them keeps what the form says a kit is. */
    public function test_the_form_saves_the_type_and_the_layout(): void
    {
        $kit = $this->kit(['type' => 'extended', 'layout' => 'grid']);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->put('http://localhost/admin/kits/'.$kit->id, [
                'admin_name' => $kit->admin_name,
                'name' => 'Starter set',
                'placement' => 'before_details',
                'layout' => 'scroller',
                'type' => 'simple',
                'sort' => 0,
                'enabled' => 1,
            ])->assertRedirect();

        $kit->refresh();

        $this->assertSame('simple', $kit->type);
        $this->assertSame('scroller', $kit->layout);
        $this->assertSame('before_details', $kit->placement);
    }

    /**
     * A characteristic with its values, ready to be hung on products.
     *
     * @return array{0: Feature, 1: FeatureValue, ...}
     */
    protected function feature(string $code, array $values): array
    {
        $feature = Feature::create([
            'name' => ['en' => $code], 'code' => $code,
            'purpose' => 'filter', 'input_type' => 'select',
        ]);

        $made = [$feature];

        foreach ($values as $value) {
            $made[] = FeatureValue::create(['feature_id' => $feature->id, 'value' => ['en' => $value]]);
        }

        return $made;
    }

    /**
     * Hang values on a product. A list, not a map: the value already knows
     * which characteristic it belongs to, and an object cannot be an array key
     * anyway.
     *
     * @param  list<FeatureValue>  $values
     */
    protected function assign(Product $product, array $values): void
    {
        foreach ($values as $value) {
            DB::table('m_catalog_product_features')->insert([
                'product_id' => $product->id,
                'feature_id' => $value->feature_id,
                'feature_value_id' => $value->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function slot(Kit $kit, array $attributes): KitSlot
    {
        $slot = KitSlot::create($attributes + [
            'kit_id' => $kit->id,
            'kind' => 'product',
            'qty' => 1,
            'discount_type' => 'percent',
            'enabled' => true,
        ]);

        $kit->load('slots');
        app(Kits::class)->flush();

        return $slot;
    }

    protected function product(string $sku, string $name, int $price, Category $category): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => 20, 'status' => 'active',
            'availability_status' => 'in_stock',
        ]);

        $product->categories()->attach($category->id);

        return $product;
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
