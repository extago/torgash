<?php

namespace Tests\Feature\Blocks;

use Tests\TestCase;
use Torgash\Blocks\BlocksServiceProvider;
use Torgash\Blocks\Models\Block;

/**
 * The two zones at the very edges of every page (the owner, 2026-10-27):
 * above the header — "the shop is being filled" — and below the footer,
 * each drawn only when something is in it.
 */
class PageEdgeZonesTest extends TestCase
{
    public function test_blocks_above_the_header_and_below_the_footer(): void
    {
        foreach (['storefront.page.top' => 'Shop being filled', 'storefront.page.bottom' => 'After the footer'] as $zone => $text) {
            Block::create(['zone' => $zone, 'type' => 'html', 'title' => $text, 'enabled' => true, 'sort' => 1,
                'settings' => ['html' => '<p>'.$text.'</p>'], 'rules_match' => 'all']);
        }
        // The module subscribes to the zones that had blocks when it booted.
        $this->app->register(new BlocksServiceProvider($this->app), true);

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $top = strpos($html, 'Shop being filled');
        $bottom = strpos($html, 'After the footer');
        $this->assertNotFalse($top);
        $this->assertNotFalse($bottom);
        $this->assertLessThan(strpos($html, '<header'), $top, 'before the header');
        $this->assertGreaterThan(strrpos($html, '</footer>'), $bottom, 'after the footer');
    }

    public function test_empty_they_leave_no_trace(): void
    {
        $this->get('http://localhost/')->assertOk()
            ->assertDontSee('torgash-page-top')
            ->assertDontSee('torgash-page-bottom');
    }
}
