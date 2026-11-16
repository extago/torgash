<?php

namespace Tests\Feature\Points;

use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Giftcards\Issuer;
use Torgash\Giftcards\Models\GiftProduct;
use Torgash\Points\Earning;
use Torgash\Points\Ledger;
use Torgash\Points\Models\OrderPoints;
use Torgash\Points\Models\Rule;
use Torgash\Points\Rules;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * The rules of the points programme (PLAN §74): a product's own answer, then
 * the first rule from the top that fits and speaks of the question, then the
 * settings — for what goods earn and for how much of them points pay.
 */
class PointsRulesTest extends TestCase
{
    protected User $customer;

    protected PaymentMethod $cash;

    protected Product $table;

    protected Product $sofa;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->customer = User::factory()->create();
        $this->cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -1]);

        $this->table = Product::create(['sku' => 'PR-1', 'name' => ['en' => 'Table'], 'slug' => 'pr-table', 'price' => 2000_00, 'stock' => 10, 'status' => 'active']);
        $this->sofa = Product::create(['sku' => 'PR-2', 'name' => ['en' => 'Sofa'], 'slug' => 'pr-sofa', 'price' => 60000_00, 'stock' => 10, 'status' => 'active']);
    }

    public function test_a_rule_by_price_halves_what_expensive_goods_earn(): void
    {
        $this->rule(['price_min' => 50000_00], earn: ['multiplier', 0.5]);

        $this->assertSame(20, $this->earns($this->table), 'under the price: the rate of the shop');
        $this->assertSame(300, $this->earns($this->sofa), 'half of 600');

        $order = $this->buy(['pr-table', 'pr-sofa']);

        $this->assertSame(320, OrderPoints::find($order->id)->earn);
    }

    public function test_the_first_rule_from_the_top_that_fits_decides(): void
    {
        $category = Category::create(['name' => ['en' => 'Sofas'], 'slug' => 'pr-sofas']);
        $this->sofa->categories()->attach($category->id);

        $this->rule(['categories' => [$category->id]], earn: ['multiplier', 2], position: 0);
        $half = $this->rule(['price_min' => 50000_00], earn: ['multiplier', 0.5], position: 1);

        $this->assertSame(1200, $this->earns($this->sofa));

        $half->update(['position' => -1]);

        $this->assertSame(300, $this->earns($this->sofa));
    }

    public function test_percent_fixed_and_nothing(): void
    {
        $rule = $this->rule(['products' => [$this->table->id]], earn: ['percent', 5]);
        $this->assertSame(100, $this->earns($this->table), '5% of 2 000');

        $rule->update(['earn_mode' => 'fixed', 'earn_value' => 77]);
        $this->assertSame(77, $this->earns($this->table));

        // A fixed number is given for every piece.
        $order = $this->buy(['pr-table'], qty: 2);
        $this->assertSame(154, OrderPoints::find($order->id)->earn);

        $rule->update(['earn_mode' => 'none', 'earn_value' => null]);
        $this->assertSame(0, $this->earns($this->table));
        $this->assertSame(600, $this->earns($this->sofa), 'the rule names one product');
    }

    public function test_the_own_answer_of_a_product_beats_every_rule(): void
    {
        $this->rule([], earn: ['none', null]);
        DB::table('m_points_products')->insert(['product_id' => $this->table->id, 'earn_mode' => 'multiplier', 'earn_value' => 3]);

        $this->assertSame(60, $this->earns($this->table));
        $this->assertSame(0, $this->earns($this->sofa));
    }

    public function test_a_group_multiplies_whatever_the_rules_came_to(): void
    {
        $vip = CustomerGroup::create(['name' => ['en' => 'VIP']]);
        $this->customer->forceFill(['customer_group_id' => $vip->id])->save();
        DB::table('m_points_groups')->insert(['group_id' => $vip->id, 'multiplier' => 2]);
        $this->rule(['price_min' => 50000_00], earn: ['multiplier', 0.5]);

        $this->assertSame(600, $this->earns($this->sofa, $this->customer));
        $this->assertSame(300, $this->earns($this->sofa));
    }

    public function test_a_rule_for_a_customer_group_is_for_nobody_else(): void
    {
        $vip = CustomerGroup::create(['name' => ['en' => 'VIP']]);
        $this->rule(['groups' => [$vip->id]], earn: ['multiplier', 10]);

        $this->assertSame(20, $this->earns($this->table, $this->customer));

        $this->customer->forceFill(['customer_group_id' => $vip->id])->save();

        $this->assertSame(200, $this->earns($this->table, $this->customer));
    }

    public function test_points_do_not_pay_for_goods_a_rule_forbids(): void
    {
        app(Ledger::class)->adjust($this->customer->id, 100000, 'Lots');
        $this->rule(['price_min' => 50000_00], spend: ['forbidden', null]);

        $this->actingAs($this->customer)->post('http://localhost/cart/add/pr-table', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/add/pr-sofa', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/points', ['points' => '100000']);

        $this->actingAs($this->customer)->get('http://localhost/cart')->assertOk()
            ->assertSee(__('Some of these goods cannot be paid with points, or only in part.'));

        $this->assertSame(1000_00, $this->place()->discount_amount, 'half of the table, nothing of the sofa');
    }

    public function test_a_rule_can_give_goods_their_own_share(): void
    {
        app(Ledger::class)->adjust($this->customer->id, 100000, 'Lots');
        $this->rule(['products' => [$this->sofa->id]], spend: ['limit', 10]);

        $this->actingAs($this->customer)->post('http://localhost/cart/add/pr-table', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/add/pr-sofa', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/points', ['points' => '100000']);

        $this->assertSame(1000_00 + 6000_00, $this->place()->discount_amount);
    }

    public function test_with_paying_off_by_default_only_what_is_allowed_is_paid(): void
    {
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.points', 'spend_default', false);
        app(Ledger::class)->adjust($this->customer->id, 100000, 'Lots');
        DB::table('m_points_products')->insert(['product_id' => $this->table->id, 'spend_mode' => 'allowed']);

        $this->actingAs($this->customer)->post('http://localhost/cart/add/pr-table', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/add/pr-sofa', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/points', ['points' => '100000']);

        $this->assertSame(1000_00, $this->place()->discount_amount);
    }

    public function test_a_rule_about_earning_does_not_settle_paying(): void
    {
        $this->rule(['products' => [$this->table->id]], earn: ['multiplier', 2], position: 0);
        $this->rule([], spend: ['forbidden', null], position: 1);

        $this->assertSame(40, $this->earns($this->table));
        $this->assertSame(0, app(Rules::class)->spend($this->table->id, 2000_00, null)['percent']);
    }

    public function test_a_gift_card_pays_like_money_and_buying_one_earns_nothing(): void
    {
        // Paid in part with a card: the order earns in full.
        [, $code] = app(Issuer::class)->issue(1000_00);
        $this->actingAs($this->customer)->post('http://localhost/cart/add/pr-table', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/coupon', ['code' => $code]);
        $this->assertSame(20, OrderPoints::find($this->place()->id)->earn);

        // The card itself is outside the programme.
        $card = Product::create(['sku' => 'PR-GC', 'name' => ['en' => 'Gift card'], 'slug' => 'pr-card', 'price' => 500_00, 'stock' => 10, 'status' => 'active']);
        GiftProduct::create(['product_id' => $card->id, 'free_amount' => false]);
        $this->app->forgetInstance(\Torgash\Giftcards\GiftConfig::class);

        $this->assertSame(0, $this->earns($card));
        $this->assertSame(0, app(Rules::class)->spend($card->id, 500_00, null)['percent']);
    }

    public function test_an_order_keeps_the_terms_it_was_placed_by(): void
    {
        $rule = $this->rule([], earn: ['multiplier', 2]);
        $order = $this->buy(['pr-table']);
        $this->assertSame(40, OrderPoints::find($order->id)->earn);

        $rule->update(['earn_value' => 10]);
        $this->forgetRules();
        app(\Torgash\Points\OrderFlow::class)->recalculated($order->fresh());

        $this->assertSame(40, OrderPoints::find($order->id)->earn);
    }

    public function test_a_narrower_rule_is_put_above_the_wider_and_a_dead_one_says_so(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $parent = Category::create(['name' => ['en' => 'Furniture'], 'slug' => 'pr-furniture']);
        $child = Category::create(['name' => ['en' => 'Tables'], 'slug' => 'pr-tables', 'parent_id' => $parent->id]);

        $this->actingAs($admin)->post('http://localhost/admin/points/rules', [
            'name' => 'Furniture', 'enabled' => '1', 'category_ids' => [$parent->id], 'earn_mode' => 'multiplier', 'earn_value' => '2',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('http://localhost/admin/points/rules', [
            'name' => 'Tables', 'enabled' => '1', 'category_ids' => [$child->id], 'earn_mode' => 'multiplier', 'earn_value' => '3',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Tables', 'Furniture'], Rule::orderBy('position')->pluck('name')->all());
        $this->assertSame([], Rules::dead(Rule::orderBy('position')->get()));

        // Dragged the wrong way round: the narrower one is out of reach, and one press puts it back.
        $tables = Rule::where('name', 'Tables')->firstOrFail();
        $furniture = Rule::where('name', 'Furniture')->firstOrFail();
        $this->actingAs($admin)->postJson('http://localhost/admin/points/rules/sort', ['ids' => [$furniture->id, $tables->id]])->assertOk();

        $this->assertSame([$tables->id], array_keys(Rules::dead(Rule::orderBy('position')->get())));
        $this->actingAs($admin)->get('http://localhost/admin/points/rules')->assertOk()->assertSee(__('Put it above'));

        $this->actingAs($admin)->post('http://localhost/admin/points/rules/'.$tables->id.'/raise');
        $this->assertSame(['Tables', 'Furniture'], Rule::orderBy('position')->pluck('name')->all());

        // A rule that says nothing is not a rule.
        $this->actingAs($admin)->post('http://localhost/admin/points/rules', ['name' => 'Empty', 'enabled' => '1'])->assertSessionHasErrors('earn_mode');
    }

    public function test_the_product_tab_says_what_it_earns_and_whose_word_that_is(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $rule = $this->rule(['price_min' => 50000_00], earn: ['multiplier', 0.5], name: 'Expensive goods');

        $this->actingAs($admin)->get('http://localhost/admin/catalog/products/'.$this->sofa->id.'/edit')->assertOk()
            ->assertSee('300')
            ->assertSee('Expensive goods')
            ->assertSee(route('admin.points.rules.edit', $rule->id), false);

        // Its own answer, saved from the tab, wins from then on.
        $this->actingAs($admin)->get('http://localhost/admin/points/rules/'.$rule->id.'/edit')->assertOk()->assertSee('Expensive goods');
        $this->actingAs($admin)->get('http://localhost/admin/points/rules/create')->assertOk();
        $this->actingAs($admin)->get('http://localhost/admin/points')->assertOk()->assertSee(__('Rules'));
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @param  array{0: string, 1: float|int|null}|null  $earn
     * @param  array{0: string, 1: int|null}|null  $spend
     */
    protected function rule(array $conditions, ?array $earn = null, ?array $spend = null, int $position = 0, ?string $name = null): Rule
    {
        $rule = Rule::create([
            'name' => $name,
            'position' => $position,
            'conditions' => $conditions,
            'earn_mode' => $earn[0] ?? 'default',
            'earn_value' => $earn[1] ?? null,
            'spend_mode' => $spend[0] ?? 'default',
            'spend_percent' => $spend[1] ?? null,
        ]);
        $this->forgetRules();

        return $rule;
    }

    /** The memo is one request's: a test that edits rules between questions starts it again. */
    protected function forgetRules(): void
    {
        $this->app->forgetInstance(Rules::class);
        $this->app->forgetInstance(Earning::class);
    }

    protected function earns(Product $product, ?User $user = null): int
    {
        $this->forgetRules();

        return app(Earning::class)->forPrice($product, (int) $product->price, $user);
    }

    /** @param  list<string>  $slugs */
    protected function buy(array $slugs, int $qty = 1): Order
    {
        foreach ($slugs as $slug) {
            $this->actingAs($this->customer)->post('http://localhost/cart/add/'.$slug, ['qty' => $qty]);
        }

        return $this->place();
    }

    protected function place(): Order
    {
        $this->actingAs($this->customer)->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => $this->customer->email,
            'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }
}
