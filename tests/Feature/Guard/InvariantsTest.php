<?php

namespace Tests\Feature\Guard;

use App\Core\Settings\SettingsManager;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Guard\Invariants\Invariants;
use Torgash\Guard\Models\CheckState;
use Torgash\Guard\Models\Finding;

/**
 * The third part of the guard: statements about the shop that must be true.
 *
 * The engine's own rules are what most of this pins, because they are where it
 * would quietly become useless: an invariant is a STATE, so what has been put
 * right must disappear by itself; a run that stopped at its limit must not
 * delete what it never looked at; a check that threw must not be read as "all
 * clear". Then the real statements, because a check that does not actually
 * catch the bug it was written for is decoration.
 */
class InvariantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.guard', 'invariants_limit', 50);

        Finding::query()->delete();
        CheckState::query()->delete();
    }

    /** Registers one throwaway statement and nothing else. */
    protected function only(string $key, callable $check, array $extra = []): Invariants
    {
        hook_listen('guard.invariants', fn (array $all) => [$key => $extra + [
            'label' => ['en' => 'A test statement'],
            'check' => $check,
        ]]);

        return app(Invariants::class);
    }

    protected function violations(int $n): callable
    {
        return fn () => collect(range(1, $n))->map(fn ($i) => [
            'id' => (string) $i,
            'title' => 'Thing '.$i,
            'detail' => 'wrong',
        ])->all();
    }

    // ------------------------------------------------------------ the engine

    public function test_a_broken_statement_is_recorded_with_what_is_wrong(): void
    {
        $this->only('test.one', $this->violations(2))->run();

        $this->assertSame(2, Finding::where('key', 'test.one')->count());
        $this->assertSame('Thing 1', Finding::where('entity_id', '1')->value('title'));
        $this->assertSame(2, CheckState::find('test.one')->violations);
    }

    /**
     * The decision that separates this from the errors screen: an invariant is
     * a state, not an event. Something that has been put right stops being on
     * the list on its own — nobody has to tick it off, and an empty screen
     * means the shop is sound rather than tidied.
     */
    public function test_what_has_been_put_right_disappears(): void
    {
        $engine = app(Invariants::class);

        // An object, not a bool: `fn ()` captures by value, so a plain variable
        // would still say "broken" on the second run.
        $shop = new \stdClass;
        $shop->broken = true;

        hook_listen('guard.invariants', fn () => ['test.one' => [
            'label' => ['en' => 'A test statement'],
            'check' => fn () => $shop->broken ? [['id' => '1', 'title' => 'Thing 1']] : [],
        ]]);

        $engine->run();
        $this->assertSame(1, Finding::count());

        $shop->broken = false;
        $engine->run();

        $this->assertSame(0, Finding::count());
    }

    /**
     * ...but only when the run actually saw everything. A check that stopped at
     * its limit knows nothing about what lies beyond it, and deleting on that
     * basis would clear problems it never looked at.
     */
    public function test_a_capped_run_deletes_nothing_and_says_it_is_capped(): void
    {
        app(SettingsManager::class)->set('torgash.guard', 'invariants_limit', 2);

        Finding::create([
            'key' => 'test.one',
            'entity_id' => '99',
            'title' => 'Found by an earlier, complete run',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->only('test.one', $this->violations(10))->run();

        $this->assertTrue(CheckState::find('test.one')->truncated);
        $this->assertSame(2, CheckState::find('test.one')->violations);
        $this->assertNotNull(Finding::where('entity_id', '99')->first());
    }

    /**
     * A check that blew up is recorded as having blown up — never as "all
     * clear", which is what silently swallowing it would have shown — and what
     * it reported last night is left exactly where it was.
     */
    public function test_a_check_that_throws_is_recorded_and_keeps_its_findings(): void
    {
        Finding::create([
            'key' => 'test.one',
            'entity_id' => '1',
            'title' => 'From yesterday',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->only('test.one', fn () => throw new RuntimeException('the check is broken'))->run();

        $this->assertStringContainsString('broken', CheckState::find('test.one')->error);
        $this->assertSame(1, Finding::count());
    }

    /**
     * "We know, and it is fine" hides one row, not the statement — and it
     * survives the next run, or it would be a button that does nothing until
     * tomorrow morning.
     */
    public function test_an_exemption_survives_a_re_run(): void
    {
        $engine = $this->only('test.one', $this->violations(1));
        $engine->run();

        Finding::first()->update(['ignored_at' => now()]);

        $engine->run();

        $this->assertNotNull(Finding::first()->ignored_at);
        $this->assertSame(0, Finding::live()->count());
    }

    /**
     * The bug this pins was found on the live screen: the nightly run speaks
     * the application's default language, so a finished sentence stored by it
     * reads in that language for ever, under a Russian heading. The rule is the
     * one the background tasks already live by — a key plus its parameters.
     */
    public function test_a_finding_is_stored_as_a_key_and_read_in_the_reader_s_language(): void
    {
        $this->only('test.one', fn () => [[
            'id' => 'shop',
            'title' => ['Groups marked as default: :n', ['n' => 0]],
        ]])->run();

        $finding = Finding::firstOrFail();

        $this->assertSame('Groups marked as default: :n', $finding->title);
        $this->assertSame(['n' => 0], $finding->title_params);

        app()->setLocale('ru');
        $this->assertStringContainsString('умолчанию', $finding->titleText());

        app()->setLocale('en');
        $this->assertSame('Groups marked as default: 0', $finding->titleText());
    }

    /** First seen is when it FIRST went wrong, not when it was last looked at. */
    public function test_first_seen_is_not_moved_by_a_later_run(): void
    {
        $engine = $this->only('test.one', $this->violations(1));
        $engine->run();

        Finding::first()->update(['first_seen_at' => now()->subDays(3)]);
        $engine->run();

        $this->assertSame(3, (int) Finding::first()->first_seen_at->diffInDays(now()));
    }

    // ------------------------------------------------------- the real ones

    /**
     * The statement the whole part was argued from. It is checked against
     * `OrderTotals` itself, so there is no second formula here to drift from
     * the one that writes the column.
     */
    public function test_an_order_whose_total_does_not_add_up_is_found(): void
    {
        $order = $this->order(['total' => 999_99]);

        app(Invariants::class)->run('checkout.order_arithmetic');

        $finding = Finding::where('key', 'checkout.order_arithmetic')->firstOrFail();

        $this->assertSame((string) $order->id, $finding->entity_id);
        $this->assertSame($order->order_number, $finding->title);
    }

    public function test_a_correct_order_is_not_reported(): void
    {
        $this->order();

        app(Invariants::class)->run('checkout.order_arithmetic');

        $this->assertSame(0, Finding::count());
    }

    public function test_a_negative_total_is_found(): void
    {
        $this->order(['subtotal' => -2000, 'total' => -2000]);

        app(Invariants::class)->run('checkout.order_amounts_not_negative');

        $this->assertSame(1, Finding::where('key', 'checkout.order_amounts_not_negative')->count());
    }

    /** Two axes that are allowed to differ, which is exactly why they drift. */
    public function test_an_order_marked_paid_with_no_money_recorded_is_found(): void
    {
        $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 0]);

        app(Invariants::class)->run('checkout.paid_orders_have_money');

        $this->assertSame(1, Finding::where('key', 'checkout.paid_orders_have_money')->count());
    }

    public function test_negative_stock_in_a_warehouse_is_found(): void
    {
        $warehouse = DB::table('m_warehouses')->orderBy('id')->first();

        $product = Product::create([
            'sku' => 'INV-STOCK',
            'name' => ['en' => 'Stock check'],
            'slug' => 'inv-stock',
            'price' => 10_00,
            'stock' => -3,
            'status' => 'active',
        ]);

        DB::table('m_warehouse_product_stock')->updateOrInsert(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['qty' => -3],
        );

        app(Invariants::class)->run('warehouses.stock_not_negative');

        $this->assertSame(1, Finding::where('key', 'warehouses.stock_not_negative')->count());
    }

    /**
     * Core's own data, which is what this module speaks for — nobody else can,
     * and a shop with no default group puts every new customer nowhere.
     */
    public function test_a_shop_with_no_default_customer_group_is_told(): void
    {
        CustomerGroup::query()->update(['is_default' => false]);

        app(Invariants::class)->run('core.one_default_customer_group');

        $finding = Finding::where('key', 'core.one_default_customer_group')->firstOrFail();

        $this->assertSame('shop', $finding->entity_id);
    }

    // ------------------------------------------------------------- the screen

    public function test_the_screen_lists_every_statement_and_can_run_them(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)
            ->get('http://localhost/admin/guard/invariants')
            ->assertOk()
            ->assertSee('checkout.order_arithmetic')
            // A sound statement is listed too: "nothing is wrong" and "nothing
            // was ever checked" have to be different answers.
            ->assertSee('Not checked yet');

        $this->actingAs($admin)
            ->post('http://localhost/admin/guard/invariants/run')
            ->assertRedirect();

        $this->assertNotNull(CheckState::find('checkout.order_arithmetic'));
    }

    /**
     * The tabs are the summary, so each has to hold exactly what its count
     * says: a broken statement under "Broken", never under "Sound", and a
     * finding marked fine only under "Marked fine".
     */
    public function test_the_tabs_and_the_search_narrow_the_list(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $this->only('test.one', $this->violations(2))->run();

        $this->actingAs($admin)
            ->get('http://localhost/admin/guard/invariants?tab=broken')
            ->assertOk()
            ->assertSee('test.one')
            ->assertSee('Thing 2');

        $this->actingAs($admin)
            ->get('http://localhost/admin/guard/invariants?tab=sound')
            ->assertOk()
            ->assertDontSee('test.one');

        $this->actingAs($admin)
            ->get('http://localhost/admin/guard/invariants?q=Thing+1')
            ->assertOk()
            ->assertSee('test.one');

        $this->actingAs($admin)
            ->get('http://localhost/admin/guard/invariants?q=nothing-like-it')
            ->assertOk()
            ->assertDontSee('test.one');
    }

    public function test_ticked_findings_are_marked_fine_and_brought_back_together(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $this->only('test.one', $this->violations(3))->run();
        $ids = Finding::orderBy('entity_id')->limit(2)->pluck('id')->all();

        $this->actingAs($admin)
            ->post('http://localhost/admin/guard/invariants/findings/bulk', ['action' => 'ignore', 'ids' => $ids])
            ->assertRedirect();

        $this->assertSame(1, Finding::live()->count());

        $this->actingAs($admin)
            ->get('http://localhost/admin/guard/invariants?tab=hidden')
            ->assertOk()
            ->assertSee('Thing 1')
            ->assertDontSee('Thing 3');

        $this->actingAs($admin)
            ->post('http://localhost/admin/guard/invariants/findings/bulk', ['action' => 'unignore', 'ids' => $ids])
            ->assertRedirect();

        $this->assertSame(3, Finding::live()->count());
    }

    /** @param array<string, mixed> $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create($overrides + [
            'order_number' => 'TG-IV'.random_int(1000, 9999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'USD',
            'subtotal' => 0,
            'shipping_cost' => 0,
            'total' => 0,
            'status' => 'new',
            'payment_state' => PaymentState::PENDING,
            'items' => [],
        ]);
    }
}
