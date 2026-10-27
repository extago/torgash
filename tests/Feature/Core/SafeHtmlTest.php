<?php

namespace Tests\Feature\Core;

use App\Core\Content\SafeHtml;
use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Pages\Models\Page;

/**
 * The right to edit products is not the right to run scripts on the shop.
 *
 * Descriptions, pages, articles and promotions were printed exactly as typed,
 * and the storefront and the admin share one origin and one session: a script
 * in a description ran as whoever looked at the product — the owner included —
 * and could make itself an administrator. What a text may keep, and why it is
 * cleaned when printed rather than when saved, is on App\Core\Content\SafeHtml.
 */
class SafeHtmlTest extends TestCase
{
    public function test_scripts_handlers_and_script_links_are_removed(): void
    {
        $clean = $this->clean('<p>Hi</p><script>alert(1)</script><img src="/a.jpg" onerror="alert(1)"><a href="javascript:alert(1)">x</a>');

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('<p>Hi</p>', $clean);
        $this->assertStringContainsString('src="/a.jpg"', $clean);
    }

    /** Alpine and HTMX run on every storefront page, so their attributes are code too. */
    public function test_alpine_and_htmx_attributes_are_removed(): void
    {
        $clean = $this->clean('<div x-data="{a: 1}" x-init="alert(1)" @click="alert(2)" :class="a" hx-get="/admin" hx-on:click="alert(3)">x</div>');

        foreach (['x-data', 'x-init', '@click', ':class', 'hx-get', 'hx-on'] as $attribute) {
            $this->assertStringNotContainsString($attribute, $clean);
        }
    }

    public function test_forms_and_styles_go_and_the_looks_of_a_block_stay(): void
    {
        $clean = $this->clean('<div data-html-block class="grid gap-2" style="color: red"><style>body{display:none}</style><form action="/x"><input name="a"></form><table><tr><td colspan="2">x</td></tr></table></div>');

        $this->assertStringNotContainsString('<style', $clean);
        $this->assertStringNotContainsString('<form', $clean);
        $this->assertStringNotContainsString('<input', $clean);
        $this->assertStringContainsString('data-html-block', $clean);
        $this->assertStringContainsString('class="grid gap-2"', $clean);
        $this->assertStringContainsString('style="color: red"', $clean);
        $this->assertStringContainsString('colspan="2"', $clean);
    }

    /** What the editor itself writes survives — or the next edit would lose it. */
    public function test_the_editors_own_marks_survive(): void
    {
        $clean = $this->clean('<div data-gallery><img src="/a.jpg" data-size="wide" alt="a"></div><h2 id="part">P</h2><details open><summary>Q</summary>A</details>');

        $this->assertStringContainsString('data-gallery', $clean);
        $this->assertStringContainsString('data-size="wide"', $clean);
        $this->assertStringContainsString('id="part"', $clean);
        $this->assertStringContainsString('<details open>', $clean);
    }

    /** Placeholders are expanded after cleaning, so they must come through it whole — attributes too. */
    public function test_placeholders_come_through_intact(): void
    {
        $clean = $this->clean('<p>{form:callback}</p><a href="tel:{phone}">{phone}</a><a href="{catalog_url}">c</a>');

        $this->assertStringContainsString('{form:callback}', $clean);
        $this->assertStringContainsString('href="tel:{phone}"', $clean);
        $this->assertStringContainsString('href="{catalog_url}"', $clean);
    }

    public function test_a_frame_stays_only_from_an_allowed_site_over_https(): void
    {
        $clean = $this->clean(
            '<iframe src="https://www.youtube.com/embed/abc" allowfullscreen></iframe>'
            .'<iframe src="https://evil.example/x"></iframe>'
            .'<iframe src="http://www.youtube.com/embed/x"></iframe>'
        );

        $this->assertStringContainsString('https://www.youtube.com/embed/abc', $clean);
        $this->assertStringNotContainsString('evil.example', $clean);
        $this->assertStringNotContainsString('http://www.youtube.com', $clean);
        $this->assertSame(1, substr_count($clean, '<iframe'), 'a refused frame leaves no empty box behind');
    }

    public function test_the_shop_names_the_sites_a_frame_may_come_from(): void
    {
        app(SettingsManager::class)->set('core', SafeHtml::SETTING, "https://maps.example.org/\n");

        $clean = (new SafeHtml)->clean('<iframe src="https://maps.example.org/embed?x=1"></iframe><iframe src="https://www.youtube.com/embed/abc"></iframe>');

        $this->assertStringContainsString('maps.example.org/embed', $clean);
        $this->assertStringNotContainsString('youtube', $clean);
    }

    public function test_plain_text_passes_untouched(): void
    {
        $this->assertSame('Fish & chips, 2 > 1', $this->clean('Fish & chips, 2 > 1'));
        $this->assertSame('', $this->clean(null));
    }

    public function test_the_product_page_prints_its_description_cleaned(): void
    {
        Product::create([
            'sku' => 'SAFE-1', 'slug' => 'safe-product', 'name' => ['en' => 'Safe product'],
            'description' => ['en' => '<p>Good words</p><script>steal()</script><img src="/x.jpg" onerror="steal()">'],
            'price' => 10_00, 'stock' => 3, 'status' => 'active',
        ]);

        $this->get('http://localhost/product/safe-product')
            ->assertOk()
            ->assertSee('Good words')
            ->assertDontSee('steal()', false);
    }

    public function test_a_page_prints_its_body_cleaned(): void
    {
        Page::create([
            'slug' => 'safe-page', 'title' => ['en' => 'Safe page'],
            'body' => ['en' => '<p>Kind words</p><div x-data="{}" x-init="steal()">x</div>'],
            'enabled' => true,
        ]);

        $this->get('http://localhost/safe-page')
            ->assertOk()
            ->assertSee('Kind words')
            ->assertDontSee('steal()', false);
    }

    protected function clean(?string $html): string
    {
        return app(SafeHtml::class)->clean($html);
    }
}
