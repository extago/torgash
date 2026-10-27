<?php

namespace Tests\Feature\Core;

use App\Core\Content\Placeholders;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * Tokens that know what they are standing next to.
 *
 * The contact tokens ({phone}, {hours}) answer from settings and need nothing;
 * a token about the product ({price}, {club_price}) cannot exist without one.
 * So expand() carries the caller's context — the zone context, for a block —
 * and every resolver written before this keeps working untouched, because PHP
 * ignores an argument a closure did not ask for.
 *
 * The empty answer is the important half: the same block dropped on the home
 * page must render nothing rather than a frame around a blank, which is what
 * the HTML block's hide_if_empty acts on.
 */
class ContextPlaceholdersTest extends TestCase
{
    public function test_a_product_token_answers_from_the_context(): void
    {
        $product = Product::create([
            'sku' => 'TOK-1', 'name' => ['en' => 'Tokened'], 'slug' => 'tokened',
            'price' => 25_00, 'stock' => 1, 'status' => 'active',
        ]);

        $expanded = expand_placeholders('{product_sku} — {price}', ['product' => $product]);

        $this->assertStringContainsString('TOK-1', $expanded);
        $this->assertStringContainsString('25.00', $expanded);
    }

    public function test_the_same_token_is_empty_without_a_product(): void
    {
        $this->assertSame(' — ', expand_placeholders('{product_sku} — {price}'));
        $this->assertSame('', app(Placeholders::class)->resolve('club_price'));
    }

    /** A resolver that takes no argument is called with one and does not care. */
    public function test_a_context_free_resolver_still_works(): void
    {
        app(Placeholders::class)->register('answer', [
            'label' => ['en' => 'Answer'],
            'resolve' => fn () => 42,
        ]);

        $this->assertSame('42', expand_placeholders('{answer}', ['product' => null]));
    }
}
