<?php

namespace Tests\Feature\Automations;

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Automations\Models\ProductBatch;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\ProductWatch;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * Rules about the shelf itself (PLAN §64.2–64.5): a product running low,
 * running out, coming back, changing its price — as CROSSINGS, worked out once
 * per act, so that many products at once are one message; narrowed by the
 * rule's conditions; not repeated within its quiet period.
 */
class ProductEventsTest extends TestCase
{
    /** @var list<string> every message "sent" to Telegram, in order */
    protected array $sent = [];

    protected Product $boots;

    protected Product $hat;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (HttpRequest $request) {
            $this->sent[] = (string) ($request->data()['text'] ?? '');

            return Http::response(['ok' => true], 200);
        });

        $this->boots = Product::create(['sku' => 'PE-BOOTS', 'name' => ['en' => 'Boots'], 'slug' => 'pe-boots', 'price' => 100_00, 'stock' => 5, 'status' => 'active', 'low_stock_threshold' => 2]);
        $this->hat = Product::create(['sku' => 'PE-HAT', 'name' => ['en' => 'Hat'], 'slug' => 'pe-hat', 'price' => 20_00, 'stock' => 5, 'status' => 'active', 'low_stock_threshold' => 2]);
        // Creating them was an act of its own, over before the test begins.
        app(ProductWatch::class)->flush();
    }

    public function test_the_line_is_told_when_it_is_crossed_and_not_at_every_sale_below_it(): void
    {
        $this->rule(ProductWatch::LOW, '{{ product.sku }} low: {{ product.stock }} (was {{ product.stock_before }})');
        $this->rule(ProductWatch::OUT, '{{ product.sku }} out');
        $this->rule(ProductWatch::RESTOCKED, '{{ product.sku }} back: {{ product.stock }}');

        $this->act(fn () => $this->boots->adjustStock(-2));
        $this->assertSame([], $this->sent, '3 left: above its line of 2');

        $this->act(fn () => $this->boots->fresh()->adjustStock(-1));
        $this->assertSame(['PE-BOOTS low: 2 (was 3)'], $this->sent);

        $this->act(fn () => $this->boots->fresh()->adjustStock(-1));
        $this->assertCount(1, $this->sent, 'already low: a sale below the line is not the line crossed again');

        $this->act(fn () => $this->boots->fresh()->adjustStock(-1));
        $this->assertSame('PE-BOOTS out', $this->sent[1]);

        $this->act(fn () => $this->boots->fresh()->adjustStock(8));
        $this->assertSame('PE-BOOTS back: 8', $this->sent[2]);
        $this->assertCount(3, $this->sent);
    }

    public function test_many_products_in_one_act_are_one_message(): void
    {
        $this->rule(ProductWatch::OUT, "Out ({{ count }}):\n{{#products}}{{ item.sku }}={{ item.stock }};{{/products}}\n{{ products_text }}");

        $this->act(function () {
            $this->boots->adjustStock(-5);
            $this->hat->adjustStock(-5);
        });

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Out (2):', $this->sent[0]);
        $this->assertStringContainsString('PE-BOOTS=0;PE-HAT=0;', $this->sent[0]);
        $this->assertStringContainsString('PE-HAT Hat', $this->sent[0]);
        $this->assertSame(1, ProductBatch::count());
    }

    public function test_a_number_set_and_put_back_within_one_act_is_no_event(): void
    {
        $this->rule(ProductWatch::OUT, 'out');
        $this->rule(ProductWatch::RESTOCKED, 'back');

        $this->act(function () {
            $this->boots->adjustStock(-5);
            $this->boots->fresh()->adjustStock(5);
        });

        $this->assertSame([], $this->sent);
    }

    public function test_a_new_products_first_stock_is_not_a_shelf_filled_again(): void
    {
        $this->rule(ProductWatch::RESTOCKED, 'back');
        $this->rule(ProductWatch::PRICE, 'price');

        $this->act(fn () => Product::create(['sku' => 'PE-NEW', 'name' => ['en' => 'New'], 'slug' => 'pe-new', 'price' => 10_00, 'stock' => 7, 'status' => 'active']));

        $this->assertSame([], $this->sent);
    }

    public function test_nobody_asked_nothing_is_kept(): void
    {
        $this->act(fn () => $this->boots->adjustStock(-5));

        $this->assertSame(0, ProductBatch::count());
    }

    public function test_a_condition_narrows_the_list_to_what_the_rule_asked_about(): void
    {
        $shoes = Category::create(['name' => ['en' => 'Shoes'], 'slug' => 'pe-shoes']);
        $this->boots->categories()->attach($shoes->id);

        $rule = $this->rule(ProductWatch::OUT, '{{ count }}: {{#products}}{{ item.sku }} {{/products}}');
        $rule->conditions()->create(['kind' => 'category', 'operator' => 'in', 'value' => ['ids' => [$shoes->id]], 'position' => 0]);

        $this->act(function () {
            $this->boots->adjustStock(-5);
            $this->hat->adjustStock(-5);
        });

        $this->assertSame(['1: PE-BOOTS'], $this->sent);

        // Nothing of the batch fits: the rule says nothing, and the log says why.
        $this->act(fn () => $this->boots->fresh()->adjustStock(5));
        $this->act(fn () => $this->hat->fresh()->adjustStock(5));
        $this->act(fn () => $this->hat->fresh()->adjustStock(-5));
        $this->assertCount(1, $this->sent);
        $this->assertSame(RuleRun::SKIPPED, RuleRun::latest('id')->firstOrFail()->status);
    }

    public function test_a_quiet_period_is_kept_per_product(): void
    {
        $rule = $this->rule(ProductWatch::OUT, '{{#products}}{{ item.sku }} {{/products}}');
        $rule->update(['quiet_minutes' => 24 * 60]);

        $this->act(fn () => $this->boots->adjustStock(-5));
        $this->act(fn () => $this->boots->fresh()->adjustStock(5));

        // Out again within the day — together with one that was not told yet.
        $this->act(function () {
            $this->boots->fresh()->adjustStock(-5);
            $this->hat->adjustStock(-5);
        });

        $this->assertSame(['PE-BOOTS', 'PE-HAT'], $this->sent);

        $this->travel(25)->hours();
        $this->act(fn () => $this->boots->fresh()->adjustStock(5));
        $this->act(fn () => $this->boots->fresh()->adjustStock(-5));
        $this->assertSame('PE-BOOTS', $this->sent[2]);
    }

    public function test_a_price_changed_says_what_it_was_and_which_way_it_went(): void
    {
        $down = $this->rule(ProductWatch::PRICE, 'down: {{ product.sku }} {{ product.price_before }} → {{ product.price }}');
        $down->conditions()->create(['kind' => 'price_went', 'operator' => 'down', 'value' => [], 'position' => 0]);

        $this->act(fn () => $this->boots->update(['price' => 120_00]));
        $this->assertSame([], $this->sent, 'it went up');

        $this->act(fn () => $this->boots->update(['price' => 90_00]));
        $this->assertSame(['down: PE-BOOTS 120.00 → 90.00'], $this->sent);

        // Typed and typed back within one act: no change, no message.
        $this->act(function () {
            $this->boots->update(['price' => 50_00]);
            $this->boots->update(['price' => 90_00]);
        });
        $this->assertCount(1, $this->sent);
    }

    public function test_a_shelf_emptied_from_the_admin_is_told_when_the_request_ends(): void
    {
        $this->rule(ProductWatch::OUT, '{{ products_text }}');

        $this->actingAs(User::role('admin')->firstOrFail())->post('http://localhost/admin/catalog/products/bulk', [
            'action' => 'edit', 'ids' => [$this->boots->id, $this->hat->id], 'stock_mode' => 'set', 'stock_value' => 0,
        ])->assertRedirect();

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('PE-BOOTS Boots', $this->sent[0]);
        $this->assertStringContainsString('PE-HAT Hat', $this->sent[0]);
    }

    public function test_left_for_fewer_days_than_it_sells_is_low_whatever_the_number(): void
    {
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.automations', 'low_stock_days', 7);
        hook_listen('catalog.products.sold', fn (array $sold, array $context) => $sold + [$this->boots->id => 40]);
        $this->boots->update(['stock' => 50]);
        $this->rule(ProductWatch::LOW, '{{ product.sku }} {{ product.stock }}');

        $this->act(fn () => $this->boots->fresh()->adjustStock(-10));
        $this->assertSame([], $this->sent, '40 left, 40 sold in the week: still enough');

        $this->act(fn () => $this->boots->fresh()->adjustStock(-1));
        $this->assertSame(['PE-BOOTS 39'], $this->sent);
    }

    public function test_the_forms_keep_the_products_own_line_and_the_rules_quiet_period(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/catalog/products/'.$this->boots->id.'/edit')->assertOk()
            ->assertSee('name="low_stock_threshold"', false);

        $this->actingAs($admin)->post('http://localhost/admin/automations', [
            'name' => 'Shelf', 'event' => ProductWatch::OUT, 'enabled' => '1', 'quiet_hours' => '24',
            'actions' => [['type' => 'telegram', 'token' => '1:a', 'chat_id' => '1', 'text' => 'x']],
        ])->assertSessionHasNoErrors();

        $rule = Rule::where('name', 'Shelf')->firstOrFail();
        $this->assertSame(1440, $rule->quiet_minutes);
        $this->assertSame('product', $rule->subjectKey());

        $this->actingAs($admin)->get('http://localhost/admin/automations/'.$rule->id.'/edit')->assertOk()
            ->assertSee(__('A product runs out'))
            ->assertSee('name="quiet_hours"', false);

        // "Test" has something to show even in a shop where nothing happened yet.
        $this->actingAs($admin)->post('http://localhost/admin/automations/'.$rule->id.'/test')->assertRedirect();
        $this->assertSame(['x'], $this->sent);
    }

    /** One act: whatever happens inside is looked at once, when it is over. */
    protected function act(callable $work): void
    {
        $work();
        app(ProductWatch::class)->flush();
    }

    protected function rule(string $event, string $text): Rule
    {
        $rule = Rule::create(['name' => $event, 'event' => $event, 'enabled' => true, 'delay_minutes' => 0]);
        $rule->actions()->create(['type' => 'telegram', 'config' => ['token' => '1:a', 'chat_id' => '1', 'text' => $text], 'position' => 0]);

        return $rule;
    }
}
