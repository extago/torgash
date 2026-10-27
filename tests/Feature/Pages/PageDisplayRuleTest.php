<?php

namespace Tests\Feature\Pages;

use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;
use Torgash\Pages\Models\Page;

/**
 * "Page being viewed" — the display rule that keeps a block placed under
 * pages (the comments, a banner) off the pages it does not belong on.
 */
class PageDisplayRuleTest extends TestCase
{
    public function test_a_block_can_be_kept_off_one_page(): void
    {
        $privacy = Page::create(['slug' => 'rule-privacy', 'title' => ['en' => 'Privacy'], 'body' => ['en' => '<p>Text</p>'], 'enabled' => true]);
        Page::create(['slug' => 'rule-about', 'title' => ['en' => 'About'], 'body' => ['en' => '<p>Text</p>'], 'enabled' => true]);

        $this->block('pages.after_content', 'UNDER-THE-TEXT', [['kind' => 'page_viewed', 'operator' => 'not_in', 'value' => [$privacy->id]]]);

        $this->get('http://localhost/rule-about')->assertOk()->assertSee('UNDER-THE-TEXT');
        $this->get('http://localhost/rule-privacy')->assertOk()->assertDontSee('UNDER-THE-TEXT');
    }

    public function test_a_block_can_be_put_on_one_page_only(): void
    {
        $about = Page::create(['slug' => 'only-about', 'title' => ['en' => 'About'], 'body' => ['en' => '<p>Text</p>'], 'enabled' => true]);
        Page::create(['slug' => 'only-other', 'title' => ['en' => 'Other'], 'body' => ['en' => '<p>Text</p>'], 'enabled' => true]);

        $this->block('pages.after_content', 'ABOUT-ONLY', [['kind' => 'page_viewed', 'operator' => 'in', 'value' => [$about->id]]]);

        $this->get('http://localhost/only-about')->assertOk()->assertSee('ABOUT-ONLY');
        $this->get('http://localhost/only-other')->assertOk()->assertDontSee('ABOUT-ONLY');
    }

    protected function block(string $zone, string $text, array $rules): Block
    {
        $block = Block::create([
            'zone' => $zone, 'type' => 'html', 'title' => $text,
            'settings' => ['html' => '<p>'.$text.'</p>'],
            'enabled' => true, 'sort' => 1, 'rules' => $rules, 'rules_match' => 'all',
        ]);

        hook_listen($zone, fn (string $value, array $context) => $value.app(ZoneRenderer::class)->render(collect([$block]), $context));

        return $block;
    }
}
