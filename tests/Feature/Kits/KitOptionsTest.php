<?php

namespace Tests\Feature\Kits;

use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Product;
use Torgash\Kits\Models\Kit;
use Torgash\Kits\Models\KitSlot;
use Torgash\Kits\Offer;
use Torgash\Options\Configurator;
use Torgash\Options\Groups;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;
use Torgash\Options\PriceRules;

/**
 * A kit under a product that ASKS something first.
 *
 * The card is drawn on a page where the customer may have just chosen an
 * engraving or a processor, and "add the set" buys that product — not a bare
 * one. Two statements follow, and the second is why this is a bug rather than
 * a nicety: the price the card quotes has to be the price the cart charges,
 * and a product whose questions are REQUIRED cannot be bought without them at
 * all — before this the whole kit was refused and nothing reached the basket.
 *
 * Variations need none of it and are deliberately not tested here: picking one
 * is a different PRODUCT with a page of its own, so the kit is already about
 * the right one.
 */
class KitOptionsTest extends TestCase
{
    protected Product $phone;

    protected Product $case;

    protected Kit $kit;

    protected KitSlot $slot;

    protected OptionGroup $engraving;

    protected function setUp(): void
    {
        parent::setUp();

        app(Groups::class)->flush();
        app(PriceRules::class)->flush();

        $this->phone = $this->product('PH-1', 'Phone', 1000_00);
        $this->case = $this->product('CS-1', 'Case', 100_00);

        $this->kit = Kit::create([
            'admin_name' => 'Set', 'name' => ['en' => 'Set'],
            'main_product_id' => $this->phone->id, 'enabled' => true,
            'placement' => 'before_details', 'layout' => 'grid',
        ]);

        $this->slot = KitSlot::create([
            'kit_id' => $this->kit->id, 'kind' => 'product', 'product_id' => $this->case->id,
            'qty' => 1, 'discount_type' => 'percent', 'discount_percent' => 10, 'enabled' => true,
        ]);

        $this->engraving = OptionGroup::create([
            'product_id' => $this->phone->id, 'admin_name' => 'e', 'name' => ['en' => 'Engraving'],
            'type' => 'text', 'required' => true, 'enabled' => true,
        ]);

        OptionChoice::create([
            'group_id' => $this->engraving->id, 'enabled' => true, 'kind' => 'plain',
            'name' => ['en' => 'Engrave'], 'price_rule' => 'fixed', 'amount' => 200_00,
        ]);

        app(Groups::class)->flush();
    }

    /** The whole point: the set reaches the basket, configured as it was quoted. */
    public function test_the_set_is_bought_with_the_answers_given_on_the_page(): void
    {
        $this->addKit(['options' => [$this->engraving->id => ['text' => 'For Anna']]])->assertOk();

        $cart = app(CartManager::class);
        $lines = $cart->items();

        $this->assertCount(2, $lines, 'the phone and the case');

        $phoneLine = $lines->firstWhere(fn ($line) => (int) $line->product->id === (int) $this->phone->id);

        $this->assertSame(1200_00, $phoneLine->unit_price, 'the engraving is charged');
        $this->assertStringContainsString('For Anna', $phoneLine->parts[0]['label']);
    }

    /**
     * And without them it is refused rather than half-bought. This is the
     * measured regression: a required question left unanswered sent the whole
     * kit away and put NOTHING in the basket, silently.
     */
    public function test_a_required_question_left_unanswered_refuses_the_whole_set(): void
    {
        $this->addKit([]);

        $this->assertTrue(app(CartManager::class)->items()->isEmpty());
    }

    /** The card quotes what the cart will charge — one number, not two. */
    public function test_the_card_quotes_the_configured_price(): void
    {
        $config = [Configurator::KEY => [$this->engraving->id => [['c' => (int) OptionChoice::first()->id, 'q' => 1, 't' => 'For Anna']]]];

        $bare = app(Offer::class)->for($this->kit, $this->phone);
        $configured = app(Offer::class)->for($this->kit, $this->phone, config: $config);

        $this->assertSame(1000_00, $bare['main_price']);
        $this->assertSame(1200_00, $configured['main_price']);
        $this->assertSame(
            $configured['main_price'] + $configured['slots'][0]['price'],
            $configured['kit_total'],
            'the total is the configured phone plus the discounted case',
        );

        // …and it says WHICH answers made it that price, so the number is an
        // explained number rather than a surprise.
        $this->assertStringContainsString('For Anna', $configured['main_parts'][0]['label']);
    }

    /**
     * The quote as the PAGE asks it — the answers spelled the way the buy
     * panel's form spells them, not the canonical shape. It priced the bare
     * phone (measured): the parts seam found none of them. And the main
     * product's price and answers come back beside the total, out of band.
     */
    public function test_the_quote_reads_the_form_as_sent_and_names_the_answers(): void
    {
        $html = $this->post('http://localhost/kits/'.$this->kit->id.'/quote', [
            'main' => $this->phone->id,
            'slots' => [$this->slot->id => $this->case->id],
            'config' => ['options' => [$this->engraving->id => 'For Anna']],
        ], ['HX-Request' => 'true'])->assertOk()->getContent();

        // A simple set carries no discount: the engraved phone plus the case.
        $this->assertStringContainsString(format_price(1200_00 + 100_00), $html, 'the engraved phone, not the bare one');
        $this->assertStringContainsString('hx-swap-oob="outerHTML:#kit-main-'.$this->kit->id.'"', $html);
        $this->assertStringContainsString('For Anna', $html);
    }

    /** The shop chooses how the goods stand, and the card obeys. */
    public function test_the_layout_is_the_shops_choice(): void
    {
        $body = $this->get('http://localhost/product/ph-1')->assertOk()->getContent();
        $this->assertStringNotContainsString('overflow-x-auto', $body, 'a grid does not scroll sideways');

        $this->kit->update(['layout' => 'scroller']);

        $body = $this->get('http://localhost/product/ph-1')->assertOk()->getContent();
        $this->assertStringContainsString('overflow-x-auto', $body);
    }

    /**
     * Every cell opens with a row of the same height, which is what puts the
     * photographs on one baseline — the main product had none and sat a line
     * higher than everything beside it.
     */
    public function test_every_cell_has_a_header_row_so_the_photos_line_up(): void
    {
        $body = $this->get('http://localhost/product/ph-1')->assertOk()->getContent();

        $this->assertStringContainsString('This product', $body, 'the main product has a header of its own');
        $this->assertSame(
            2,
            substr_count($body, ' h-6"'), // the kit's rows end their class with it; an icon's `w-6 h-6 …` does not
            'one header row per cell — the main product and the one slot',
        );
    }

    /** @param array<string, mixed> $config */
    protected function addKit(array $config)
    {
        return $this->post('http://localhost/kits/'.$this->kit->id.'/add', [
            'main' => $this->phone->id,
            'slots' => [$this->slot->id => $this->case->id],
            'config' => $config,
        ]);
    }

    protected function product(string $sku, string $name, int $price): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => 10, 'status' => 'active',
            'availability_status' => 'in_stock',
        ]);
    }
}
