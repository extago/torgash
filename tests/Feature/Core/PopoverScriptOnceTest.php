<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * The storefront layout prints popover-anchor.js for the shop's own markup,
 * and <x-popover> pushes it too — once a page between them (the first live
 * shop's report, 2026-10-27: every page fetched it twice).
 */
class PopoverScriptOnceTest extends TestCase
{
    public function test_a_storefront_page_loads_the_popover_script_once(): void
    {
        $page = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString('popovertarget=', $page, 'the page draws a popover of its own');
        $this->assertSame(1, substr_count($page, 'popover-anchor.js'));
    }
}
