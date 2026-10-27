<?php

namespace Tests\Feature\Options;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Orders\OrderLines;
use Torgash\Options\Configurator;
use Torgash\Options\Groups;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;
use Torgash\Options\PriceRules;

/**
 * Options: what a product asks before it can be bought (PLAN §33.4).
 *
 * The rules worth pinning are the ones the owner's own examples turn on: a
 * shared question prices itself by FORMULA against whatever product it is
 * asked on (that is what makes it shareable at all, §33.8), a service folds
 * into the line's price while a product becomes a line of its own, a set of
 * products only ever sells what is actually in the set, and every price the
 * page quotes is the price the cart charges, because one class answers both.
 */
class OptionsTest extends TestCase
{
    protected Category $servers;

    protected Product $server;

    protected Product $cpu;

    protected function setUp(): void
    {
        parent::setUp();

        app(Groups::class)->flush();
        app(PriceRules::class)->flush();

        $this->servers = Category::create(['name' => ['en' => 'Servers'], 'slug' => 'servers', 'enabled' => true]);

        $this->server = $this->product('SRV-1', 'Server', 1000_00, 5);
        $this->server->categories()->attach($this->servers->id);

        $this->cpu = $this->product('CPU-1', 'Xeon', 170_00, 20);
    }

    public function test_a_service_is_priced_by_formula_and_folds_into_the_line(): void
    {
        // The owner's own example: 500 plus 10% of what is being insured.
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Warranty']]);
        $choice = $this->choice($group, ['kind' => 'plain', 'name' => ['en' => '+1 year'], 'price_rule' => 'fixed_plus_percent', 'amount' => 500_00, 'percent' => 10]);

        $cart = app(CartManager::class);
        $cart->add($this->server->id, 2, $this->answers($group, (string) $choice->id));
        $line = $cart->items()->first();

        $this->assertSame(1600_00, $line->unit_price);
        $this->assertSame(3200_00, $line->line_total);

        // One order line, with the service inside its price and named in its
        // own snapshot — printed once, not twice.
        $lines = OrderLines::fromBasket($cart->items());
        $this->assertCount(1, $lines);
        $this->assertSame(1600_00, $lines[0]['unit_price']);
        $this->assertSame('Warranty: +1 year', $lines[0]['options'][0]['label']);
    }

    /** A product answer is goods: its own line, its own stock, its own return. */
    public function test_a_product_answer_becomes_a_line_of_the_order(): void
    {
        $group = $this->group(['type' => 'quantities', 'name' => ['en' => 'Processor'], 'max_qty' => 2]);
        $choice = $this->choice($group, ['kind' => 'product', 'product_id' => $this->cpu->id, 'price_rule' => 'product_price']);

        $cart = app(CartManager::class);
        $cart->add($this->server->id, 2, $this->answers($group, [(string) $choice->id => 2]));

        $lines = OrderLines::fromBasket($cart->items());

        $this->assertCount(2, $lines);
        $this->assertSame(1000_00, $lines[0]['unit_price'], 'the server keeps its own price');
        $this->assertSame($this->cpu->id, $lines[1]['product_id']);
        // Two per server, two servers.
        $this->assertSame(4, $lines[1]['qty']);
        $this->assertSame(680_00, $lines[1]['line_total']);
        $this->assertSame($cart->total(), array_sum(array_column($lines, 'line_total')));
    }

    /** …and a service charged once for the line is not multiplied by anything. */
    public function test_a_once_per_line_service_is_charged_once(): void
    {
        $group = $this->group(['type' => 'checkboxes', 'name' => ['en' => 'Installation'], 'per_unit' => false]);
        $choice = $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Install it'], 'price_rule' => 'fixed', 'amount' => 120_00]);

        $cart = app(CartManager::class);
        $cart->add($this->server->id, 3, $this->answers($group, [(string) $choice->id]));
        $line = $cart->items()->first();

        $this->assertSame(1000_00, $line->unit_price);
        $this->assertSame(120_00, $line->extra_total);
        $this->assertSame(3120_00, $line->line_total);
    }

    /**
     * The whole point of a shared group: one formula, read against each
     * product's own price. Re-pricing it per product is what §33.8 refuses.
     */
    public function test_a_shared_group_reaches_a_category_and_prices_itself_per_product(): void
    {
        $other = $this->product('SRV-2', 'Bigger server', 5000_00, 3);
        $other->categories()->attach($this->servers->id);
        $outside = $this->product('KBD-1', 'Keyboard', 50_00, 9);

        $group = OptionGroup::create([
            'product_id' => null,
            'admin_name' => 'Warranty everywhere',
            'name' => ['en' => 'Warranty'],
            'type' => 'select',
            'rules' => [['kind' => 'product_category', 'operator' => 'in', 'value' => [$this->servers->id]]],
            'rules_match' => 'all',
            'enabled' => true,
        ]);
        $choice = $this->choice($group, ['kind' => 'plain', 'name' => ['en' => '+1 year'], 'price_rule' => 'percent', 'percent' => 10]);
        app(Groups::class)->flush();

        $this->assertTrue(app(Groups::class)->has($this->server));
        $this->assertTrue(app(Groups::class)->has($other));
        $this->assertFalse(app(Groups::class)->has($outside));

        $configurator = app(Configurator::class);
        $cheap = $configurator->parts($this->server, 1, $configurator->normalise($this->server, $this->answers($group, (string) $choice->id)));
        $dear = $configurator->parts($other, 1, $configurator->normalise($other, $this->answers($group, (string) $choice->id)));

        $this->assertSame(100_00, $cheap[0]['amount']);
        $this->assertSame(500_00, $dear[0]['amount']);

        // …and one product may refuse the question outright.
        $this->actingAs($this->admin())->post('http://localhost/admin/options/groups/'.$group->id.'/exclude', [
            'product_id' => $this->server->id,
        ])->assertRedirect();

        app(Groups::class)->flush();
        $this->assertFalse(app(Groups::class)->has($this->server->fresh()));
        $this->assertTrue(app(Groups::class)->has($other->fresh()));
    }

    /** A multiplier is an addition here, so two of them cannot compound. */
    public function test_the_price_rules_are_measured_against_the_untouched_price(): void
    {
        $group = $this->group(['type' => 'checkboxes', 'name' => ['en' => 'Extras']]);
        $one = $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Plus fifteen'], 'price_rule' => 'multiplier', 'percent' => 1.15]);
        $two = $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Plus ten'], 'price_rule' => 'percent', 'percent' => 10]);

        $cart = app(CartManager::class);
        $cart->add($this->server->id, 1, $this->answers($group, [(string) $one->id, (string) $two->id]));

        // 1000 + 150 + 100, never 1000 × 1.15 × 1.10.
        $this->assertSame(1250_00, $cart->items()->first()->line_total);
    }

    public function test_a_required_question_must_be_answered(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor'], 'required' => true]);
        $this->choice($group, ['kind' => 'product', 'product_id' => $this->cpu->id, 'price_rule' => 'product_price']);

        $this->expectException(ValidationException::class);
        app(CartManager::class)->add($this->server->id, 1, $this->answers($group, ''));
    }

    /**
     * A set is a set. Without this check the value is a product id a client
     * types, and "pick anything from Processors" buys any product in the shop
     * at the processor's price rule.
     */
    public function test_a_set_only_sells_what_is_in_it(): void
    {
        $parts = Category::create(['name' => ['en' => 'Parts'], 'slug' => 'parts', 'enabled' => true]);
        $this->cpu->categories()->attach($parts->id);
        $elsewhere = $this->product('TV-1', 'Television', 9000_00, 2);

        $group = $this->group(['type' => 'rows', 'name' => ['en' => 'Memory']]);
        $choice = $this->choice($group, ['kind' => 'source', 'source' => 'category:'.$parts->id, 'price_rule' => 'product_price']);

        $cart = app(CartManager::class);
        $key = $cart->add($this->server->id, 1, $this->answers($group, [['choice' => $choice->id.':'.$this->cpu->id, 'qty' => 1]]));
        $this->assertNotSame('', $key);

        $this->expectException(ValidationException::class);
        $cart->add($this->server->id, 1, $this->answers($group, [['choice' => $choice->id.':'.$elsewhere->id, 'qty' => 1]]));
    }

    /** An answer that can no longer be bought is not an answer. */
    public function test_an_answer_whose_product_ran_out_is_refused(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor']]);
        $choice = $this->choice($group, ['kind' => 'product', 'product_id' => $this->cpu->id, 'price_rule' => 'product_price']);

        $this->cpu->forceFill(['stock' => 0])->save();

        $this->expectException(ValidationException::class);
        app(CartManager::class)->add($this->server->id, 1, $this->answers($group, (string) $choice->id));
    }

    /**
     * Two builds are two lines, the same build is one line of two — which is
     * the cart's own rule, and works here only because the configuration has
     * exactly one spelling.
     */
    public function test_the_same_build_merges_and_a_different_one_does_not(): void
    {
        $group = $this->group(['type' => 'checkboxes', 'name' => ['en' => 'Licences']]);
        $a = $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Windows'], 'price_rule' => 'fixed', 'amount' => 100_00]);
        $b = $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'iDRAC'], 'price_rule' => 'fixed', 'amount' => 200_00]);

        $cart = app(CartManager::class);
        $one = $cart->add($this->server->id, 1, $this->answers($group, [(string) $a->id, (string) $b->id]));
        // The same two answers, submitted the other way round.
        $same = $cart->add($this->server->id, 1, $this->answers($group, [(string) $b->id, (string) $a->id]));
        $other = $cart->add($this->server->id, 1, $this->answers($group, [(string) $a->id]));

        $this->assertSame($one, $same);
        $this->assertNotSame($one, $other);
        $this->assertCount(2, $cart->raw());
        $this->assertSame(2, $cart->items()->firstWhere('key', $one)->qty);
    }

    /** The page's quote and the cart's price are one class, so they agree. */
    public function test_the_quote_endpoint_prices_what_the_cart_will_charge(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Warranty']]);
        $choice = $this->choice($group, ['kind' => 'plain', 'name' => ['en' => '+1 year'], 'price_rule' => 'fixed_plus_percent', 'amount' => 500_00, 'percent' => 10]);

        $this->post('http://localhost/options/quote/'.$this->server->slug, [
            'qty' => 1,
            'config' => ['options' => [$group->id => (string) $choice->id]],
        ])->assertOk()->assertSee("1\u{00A0}600", false);

        app(CartManager::class)->add($this->server->id, 1, $this->answers($group, (string) $choice->id));
        $this->assertSame(1600_00, app(CartManager::class)->items()->first()->line_total);
    }

    /**
     * A question the shop wrote for one product, and the shared one copied
     * into it — the answer to "this product needs a different number" that
     * does not involve a hidden per-product override (§33.8).
     */
    public function test_a_shared_question_can_be_copied_into_a_product_of_its_own(): void
    {
        $group = OptionGroup::create([
            'product_id' => null, 'admin_name' => 'Warranty everywhere',
            'name' => ['en' => 'Warranty'], 'type' => 'select', 'enabled' => true,
        ]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => '+1 year'], 'price_rule' => 'fixed', 'amount' => 100_00]);
        app(Groups::class)->flush();

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/options/groups/'.$group->id.'/copy', ['product_id' => $this->server->id])
            ->assertRedirect();

        app(Groups::class)->flush();
        $groups = app(Groups::class)->for($this->server->fresh());

        // Its own copy, and the shared one no longer asked here — or the
        // customer would be asked the same question twice.
        $this->assertCount(1, $groups);
        $this->assertSame($this->server->id, $groups->first()->product_id);
        $this->assertCount(1, $groups->first()->choices);
    }

    public function test_the_admin_writes_a_question_with_its_answers(): void
    {
        $this->actingAs($this->admin())->post('http://localhost/admin/options/groups', [
            'name' => 'Processor',
            'admin_name' => 'CPU',
            'type' => 'quantities',
            'product_id' => $this->server->id,
            'required' => '1',
            'per_unit' => '1',
            'enabled' => '1',
            'min_qty' => 1,
            'max_qty' => 2,
            'choices' => [
                ['kind' => 'product', 'product' => 'CPU-1', 'price_rule' => 'product_price', 'enabled' => '1'],
                ['kind' => 'plain', 'name' => 'Without a processor', 'price_rule' => 'free', 'enabled' => '1'],
                // Unfinished rows are dropped rather than refusing the form:
                // the admin may be mid-edit, and losing the rest of it is worse.
                ['kind' => 'plain', 'name' => '', 'price_rule' => 'fixed'],
            ],
        ])->assertRedirect();

        $group = OptionGroup::where('admin_name', 'CPU')->firstOrFail();

        $this->assertSame($this->server->id, $group->product_id);
        $this->assertTrue($group->required);
        $this->assertCount(2, $group->choices);
        $this->assertSame($this->cpu->id, $group->choices->first()->product_id);
    }

    /**
     * An amount typed in another currency follows that rate, exactly as a
     * product's own price does — the engine's money rule, no exception.
     */
    public function test_an_amount_typed_in_another_currency_is_kept_as_typed(): void
    {
        $code = collect(app(\App\Core\Support\Currencies::class)->all())
            ->keys()->first(fn ($c) => $c !== shop_currency());

        if (! $code) {
            $this->markTestSkipped('the test shop has one currency');
        }

        $rate = (float) app(\App\Core\Support\Currencies::class)->find($code)->rate;

        $this->actingAs($this->admin())->post('http://localhost/admin/options/groups', [
            'name' => 'Warranty', 'admin_name' => 'W', 'type' => 'select', 'enabled' => '1',
            'choices' => [['kind' => 'plain', 'name' => 'A year', 'price_rule' => 'fixed', 'amount' => '100', 'currency' => $code, 'enabled' => '1']],
        ])->assertRedirect();

        $choice = OptionChoice::latest('id')->firstOrFail();

        $this->assertSame($code, $choice->currency);
        $this->assertSame(100_00, $choice->amount_original);
        $this->assertSame((int) round(100_00 / $rate), $choice->amount);
    }

    /**
     * A set of two hundred processors is not a choice, it is a scroll — so the
     * list is narrowed by the SERVER, which is the only thing that can narrow a
     * catalogue query.
     */
    public function test_a_set_is_searched_on_the_server_and_only_offers_its_own(): void
    {
        $parts = Category::create(['name' => ['en' => 'Parts'], 'slug' => 'parts', 'enabled' => true]);
        $this->cpu->categories()->attach($parts->id);
        $psu = $this->product('PSU-1', 'Power supply', 80_00, 10);
        $psu->categories()->attach($parts->id);
        $elsewhere = $this->product('TV-9', 'Power television', 900_00, 2);

        $group = $this->group(['type' => 'rows', 'name' => ['en' => 'Memory']]);
        $choice = $this->choice($group, ['kind' => 'source', 'source' => 'category:'.$parts->id, 'price_rule' => 'product_price']);

        $this->get('http://localhost/options/set/'.$choice->id.'?q=Power&product='.$this->server->slug)
            ->assertOk()
            ->assertSee('Power supply')
            // In the category, so offered; the television is not, however well
            // it matches what was typed.
            ->assertDontSee('Power television');
    }

    /** "Add…" is a row rendered by the server, so every row is one view. */
    public function test_another_row_comes_from_the_server_with_an_index_of_its_own(): void
    {
        $group = $this->group(['type' => 'rows', 'name' => ['en' => 'Memory']]);
        $this->choice($group, ['kind' => 'product', 'product_id' => $this->cpu->id, 'price_rule' => 'product_price']);

        $this->get('http://localhost/options/row/'.$group->id.'?index=7&product='.$this->server->slug)
            ->assertOk()
            ->assertSee('config[options]['.$group->id.'][7][choice]', false)
            ->assertSee('config[options]['.$group->id.'][7][qty]', false);
    }
    /**
     * An option the customer WRITES (PLAN §33.7): an engraving, a name on a
     * shirt. What it costs is its first choice; what it says is part of the
     * purchase, which is why two engravings are two lines.
     */
    public function test_a_typed_option_is_charged_by_its_first_choice(): void
    {
        $group = $this->group(['type' => 'text', 'name' => ['en' => 'Engraving'], 'max_length' => 20]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Engraving'], 'price_rule' => 'fixed', 'amount' => 200_00]);

        $cart = app(CartManager::class);
        $mum = $cart->add($this->server->id, 2, [Configurator::KEY => [$group->id => ['text' => 'For Mum']]]);
        $dad = $cart->add($this->server->id, 1, [Configurator::KEY => [$group->id => ['text' => 'For Dad']]]);

        // Two different engravings are two different things bought.
        $this->assertNotSame($mum, $dad);

        $line = $cart->items()->firstWhere('key', $mum);
        $this->assertSame(1200_00, $line->unit_price);
        $this->assertSame(2400_00, $line->line_total);
        // What was written is what the line, the order and the workshop read.
        $this->assertSame('Engraving: For Mum', $line->parts[0]['label']);

        $lines = OrderLines::fromBasket($cart->items());
        $this->assertSame('Engraving: For Mum', $lines[0]['options'][0]['label']);
    }

    /** …and the same words twice are one line of two. */
    public function test_the_same_text_merges_into_one_line(): void
    {
        $group = $this->group(['type' => 'text', 'name' => ['en' => 'Engraving']]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Engraving'], 'price_rule' => 'fixed', 'amount' => 100_00]);

        $cart = app(CartManager::class);
        $first = $cart->add($this->server->id, 1, [Configurator::KEY => [$group->id => ['text' => '  For Mum ']]]);
        $again = $cart->add($this->server->id, 1, [Configurator::KEY => [$group->id => ['text' => 'For Mum']]]);

        $this->assertSame($first, $again);
        $this->assertCount(1, $cart->raw());
        $this->assertSame(2, $cart->items()->first()->qty);
    }

    public function test_a_required_typed_option_must_be_filled_in(): void
    {
        $group = $this->group(['type' => 'text', 'name' => ['en' => 'Engraving'], 'required' => true]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Engraving'], 'price_rule' => 'fixed', 'amount' => 100_00]);

        $this->expectException(ValidationException::class);
        app(CartManager::class)->add($this->server->id, 1, [Configurator::KEY => [$group->id => ['text' => '   ']]]);
    }

    /** A plate twenty characters wide refuses in the form, not at the till. */
    public function test_a_text_longer_than_the_shop_allows_is_refused(): void
    {
        $group = $this->group(['type' => 'text', 'name' => ['en' => 'Engraving'], 'max_length' => 10]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Engraving'], 'price_rule' => 'fixed', 'amount' => 100_00]);

        $this->expectException(ValidationException::class);
        app(CartManager::class)->add($this->server->id, 1, [Configurator::KEY => [$group->id => ['text' => 'far too long to engrave']]]);
    }

    /** With nothing to charge, the words are free and still bought. */
    public function test_a_typed_option_with_no_choice_costs_nothing_and_is_still_recorded(): void
    {
        $group = $this->group(['type' => 'textarea', 'name' => ['en' => 'Note for the workshop']]);

        $cart = app(CartManager::class);
        $cart->add($this->server->id, 1, [Configurator::KEY => [$group->id => ['text' => "Two holes\nin the lid"]]]);

        $line = $cart->items()->first();

        $this->assertSame(1000_00, $line->line_total);
        $this->assertSame(0, $line->parts[0]['amount']);
        $this->assertStringContainsString('Two holes', $line->parts[0]['label']);
    }

    /**
     * A question whose every answer has been WITHDRAWN is not drawn at all —
     * right, because a deleted or disabled product is nothing to tell anybody
     * about (an answer that has merely sold out is drawn and refused, see
     * test_an_answer_that_has_sold_out_is_shown_and_refused). But a REQUIRED
     * question that is not drawn is still checked when the customer presses
     * Buy, so the product cannot be bought and the error names a control that
     * is not on the page. Nothing says so anywhere: hence the statement
     * (PLAN §25.2/3).
     */
    public function test_a_required_question_with_no_buyable_answer_is_reported(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor'], 'required' => true]);
        $this->choice($group, ['kind' => 'product', 'product_id' => $this->cpu->id, 'price_rule' => 'product_price']);

        $this->assertSame([], $this->findings('options.required_questions_can_be_answered'));

        // The one processor is withdrawn, and the server silently stops being
        // buyable at all.
        $this->cpu->update(['status' => 'disabled']);

        $found = $this->findings('options.required_questions_can_be_answered');

        $this->assertCount(1, $found);
        $this->assertSame('Processor', $found[0]['title']);
        $this->assertSame('Asked on ":product", which cannot be bought while this stands.', $found[0]['detail'][0]);

        // …and the answer itself is reported separately, because that is the
        // row somebody actually fixes.
        $dead = $this->findings('options.answers_stand_for_something');
        $this->assertCount(1, $dead);
    }

    /** An optional question losing an answer is a warning, never a refusal. */
    public function test_an_optional_question_that_loses_an_answer_is_not_a_broken_product(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Extra disk']]);
        $this->choice($group, ['kind' => 'product', 'product_id' => $this->cpu->id, 'price_rule' => 'product_price']);

        $this->cpu->update(['status' => 'disabled']);

        $this->assertSame([], $this->findings('options.required_questions_can_be_answered'));
        $this->assertCount(1, $this->findings('options.answers_stand_for_something'));
    }

    /**
     * An option is not a promise that everything is in stock — but the customer
     * is told which answer is gone, rather than shown a list that has quietly
     * shortened itself and left them looking for what the page advertised.
     */
    public function test_an_answer_that_has_sold_out_is_shown_and_refused(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor']]);
        $choice = $this->choice($group, ['kind' => 'product', 'product_id' => $this->cpu->id, 'price_rule' => 'product_price']);

        $this->cpu->forceFill(['stock' => 0])->save();

        $body = $this->get('http://localhost/product/'.$this->server->slug)->assertOk()->getContent();

        $this->assertStringContainsString('Processor', $body, 'the question is still asked');
        $this->assertMatchesRegularExpression(
            '/<option value="'.$choice->id.'"[^>]*\sdisabled/',
            $body,
            'a sold-out answer must be shown and refused, not silently dropped',
        );
        $this->assertStringContainsString('Out of stock', $body);
    }

    /** ...and buying it is still refused, whoever posts it. */
    public function test_a_sold_out_answer_is_still_refused_when_posted_by_hand(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor']]);
        $choice = $this->choice($group, ['kind' => 'product', 'product_id' => $this->cpu->id, 'price_rule' => 'product_price']);

        $this->cpu->forceFill(['stock' => 0])->save();

        $this->expectException(ValidationException::class);
        app(CartManager::class)->add($this->server->id, 1, $this->answers($group, (string) $choice->id));
    }

    /** A product that is GONE is not drawn: there is nothing left to say about it. */
    public function test_an_answer_whose_product_is_withdrawn_is_not_shown_at_all(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor']]);
        $this->choice($group, ['kind' => 'product', 'product_id' => $this->cpu->id, 'price_rule' => 'product_price']);

        $this->cpu->update(['status' => 'disabled']);
        app()->forgetInstance(Configurator::class);

        $this->get('http://localhost/product/'.$this->server->slug)->assertOk()
            ->assertDontSee($this->cpu->t('name'));
    }

    /** The same answer in a set, and in the list the search narrows. */
    public function test_a_sold_out_member_of_a_set_is_shown_and_refused(): void
    {
        $parts = Category::create(['name' => ['en' => 'Parts'], 'slug' => 'sold-out-parts', 'enabled' => true]);
        $this->cpu->categories()->attach($parts->id);
        $this->cpu->forceFill(['stock' => 0])->save();

        $group = $this->group(['type' => 'rows', 'name' => ['en' => 'Memory']]);
        $choice = $this->choice($group, ['kind' => 'source', 'source' => 'category:'.$parts->id, 'price_rule' => 'product_price']);

        $body = $this->get('http://localhost/options/set/'.$choice->id.'?product='.$this->server->slug)
            ->assertOk()->getContent();

        $this->assertStringContainsString($this->cpu->t('name'), $body);
        $this->assertMatchesRegularExpression('/<option value="'.$choice->id.':'.$this->cpu->id.'"[^>]*\sdisabled/', $body);
    }

    /**
     * Run one of this module's invariants through the guard's own registry, so
     * the registration is under test and not only the method behind it.
     *
     * @return list<array<string, mixed>>
     */
    protected function findings(string $key): array
    {
        app(Groups::class)->flush();
        // The configurator memoises the products it has been asked about, and
        // this test disables one between two runs.
        app()->forgetInstance(Configurator::class);

        $check = app(\Torgash\Guard\Invariants\Invariants::class)->find($key);

        $this->assertNotNull($check, 'the check is registered');

        return collect(($check['check'])(50))->values()->all();
    }

    /**
     * One group's answers, in the shape a line's configuration BAG has: a key
     * per module that configures the product, `options` being this one's.
     * Written out here rather than hidden in a helper of the module, because
     * the shape is the contract every caller — form, API, test — speaks.
     */
    protected function answers(OptionGroup $group, mixed $answer): array
    {
        return [Configurator::KEY => [$group->id => $answer]];
    }

    protected function group(array $attributes): OptionGroup
    {
        $group = OptionGroup::create($attributes + [
            'product_id' => $this->server->id,
            'admin_name' => 'g'.uniqid(),
            'enabled' => true,
        ]);

        app(Groups::class)->flush();

        return $group;
    }

    protected function choice(OptionGroup $group, array $attributes): OptionChoice
    {
        $choice = OptionChoice::create($attributes + ['group_id' => $group->id, 'enabled' => true]);

        $group->load('choices');
        app(Groups::class)->flush();

        return $choice;
    }

    protected function product(string $sku, string $name, int $price, int $stock): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => $stock, 'status' => 'active',
            'availability_status' => 'in_stock',
        ]);
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
