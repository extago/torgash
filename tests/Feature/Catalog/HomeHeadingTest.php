<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;

/**
 * A home page made only of blocks still says what it is in one heading
 * (audit 2026-10-15): the storefront's title, for crawlers and screen
 * readers, unless the shop wrote its own <h1> into a block.
 */
class HomeHeadingTest extends TestCase
{
    public function test_the_home_page_has_exactly_one_heading(): void
    {
        $body = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($body, '<h1'));
        $this->assertMatchesRegularExpression('~<h1 class="sr-only">[^<]+</h1>~', $body);
    }

    public function test_the_shops_own_heading_in_a_block_wins(): void
    {
        // What an HTML block in the zone prints (blocks subscribe to zones at
        // boot, so a row made now would not be drawn until the next one).
        hook_listen('storefront.home.top', fn (string $html) => $html.'<h1>Our own words</h1>');

        $body = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($body, '<h1'));
        $this->assertStringContainsString('<h1>Our own words</h1>', $body);
    }
}
