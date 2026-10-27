<?php

namespace Tests\Feature\Seo;

use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * An address in a language the record has no words in shows another
 * language's text (audit 2026-10-15: "Дрели" served at the English address
 * as English). It is not advertised as a translation, and its canonical is
 * the page in the language the words are in.
 */
class UntranslatedPageTest extends TestCase
{
    protected function links(string $url): array
    {
        $body = $this->get($url)->assertOk()->getContent();
        preg_match('~<link rel="canonical" href="([^"]+)"~', $body, $canonical);
        preg_match_all('~<link rel="alternate" hreflang="([^"]+)"~', $body, $alternates);

        return [$canonical[1] ?? null, $alternates[1]];
    }

    public function test_a_page_with_words_in_one_language_points_there_and_claims_no_translation(): void
    {
        Product::create(['sku' => 'UT-1', 'name' => ['ru' => 'Дрель ударная'], 'slug' => 'drel-udarnaya', 'price' => 10_00, 'stock' => 1, 'status' => 'active']);

        [$canonical, $alternates] = $this->links('http://localhost/product/drel-udarnaya');
        $this->assertSame('http://localhost/ru/product/drel-udarnaya', $canonical);
        $this->assertSame([], $alternates);

        [$canonical] = $this->links('http://localhost/ru/product/drel-udarnaya');
        $this->assertSame('http://localhost/ru/product/drel-udarnaya', $canonical);

        // The sitemap says the same as the page: the address with words, no translation.
        $map = $this->get('http://localhost/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>http://localhost/ru/product/drel-udarnaya</loc>', $map);
        $this->assertStringNotContainsString('<loc>http://localhost/product/drel-udarnaya</loc>', $map);
        $this->assertStringNotContainsString('hreflang="en" href="http://localhost/product/drel-udarnaya"', $map);
    }

    public function test_a_page_in_both_languages_keeps_its_own_canonical_and_both_alternates(): void
    {
        Product::create(['sku' => 'UT-2', 'name' => ['en' => 'Hammer drill', 'ru' => 'Дрель'], 'slug' => 'hammer-drill', 'price' => 10_00, 'stock' => 1, 'status' => 'active']);

        [$canonical, $alternates] = $this->links('http://localhost/product/hammer-drill');
        $this->assertSame('http://localhost/product/hammer-drill', $canonical);
        $this->assertContains('ru', $alternates);
        $this->assertContains('en', $alternates);
    }
}
