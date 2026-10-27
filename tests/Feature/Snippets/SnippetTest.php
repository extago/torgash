<?php

namespace Tests\Feature\Snippets;

use App\Models\User;
use Tests\TestCase;
use Torgash\Snippets\Models\Snippet;
use Torgash\Snippets\Placements;

/**
 * A counter, a pixel, a chat widget: code the shop pastes in, printed verbatim
 * where it asked for it and only on the pages it named.
 *
 * What is worth a test each: that the code really is untouched (an engine that
 * tidies a provider's snippet breaks it), that the display rules decide where
 * it appears, that it never reaches the admin panel, and that the tokens on an
 * order's own page answer with numbers a machine can read rather than with
 * "1 200 ₴".
 */
class SnippetTest extends TestCase
{
    protected function snippet(array $attributes = []): Snippet
    {
        app(Placements::class)->flush();

        return Snippet::create($attributes + [
            'name' => 'Counter',
            'placement' => 'head',
            'code' => '<script>console.log(1)</script>',
            'enabled' => true,
            'rules_match' => 'all',
        ]);
    }

    public function test_the_code_reaches_the_page_exactly_as_written(): void
    {
        // Quotes, braces, a template literal and an array of objects — all the
        // shapes an engine might be tempted to rewrite on the way through.
        $code = '<script>var a=[{ id: 1 }]; var s=`${a[0].id}`; if (a) { console.log("x & y"); }</script>';

        $this->snippet(['code' => $code]);

        $body = $this->get('http://localhost/catalog')->assertOk()->getContent();

        // Exactly as written, plus only this page's nonce (PLAN §53).
        $this->assertStringContainsString('<script nonce="'.csp_nonce().'">', $body);
        $this->assertStringContainsString($code, (string) preg_replace('/ nonce="[^"]*"/', '', $body));
    }

    public function test_each_slot_prints_in_its_own_place(): void
    {
        $this->snippet(['placement' => 'head', 'code' => '<meta name="head-slot" content="1">']);
        $this->snippet(['placement' => 'body_start', 'code' => '<!--body-slot-->']);
        $this->snippet(['placement' => 'footer', 'code' => '<!--footer-slot-->']);

        $body = $this->get('http://localhost/catalog')->assertOk()->getContent();

        $head = substr($body, 0, (int) strpos($body, '</head>'));

        $this->assertStringContainsString('head-slot', $head);
        $this->assertStringNotContainsString('body-slot', $head);
        $this->assertLessThan(strpos($body, '<header'), strpos($body, '<!--body-slot-->'));
        $this->assertGreaterThan(strpos($body, '<footer'), strpos($body, '<!--footer-slot-->'));
    }

    /** "The pixel only on the thank-you page" — core's rule engine, no new entity. */
    public function test_a_rule_decides_which_pages_it_appears_on(): void
    {
        $this->snippet([
            'code' => '<!--only-on-catalogue-->',
            'rules' => [['kind' => 'route', 'operator' => 'is', 'value' => 'catalog.index']],
        ]);

        $this->get('http://localhost/catalog')->assertOk()->assertSee('only-on-catalogue', false);
        $this->get('http://localhost/')->assertOk()->assertDontSee('only-on-catalogue', false);
    }

    public function test_a_disabled_snippet_prints_nothing(): void
    {
        $this->snippet(['code' => '<!--switched-off-->', 'enabled' => false]);

        $this->get('http://localhost/catalog')->assertOk()->assertDontSee('switched-off', false);
    }

    /**
     * The admin has no such slots, which is what makes "everywhere except the
     * admin panel" cost nothing to express.
     */
    public function test_nothing_reaches_the_admin_panel(): void
    {
        $this->snippet(['code' => '<!--storefront-only-->']);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin')->assertOk()
            ->assertDontSee('storefront-only', false);
    }

    /** A second storefront's counter is not this storefront's. */
    public function test_a_snippet_bound_to_another_storefront_stays_there(): void
    {
        $this->snippet(['code' => '<!--other-shop-->', 'storefront_id' => 999]);

        $this->get('http://localhost/catalog')->assertOk()->assertDontSee('other-shop', false);
    }

    public function test_the_admin_screens_render_and_save(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/snippets')->assertOk();
        $this->actingAs($admin)->get('http://localhost/admin/snippets/create')->assertOk();

        $this->actingAs($admin)->post('http://localhost/admin/snippets', [
            'name' => 'Pixel',
            'placement' => 'footer',
            'code' => '<script>pixel()</script>',
            'enabled' => '1',
            'rules' => [['kind' => 'route', 'operator' => 'is', 'value' => 'catalog.index']],
            'rules_match' => 'all',
        ])->assertRedirect();

        $snippet = Snippet::firstWhere('name', 'Pixel');
        // Saving stays on the form, as every edit form in the admin does.

        $this->assertSame('<script>pixel()</script>', $snippet->code);
        $this->assertSame('footer', $snippet->placement);
        $this->assertSame('catalog.index', $snippet->rules[0]['value']);

        $this->actingAs($admin)->get('http://localhost/admin/snippets/'.$snippet->id.'/edit')->assertOk();
    }

    /** Code printed verbatim is a permission, not a convenience. */
    public function test_the_screen_needs_its_own_permission(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get('http://localhost/admin/snippets')->assertForbidden();
    }
}
