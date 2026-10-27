<?php

namespace Tests\Feature\Guard;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Automations\Models\Rule;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Guard\Invariants\Invariants;
use Torgash\Taxes\Models\Tax;
use Torgash\Guard\Models\CheckState;
use Torgash\Guard\Models\Finding;
use Torgash\Payments\Models\Binding;
use Torgash\Reviews\Models\Review;
use Torgash\Rma\Models\ReturnRequest;
use Torgash\Rma\State;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\Zone;

/**
 * The statements added on 2026-09-20, one test each.
 *
 * They are all of one kind — the shop stops working and says nothing. A
 * delivery method nobody can pick, a Pay button wired to a gateway that has
 * been uninstalled, an average that no longer matches the reviews under it, a
 * rule waiting for an event nobody announces, a return claiming more than was
 * bought. Every one of those leaves every admin screen looking correct, which
 * is exactly why a statement is the only thing that can catch it — and why
 * each one needs a test that breaks the shop for real rather than calling the
 * closure with a fixture.
 */
class InvariantCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.guard', 'invariants_limit', 50);

        Finding::query()->delete();
        CheckState::query()->delete();
    }

    /** @return list<Finding> */
    protected function findings(string $key): array
    {
        app(Invariants::class)->run($key);

        return Finding::where('key', $key)->get()->all();
    }

    // ---------------------------------------------------------------- taxes

    /**
     * "Only where listed" with nothing listed at a rate is a tax charged
     * nowhere; a row saying "not charged here" does not rescue it, and one
     * row at a rate does.
     */
    public function test_a_tax_charged_nowhere_is_found(): void
    {
        $tax = Tax::create([
            'code' => 'guard-nexus',
            'name' => ['en' => 'Sales tax'],
            'rate_type' => Tax::PERCENT,
            'rate_percent' => 5,
            'price_includes_tax' => false,
            'only_listed' => true,
            'enabled' => true,
        ]);
        $tax->rates()->create(['kind' => 'state', 'value' => 'US:OR', 'charged' => false]);

        $this->assertSame([(string) $tax->id], array_map(fn ($f) => $f->entity_id, $this->findings('taxes.charged_somewhere')));

        $tax->rates()->create(['kind' => 'state', 'value' => 'US:WA', 'charged' => true, 'rate_percent' => 6.5]);

        $this->assertSame([], $this->findings('taxes.charged_somewhere'));
    }

    // ------------------------------------------------------------- delivery

    /**
     * The zone gate: a method is offered where it has a rate, so one with no
     * rate anywhere is offered at no address at all.
     */
    public function test_a_delivery_method_offered_nowhere_is_found(): void
    {
        $method = ShippingMethod::create(['name' => ['en' => 'Orphan courier'], 'position' => 90, 'enabled' => true]);

        $found = $this->findings('shipping.methods_can_be_offered');

        $this->assertCount(1, $found);
        $this->assertSame((string) $method->id, $found[0]->entity_id);
        $this->assertSame('Orphan courier', $found[0]->title);
    }

    /** ...and one with a rate in a live zone is not reported. */
    public function test_a_delivery_method_with_a_rate_is_sound(): void
    {
        $zone = Zone::create(['name' => ['en' => 'Everywhere'], 'position' => 0, 'enabled' => true]);
        $zone->rules()->create(['kind' => 'country', 'value' => '*']);

        $method = ShippingMethod::create(['name' => ['en' => 'Real courier'], 'position' => 90, 'enabled' => true]);
        $method->rates()->create(['zone_id' => $zone->id, 'base_rate' => 5_00, 'enabled' => true]);

        $this->assertSame([], $this->findings('shipping.methods_can_be_offered'));
    }

    /** A zone is the places it names, and ZoneMatcher only ever reads those rules. */
    public function test_a_zone_that_covers_nothing_is_found(): void
    {
        $zone = Zone::create(['name' => ['en' => 'Empty zone'], 'position' => 0, 'enabled' => true]);

        $found = $this->findings('shipping.zones_cover_somewhere');

        $this->assertCount(1, $found);
        $this->assertSame((string) $zone->id, $found[0]->entity_id);
    }

    /** A carrier is a registry key, and a key outlives the module that answered for it. */
    public function test_a_method_naming_a_carrier_nobody_answers_for_is_found(): void
    {
        $method = ShippingMethod::create([
            'name' => ['en' => 'Ghost post'], 'position' => 90, 'enabled' => true, 'provider' => 'ghostpost',
        ]);

        $found = $this->findings('shipping.carriers_are_present');

        $this->assertCount(1, $found);
        $this->assertSame((string) $method->id, $found[0]->entity_id);
    }

    // -------------------------------------------------------------- payment

    public function test_a_method_bound_to_a_missing_gateway_is_found(): void
    {
        $method = PaymentMethod::create(['name' => ['en' => 'Card'], 'enabled' => true, 'position' => 0]);
        Binding::create(['payment_method_id' => $method->id, 'gateway' => 'gone', 'config' => []]);

        $found = $this->findings('payments.gateways_are_present');

        $this->assertCount(1, $found);
        $this->assertSame((string) $method->id, $found[0]->entity_id);
    }

    /**
     * The other statement stays quiet about it: one broken wire is one row on
     * the screen, or the shop fixes a finding and watches a second one stay.
     */
    public function test_a_missing_gateway_is_not_reported_twice(): void
    {
        $method = PaymentMethod::create(['name' => ['en' => 'Card'], 'enabled' => true, 'position' => 0]);
        Binding::create(['payment_method_id' => $method->id, 'gateway' => 'gone', 'config' => []]);

        $this->assertSame([], $this->findings('payments.gateways_can_charge'));
    }

    // -------------------------------------------------------------- reviews

    public function test_an_average_that_no_longer_matches_its_reviews_is_found(): void
    {
        $product = $this->product('INV-REV');
        $this->review($product, 5);
        $this->review($product, 3);

        // Sound to begin with: publishing recomputes the row.
        $this->assertSame([], $this->findings('reviews.aggregate_matches_reviews'));

        // Exactly what an import, a hand-written fix or a forgotten code path
        // leaves behind: reviews changed, the row did not.
        DB::table('m_reviews_reviews')->where('product_id', $product->id)->limit(1)->delete();

        $found = $this->findings('reviews.aggregate_matches_reviews');

        $this->assertCount(1, $found);
        $this->assertSame((string) $product->id, $found[0]->entity_id);
    }

    /** ...and the button on the reviews screen is what puts it right. */
    public function test_recounting_the_averages_clears_the_finding(): void
    {
        $product = $this->product('INV-REV2');
        $this->review($product, 5);
        $this->review($product, 3);
        DB::table('m_reviews_reviews')->where('product_id', $product->id)->limit(1)->delete();

        $this->assertCount(1, $this->findings('reviews.aggregate_matches_reviews'));

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/reviews/recount')
            ->assertRedirect();

        // A state, not an event: what has been put right leaves the screen by
        // itself on the next run.
        $this->assertSame([], $this->findings('reviews.aggregate_matches_reviews'));
    }

    public function test_a_helpfulness_counter_that_no_votes_support_is_found(): void
    {
        $product = $this->product('INV-REV3');
        $review = $this->review($product, 4);

        DB::table('m_reviews_reviews')->where('id', $review->id)->update(['helpful_yes' => 7]);

        $found = $this->findings('reviews.vote_counts_match');

        $this->assertCount(1, $found);
        $this->assertSame((string) $review->id, $found[0]->entity_id);
    }

    // ---------------------------------------------------------- automations

    public function test_a_rule_with_nothing_to_do_is_found(): void
    {
        $rule = Rule::create([
            'name' => 'Does nothing', 'enabled' => true,
            'event' => 'checkout.order_placed', 'delay_minutes' => 0,
        ]);

        $found = $this->findings('automations.rules_can_act');

        $this->assertCount(1, $found);
        $this->assertSame((string) $rule->id, $found[0]->entity_id);
    }

    public function test_a_rule_whose_action_nobody_answers_for_is_found(): void
    {
        $rule = Rule::create([
            'name' => 'Sends a pigeon', 'enabled' => true,
            'event' => 'checkout.order_placed', 'delay_minutes' => 0,
        ]);
        $rule->actions()->create(['type' => 'pigeon', 'config' => [], 'position' => 0]);

        $found = $this->findings('automations.rules_can_act');

        $this->assertCount(1, $found);
        $this->assertStringContainsString('pigeon', (string) $found[0]->detailText());
    }

    /** A trigger is a hook name, so it can stop being announced. */
    public function test_a_rule_waiting_for_an_event_nobody_announces_is_found(): void
    {
        $rule = Rule::create([
            'name' => 'Waits forever', 'enabled' => true,
            'event' => 'nobody.says.this', 'delay_minutes' => 0,
        ]);
        $rule->actions()->create(['type' => 'set_status', 'config' => [], 'position' => 0]);

        $found = $this->findings('automations.triggers_are_announced');

        $this->assertCount(1, $found);
        $this->assertSame((string) $rule->id, $found[0]->entity_id);
    }

    /** A rule the shop has switched off promises nothing, so it is not news. */
    public function test_a_disabled_rule_is_not_reported(): void
    {
        Rule::create([
            'name' => 'Off', 'enabled' => false,
            'event' => 'nobody.says.this', 'delay_minutes' => 0,
        ]);

        $this->assertSame([], $this->findings('automations.triggers_are_announced'));
        $this->assertSame([], $this->findings('automations.rules_can_act'));
    }

    // -------------------------------------------------------------- returns

    public function test_a_return_claiming_more_than_was_bought_is_found(): void
    {
        $product = $this->product('INV-RMA');

        $order = Order::create([
            'order_number' => 'TG-INV-1',
            'status' => 'new',
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'phone' => '+380000000000',
            'city' => 'Kyiv',
            'address_line' => 'Somewhere 1',
            'items' => [[
                'key' => (string) $product->id, 'product_id' => $product->id, 'sku' => $product->sku,
                'name' => 'Thing', 'qty' => 1, 'unit_price' => 10_00, 'line_total' => 10_00,
            ]],
            'subtotal' => 10_00,
            'total' => 10_00,
            'currency_code' => shop_currency(),
        ]);

        // A return always hangs off a ticket — it IS a kind of enquiry, which
        // is the whole design of the module.
        $enquiry = Enquiry::create(['kind' => 'return', 'status' => 'new']);

        // Two of something the order holds one of — what two returns approved
        // in two tabs, or an API caller, leaves behind.
        ReturnRequest::create([
            'order_id' => $order->id,
            'enquiry_id' => $enquiry->id,
            'state' => State::REQUESTED,
            'resolution' => ReturnRequest::REFUND,
            'lines' => [[
                'product_id' => $product->id, 'sku' => $product->sku,
                'qty' => 2, 'unit_price' => 10_00, 'line_total' => 20_00,
            ]],
        ]);

        $found = $this->findings('rma.returns_within_the_order');

        $this->assertCount(1, $found);
        $this->assertSame((string) $order->id, $found[0]->entity_id);
    }

    // -------------------------------------------------------------- the screen

    /**
     * The statements are grouped by whoever made them — with thirty of them a
     * flat list buries the two that matter — and the heading is that module's
     * own name.
     */
    public function test_the_screen_groups_the_statements_by_their_module(): void
    {
        $groups = app(Invariants::class)->grouped();

        $this->assertArrayHasKey('shipping', $groups);
        $this->assertArrayHasKey('reviews', $groups);
        $this->assertSame('Shipping', $groups['shipping']['label']);
        $this->assertArrayHasKey('shipping.methods_can_be_offered', $groups['shipping']['checks']);

        // Core has no module of its own and still gets a heading rather than
        // being dropped.
        $this->assertArrayHasKey('core', $groups);
    }

    // -------------------------------------------------------------- fixtures

    protected function product(string $sku): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => 'Thing '.$sku], 'slug' => strtolower($sku),
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);
    }

    protected function review(Product $product, int $stars): Review
    {
        $user = User::factory()->create();

        $review = Review::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'author_name' => 'Reviewer',
            'rating' => $stars,
            'status' => Review::PUBLISHED,
            'body' => 'Fine.',
        ]);

        app(\Torgash\Reviews\Aggregates::class)->recalculate((int) $product->id);

        return $review;
    }
}
