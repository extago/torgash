<?php

namespace Tests\Feature\Core;

use App\Core\Seo\RedirectManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Old addresses and where they lead now — the table that has quietly filled
 * itself since August, and which nobody could see, add to or hand a file until
 * now.
 *
 * The thing worth pinning beyond the screen: a redirect for an address NO
 * module claims. A move from another engine is a list of `/index.php?dispatch=…`
 * addresses that match no route at all, so the answer cannot live in a
 * controller's own 404 — it has to be the last word after everything has
 * declined.
 */
class RedirectsTest extends TestCase
{
    protected function redirects(): RedirectManager
    {
        return app(RedirectManager::class);
    }

    public function test_an_address_nobody_claims_still_redirects(): void
    {
        $this->redirects()->put('/old-shop/page', '/catalog');

        $this->get('http://localhost/old-shop/page')
            ->assertRedirect('/catalog')
            ->assertStatus(301);
    }

    /** An address from another engine is mostly query string, and that is part of it. */
    public function test_the_query_string_is_part_of_the_address(): void
    {
        $this->redirects()->put('/index.php?dispatch=products.view&product_id=12', '/product/laptop');

        $this->get('http://localhost/index.php?dispatch=products.view&product_id=12')
            ->assertRedirect('/product/laptop');

        // ...and the same path without it is a different page, so it is not
        // caught by the rule above.
        $this->get('http://localhost/index.php')->assertNotFound();
    }

    /**
     * The shape that never 404s, and the reason it is worth its own rule: a
     * front controller is stripped before PHP sees the address, so
     * `/index.php?dispatch=…` arrives as the home page and answers 200. Every
     * link the old shop earned is of exactly this shape.
     */
    public function test_the_old_shops_front_controller_is_redirected_too(): void
    {
        $this->redirects()->put('/index.php?dispatch=categories.view&category_id=3', '/catalog');

        $this->get('http://localhost/?dispatch=categories.view&category_id=3')
            ->assertRedirect('/catalog')
            ->assertStatus(301);

        // The home page with anything else on it is still the home page.
        $this->get('http://localhost/?utm_source=newsletter')->assertOk();
    }

    public function test_an_address_with_no_row_is_still_a_404(): void
    {
        $this->get('http://localhost/nothing-here-at-all')->assertNotFound();
    }

    /** A full URL pasted from the address bar is stored as a path, or it never matches. */
    public function test_a_pasted_url_is_reduced_to_its_path(): void
    {
        $this->redirects()->put('http://localhost/pasted-in', '/catalog');

        $this->assertSame('/catalog', $this->redirects()->resolve('/pasted-in'));
    }

    /** ...but a destination on another site keeps its host: that is a real answer. */
    public function test_an_external_destination_keeps_its_host(): void
    {
        $this->redirects()->put('/gone', 'https://example.test/elsewhere');

        $this->assertSame('https://example.test/elsewhere', $this->redirects()->resolve('/gone'));
    }

    public function test_an_address_may_not_redirect_to_itself(): void
    {
        $this->assertFalse($this->redirects()->put('/loop', '/loop'));
        $this->assertNull($this->redirects()->resolve('/loop'));
    }

    /** Chains are collapsed, because search engines penalise them. */
    public function test_a_chain_is_collapsed(): void
    {
        $this->redirects()->put('/a', '/b');
        $this->redirects()->put('/b', '/c');

        $this->assertSame('/c', $this->redirects()->resolve('/a'));
        $this->assertSame('/c', $this->redirects()->resolve('/b'));
    }

    public function test_a_list_can_be_imported(): void
    {
        $result = $this->redirects()->import(
            "from,to\n/one,/catalog\n/two;/catalog\n\n# a comment\n/three,/three\n"
        );

        $this->assertSame(2, $result['imported']);
        // The self-referencing pair is refused rather than stored.
        $this->assertSame(1, $result['refused']);
        $this->assertSame('/catalog', $this->redirects()->resolve('/one'));
        $this->assertSame('/catalog', $this->redirects()->resolve('/two'));
    }

    public function test_the_screen_lists_adds_and_removes(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->post('http://localhost/admin/redirects', [
            'from_path' => '/typed-in',
            'to_path' => '/catalog',
        ])->assertRedirect();

        $this->actingAs($admin)->get('http://localhost/admin/redirects')
            ->assertOk()->assertSee('/typed-in');

        $id = DB::table('redirects')->where('from_path', '/typed-in')->value('id');

        $this->actingAs($admin)->delete('http://localhost/admin/redirects/'.$id)->assertRedirect();

        $this->assertNull($this->redirects()->resolve('/typed-in'));
    }

    public function test_the_screen_imports_a_pasted_list(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/redirects/import', ['pasted' => "/from-a,/catalog\n/from-b,/catalog"])
            ->assertRedirect();

        $this->assertSame('/catalog', $this->redirects()->resolve('/from-a'));
        $this->assertSame('/catalog', $this->redirects()->resolve('/from-b'));
    }
}
