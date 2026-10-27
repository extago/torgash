<?php

namespace Tests\Feature\Core;

use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Pages\Models\Page;
use Torgash\Promotions\Models\Promotion;

/**
 * Every public page says what it is in its meta description, even when
 * nobody wrote one (audit 2026-10-15: categories, info pages, the blog and
 * the sales had none). What the shop wrote still wins.
 */
class MetaDescriptionsTest extends TestCase
{
    protected function description(string $url): ?string
    {
        $body = $this->get($url)->assertOk()->getContent();

        return preg_match('~<meta name="description" content="([^"]*)">~', $body, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }

    public function test_a_category_without_text_says_what_it_is_and_its_own_text_wins(): void
    {
        Category::create(['name' => ['en' => 'Kettles'], 'slug' => 'meta-kettles', 'enabled' => true]);
        Category::create(['name' => ['en' => 'Toasters'], 'slug' => 'meta-toasters', 'enabled' => true,
            'description' => ['en' => '<p>Toasters for two slices or four.</p>']]);

        $this->assertStringStartsWith('Kettles in ', (string) $this->description('http://localhost/catalog/meta-kettles'));
        $this->assertSame('Toasters for two slices or four.', $this->description('http://localhost/catalog/meta-toasters'));
    }

    public function test_an_info_page_is_described_by_its_own_words_without_placeholders(): void
    {
        Page::create(['title' => ['en' => 'Returns'], 'body' => ['en' => '<p>Thirty days to change your mind. {callback_link}</p>'],
            'slug' => 'meta-returns', 'enabled' => true]);

        $this->assertSame('Thirty days to change your mind.', $this->description('http://localhost/meta-returns'));
    }

    public function test_the_blog_and_the_sales_say_what_they_are(): void
    {
        $this->assertStringStartsWith('Articles and news from', (string) $this->description('http://localhost/blog'));
        $this->assertStringStartsWith('Current promotions and discounts at', (string) $this->description('http://localhost/sale'));
    }

    public function test_a_sale_page_is_described_once_through_the_page_head(): void
    {
        $promotion = Promotion::create(['name' => ['en' => 'Spring'], 'zone' => 'catalog', 'combine' => Promotion::REPLACE, 'enabled' => true,
            'slug' => 'meta-spring', 'published' => true, 'description' => ['en' => '<p>Fifteen percent off the garden.</p>']]);
        $promotion->tiers()->create(['position' => 0, 'conditions' => null, 'discount_type' => 'percent', 'discount_value' => 15]);

        $body = $this->get('http://localhost/sale/meta-spring')->assertOk()->getContent();
        $this->assertSame(1, substr_count($body, '<meta name="description"'));
        $this->assertSame('Fifteen percent off the garden.', $this->description('http://localhost/sale/meta-spring'));

        $promotion->update(['meta_description' => ['en' => 'The garden sale.']]);
        $this->assertSame('The garden sale.', $this->description('http://localhost/sale/meta-spring'));
    }
}
