<?php

namespace Tests\Feature\Options;

use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\ShownPrice;
use Torgash\Options\Configurator;
use Torgash\Options\FromPrice;
use Torgash\Options\Groups;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;
use Torgash\Options\PriceRules;

/**
 * The price "from" (PLAN §33.7): what a product can actually be bought for.
 *
 * The statements worth pinning are the ones a shop loses money on when they
 * stop being true — a card quoting a build nobody can buy, a feed disagreeing
 * with the page it links to, an optional warranty inflating a price that is
 * genuinely payable without it, and the quote parting company with what the
 * cart goes on to charge.
 */
class FromPriceTest extends TestCase
{
    protected Category $servers;

    protected Product $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flush();

        $this->servers = Category::create(['name' => ['en' => 'Servers'], 'slug' => 'servers', 'enabled' => true]);
        $this->server = $this->product('SRV-1', 'Server', 1000_00);
        $this->server->categories()->attach($this->servers->id);
    }

    /** The whole catalogue of an ordinary shop: no question, no "from". */
    public function test_a_product_that_demands_nothing_is_quoted_its_own_price(): void
    {
        $shown = app(ShownPrice::class);

        $this->assertSame(1000_00, $shown->minimum($this->server));
        $this->assertFalse($shown->isFrom($this->server));
        $this->assertStringNotContainsString('from', $shown->format($this->server));
    }

    /** A required question is part of the price, and the cheapest answer sets it. */
    public function test_a_required_question_quotes_from_its_cheapest_answer(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor'], 'required' => true]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Gold'], 'price_rule' => 'fixed', 'amount' => 900_00]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Silver'], 'price_rule' => 'fixed', 'amount' => 300_00]);

        $shown = app(ShownPrice::class);

        $this->assertSame(1300_00, $shown->minimum($this->server));
        $this->assertTrue($shown->isFrom($this->server));
    }

    /**
     * …and an OPTIONAL one is not. The product really is buyable without an
     * extended warranty, so quoting it as if it were not is a price the shop
     * invents against itself.
     */
    public function test_an_optional_question_does_not_raise_the_price(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Warranty'], 'required' => false]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => '+1 year'], 'price_rule' => 'fixed', 'amount' => 500_00]);

        $this->assertSame(1000_00, app(ShownPrice::class)->minimum($this->server));
        $this->assertFalse(app(ShownPrice::class)->isFrom($this->server));
    }

    /**
     * A set is answered by the cheapest thing still IN it — and never by one
     * that cannot be bought, because `validate()` would refuse that answer and
     * the price would be quoted from a build the buy button rejects.
     */
    public function test_a_required_set_quotes_from_the_cheapest_buyable_product_in_it(): void
    {
        $parts = Category::create(['name' => ['en' => 'Processors'], 'slug' => 'processors', 'enabled' => true]);

        $cheapButGone = $this->product('CPU-0', 'Xeon Bronze', 100_00);
        $cheapButGone->categories()->attach($parts->id);
        $cheapButGone->update(['stock' => 0, 'availability_status' => 'out_of_stock']);

        foreach ([['CPU-1', 'Xeon Silver', 170_00], ['CPU-2', 'Xeon Gold', 400_00]] as [$sku, $name, $price]) {
            $this->product($sku, $name, $price)->categories()->attach($parts->id);
        }

        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor'], 'required' => true]);
        $this->choice($group, [
            'kind' => 'source',
            'source' => 'category:'.$parts->id,
            'name' => ['en' => 'Any processor'],
            'price_rule' => 'product_price',
        ]);

        // 1000 + 170, not 1000 + 100: the bronze one is out of stock.
        $this->assertSame(1170_00, app(ShownPrice::class)->minimum($this->server));
    }

    /**
     * A question that only appears once another has been answered still counts
     * when it, too, is required — the cheapest BUILD, not the cheapest first
     * answer.
     */
    public function test_a_required_question_revealed_by_another_is_counted(): void
    {
        $cpu = $this->group(['type' => 'select', 'name' => ['en' => 'Processor'], 'required' => true]);
        $this->choice($cpu, ['kind' => 'plain', 'name' => ['en' => 'Silver'], 'price_rule' => 'fixed', 'amount' => 300_00]);

        $cooling = $this->group([
            'type' => 'select',
            'name' => ['en' => 'Cooling'],
            'required' => true,
            'depends_on_group_id' => $cpu->id,
        ]);
        $this->choice($cooling, ['kind' => 'plain', 'name' => ['en' => 'Air'], 'price_rule' => 'fixed', 'amount' => 50_00]);

        $this->assertSame(1350_00, app(ShownPrice::class)->minimum($this->server));
    }

    /**
     * The invariant the whole thing rests on: the quote is what the cart
     * charges once those same answers are picked. Two numbers from two pieces
     * of arithmetic is exactly the drift this engine keeps one pricing path to
     * avoid.
     */
    public function test_the_quote_is_what_the_cart_charges_for_that_build(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor'], 'required' => true]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Gold'], 'price_rule' => 'fixed', 'amount' => 900_00]);
        $cheapest = $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Silver'], 'price_rule' => 'percent', 'percent' => 25]);

        $quoted = app(ShownPrice::class)->minimum($this->server);

        $cart = app(CartManager::class);
        $cart->add($this->server->id, 1, [Configurator::KEY => [$group->id => (string) $cheapest->id]]);

        $this->assertSame(1250_00, $quoted);
        $this->assertSame($quoted, $cart->items()->first()->line_total);
    }

    /** The card says so in words, and drops a struck-through price that is about another product. */
    public function test_a_card_prints_the_word_and_no_old_price(): void
    {
        $this->server->update(['list_price' => 1500_00]);

        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor'], 'required' => true]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Silver'], 'price_rule' => 'fixed', 'amount' => 300_00]);

        $body = $this->get('http://localhost/catalog/servers')->assertOk()->getContent();

        $this->assertStringContainsString('from', $body);
        $this->assertStringContainsString('1 300', $this->digits($body));
        $this->assertStringNotContainsString('line-through', $body);
    }

    /**
     * What a machine is told, which must be what the page says. The head
     * quoting the bare configuration while the page quotes the buildable
     * price is exactly the disagreement `ProductFacts` was written to stop —
     * and schema.org has its own word for "from".
     */
    public function test_the_machine_readable_page_says_from_in_its_own_vocabulary(): void
    {
        $this->server->update(['list_price' => 1500_00]);

        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor'], 'required' => true]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Silver'], 'price_rule' => 'fixed', 'amount' => 300_00]);

        $html = $this->get('http://localhost/product/srv-1')->assertOk()->getContent();

        $this->assertStringContainsString('AggregateOffer', $html);
        $this->assertStringContainsString('lowPrice', $html);
        $this->assertStringContainsString('1300.00', $html);
        $this->assertStringNotContainsString('highPrice', $html);

        $text = $this->get('http://localhost/product/srv-1.md')->assertOk()->getContent();

        $this->assertStringContainsString('Price from: 1300.00', $text);
        // The struck-through number is about the bare product, so it is not a
        // fact about the cheapest build either.
        $this->assertStringNotContainsString('Old price', $text);
    }

    /**
     * The feed's own field. A marketplace compares the file against the
     * landing page, and since §33.7 the page quotes the buildable price.
     */
    public function test_the_export_schema_offers_the_buildable_price(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor'], 'required' => true]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Silver'], 'price_rule' => 'fixed', 'amount' => 300_00]);

        $fields = collect(hook_filter('exim.schema.product', []))->keyBy('key');

        $this->assertTrue($fields->has('price_from'));
        $this->assertNull($fields['price_from']['set'], 'a computed number may not be written back');
        $this->assertSame(1300_00, ($fields['price_from']['get'])($this->server->fresh(), ['priceType' => null]));
        $this->assertSame(1000_00, ($fields['price']['get'])($this->server->fresh(), ['priceType' => null]));
    }

    /**
     * A grid asks this per card, so the set is announced first: a page of
     * twelve must cost what a page of two does — the claim `QueryBudgetTest`
     * already holds the catalogue to, measured the same way, because a number
     * of queries drifts with the framework and a RATIO does not.
     */
    public function test_a_page_of_cards_reads_the_groups_once_however_many_there_are(): void
    {
        $group = $this->group(['type' => 'select', 'name' => ['en' => 'Processor'], 'required' => true]);
        $this->choice($group, ['kind' => 'plain', 'name' => ['en' => 'Silver'], 'price_rule' => 'fixed', 'amount' => 300_00]);

        // The configured one is in BOTH pages, so the two counts differ only
        // by how many cards there are: a page with no question on it is a
        // cheaper page for an honest reason, and would hide the N+1.
        $servers = collect([$this->server->fresh()])->concat(
            collect(range(1, 11))->map(fn (int $i) => $this->product('SRV-'.($i + 1), 'Server '.$i, 1000_00)),
        );

        $small = $this->pageCost($servers->take(2));
        $large = $this->pageCost($servers);

        $this->assertSame($small, $large, 'the groups of a page are read once, not once per card');
    }

    /** What one page of cards costs in queries: announce the set, then ask per card. */
    protected function pageCost(iterable $products): int
    {
        $this->flush();
        $groups = app(Groups::class);

        return $this->countQueries(function () use ($groups, $products) {
            $groups->prime($products);

            foreach ($products as $product) {
                $groups->for($product);
            }
        });
    }

    protected function countQueries(callable $work): int
    {
        $count = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$count) {
            $count++;
        });

        $work();

        return $count;
    }

    protected function digits(string $body): string
    {
        // Prices are printed with a non-breaking space in the thousands.
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $body);
    }

    protected function group(array $attributes): OptionGroup
    {
        $group = OptionGroup::create($attributes + [
            'product_id' => $this->server->id,
            'admin_name' => 'g'.uniqid(),
            'enabled' => true,
        ]);

        $this->flush();

        return $group;
    }

    protected function choice(OptionGroup $group, array $attributes): OptionChoice
    {
        $choice = OptionChoice::create($attributes + ['group_id' => $group->id, 'enabled' => true]);

        $group->load('choices');
        $this->flush();

        return $choice;
    }

    protected function product(string $sku, string $name, int $price): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => 10, 'status' => 'active',
            'availability_status' => 'in_stock',
        ]);
    }

    /** Every memo this answer is built out of — the page's, the rules', the quote's. */
    protected function flush(): void
    {
        app(Groups::class)->flush();
        app(PriceRules::class)->flush();
        app(FromPrice::class)->flush();
        app(Configurator::class);
        app()->forgetInstance(Configurator::class);
    }
}
