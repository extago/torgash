<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Pages\Models\Page;

/**
 * The two files addressed to programs: robots.txt (what a crawler may do) and
 * llms.txt (where everything is).
 *
 * Both used to be impossible: robots.txt was a two-line file in `public/` and
 * there was no map at all. What is pinned here is mostly about the ONE
 * decision the shop owner takes — assistants yes, training crawlers no — and
 * about the robots.txt rule that is easy to get wrong in a way nobody notices:
 * a named group replaces the star group entirely, so an invited agent that is
 * not handed the disallows again has been handed the admin path.
 */
class MachineReadersTest extends TestCase
{
    protected SettingsManager $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsManager::class);
    }

    public function test_robots_is_composed_and_hides_what_no_index_should_hold(): void
    {
        $robots = $this->get('http://localhost/robots.txt')->assertOk();

        $robots->assertHeader('Content-Type', 'text/plain; charset=utf-8');

        $body = $robots->getContent();

        $this->assertStringContainsString("User-agent: *\n", $body);
        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringContainsString('Disallow: /account', $body);

        // Every language shape, because /ru/account is a different address to
        // a crawler and the wildcard that would cover both is an extension.
        $this->assertStringContainsString('Disallow: /ru/account', $body);

        // Answered by the modules that own those addresses — core names none
        // of them.
        $this->assertStringContainsString('Disallow: /cart', $body);
        $this->assertStringContainsString('Disallow: /checkout', $body);
        $this->assertStringContainsString('Disallow: /search', $body);
        $this->assertStringContainsString('Sitemap: '.route('catalog.sitemap'), $body);
    }

    /**
     * The engine's opinion, and the only one it has: an assistant fetching a
     * page for somebody sends a customer back, a training crawler does not and
     * cannot be undone.
     */
    public function test_assistants_are_invited_and_training_crawlers_are_not_by_default(): void
    {
        $body = $this->get('http://localhost/robots.txt')->getContent();

        $assistants = $this->group($body, 'ChatGPT-User');
        $training = $this->group($body, 'GPTBot');

        $this->assertStringContainsString('Allow: /', $assistants);
        $this->assertSame(['Disallow: /'], array_values(array_filter(explode("\n", $training))));
    }

    /**
     * The subtle one. A named group REPLACES the star group for that agent, so
     * an allowed assistant has to be handed the same disallows again — without
     * this, inviting one agent opens /admin to it alone.
     */
    public function test_an_invited_agent_is_told_the_same_disallows_again(): void
    {
        $body = $this->get('http://localhost/robots.txt')->getContent();

        $this->assertStringContainsString('Disallow: /admin', $this->group($body, 'ChatGPT-User'));
    }

    public function test_the_shop_can_change_its_mind_about_either_audience(): void
    {
        $this->settings->set('core', 'ai_assistants', 'deny');
        $this->settings->set('core', 'ai_training', 'allow');

        $body = $this->get('http://localhost/robots.txt')->getContent();

        $this->assertSame(['Disallow: /'], array_values(array_filter(explode("\n", $this->group($body, 'Claude-User')))));
        $this->assertStringContainsString('Allow: /', $this->group($body, 'CCBot'));
    }

    /** The file is gone from disk, so a rule the engine never heard of needs somewhere to live. */
    public function test_extra_lines_are_appended_verbatim(): void
    {
        $this->settings->set('core', 'robots_extra', 'Crawl-delay: 10');

        $this->get('http://localhost/robots.txt')->assertSee('Crawl-delay: 10', false);
    }

    public function test_the_map_names_the_shop_and_what_each_module_publishes(): void
    {
        $this->settings->set('core', 'llms_summary', 'A shop that sells laptops.');

        $body = $this->get('http://localhost/llms.txt')->assertOk()->getContent();

        $this->assertStringStartsWith('# ', $body);
        $this->assertStringContainsString('> A shop that sells laptops.', $body);

        // The catalogue's own answer, including the convention that teaches
        // every product address at once.
        $this->assertStringContainsString(route('catalog.sitemap'), $body);
        $this->assertStringContainsString('.md', $body);

        // The API describes itself; the map only says where that description is.
        $this->assertStringContainsString(route('api.openapi'), $body);

        // House rules, derived from the same settings robots.txt is built from
        // rather than typed a second time.
        $this->assertStringContainsString('60 requests per minute', $body);
    }

    /**
     * A map handed to readers the rules file has just turned away is the shop
     * saying two different things at the same address.
     */
    public function test_there_is_no_map_when_nobody_is_invited(): void
    {
        $this->settings->set('core', 'ai_assistants', 'deny');
        $this->settings->set('core', 'ai_training', 'deny');

        $this->get('http://localhost/llms.txt')->assertNotFound();
    }

    /**
     * The text half of the catalogue is one more way to download all of it, so
     * it is limited the way the API is — per address, since these callers have
     * no token.
     */
    public function test_the_text_pages_are_rate_limited(): void
    {
        $this->settings->set('core', 'agent_rate_limit', 1);

        Product::create([
            'sku' => 'LIMIT-1', 'name' => ['en' => 'Limited'], 'slug' => 'limited',
            'price' => 100_00, 'stock' => 1, 'status' => 'active',
        ]);

        $this->get('http://localhost/product/limited.md')->assertOk();
        $this->get('http://localhost/product/limited.md')->assertStatus(429);

        // The HTML page is untouched by it: this limit is about the machine
        // half, and throttling the shop itself is a different decision.
        $this->get('http://localhost/product/limited')->assertOk();
    }

    /**
     * A page at a root address is answered by a FALLBACK route, which cannot
     * carry route middleware — so the same limiter is invoked from inside the
     * controller. Pinned because "the limit is on everything except the pages"
     * is exactly the hole nobody would notice.
     */
    public function test_the_limit_reaches_a_page_answered_by_the_fallback_route(): void
    {
        $this->settings->set('core', 'agent_rate_limit', 1);

        Page::create([
            'title' => ['en' => 'Delivery'], 'body' => ['en' => '<p>We ship.</p>'],
            'slug' => 'delivery-limits', 'enabled' => true, 'in_sitemap' => true,
        ]);

        $this->get('http://localhost/delivery-limits.md')->assertOk()->assertSee('We ship.', false);
        $this->get('http://localhost/delivery-limits.md')->assertStatus(429);
        $this->get('http://localhost/delivery-limits')->assertOk();
    }

    /** The lines of the group a named agent belongs to, without its User-agent lines. */
    protected function group(string $robots, string $agent): string
    {
        $blocks = preg_split("/\n{2,}/", $robots) ?: [];

        foreach ($blocks as $block) {
            if (str_contains($block, 'User-agent: '.$agent."\n") || str_ends_with($block, 'User-agent: '.$agent)) {
                return implode("\n", array_filter(
                    explode("\n", $block),
                    fn ($line) => ! str_starts_with($line, 'User-agent:') && ! str_starts_with($line, '#'),
                ));
            }
        }

        $this->fail("robots.txt has no group naming {$agent}");
    }
}
