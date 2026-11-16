<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * The shop's own error pages (audit 2026-10-15: the framework's were shown).
 * A dead link inside the storefront keeps the visitor in the shop — header,
 * search, a way home; a server error and the admin get a page that reads no
 * table; an expired form says what happened and leads back to it.
 */
class ErrorPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        Route::get('/_error-probe', fn () => throw new RuntimeException('boom'));
        Route::middleware('web')->get('/_expired-probe', fn () => abort(419));
    }

    public function test_a_dead_link_in_the_storefront_stays_in_the_shop(): void
    {
        $body = $this->get('http://localhost/no-such-page-anywhere')->assertNotFound()->getContent();

        $this->assertStringContainsString('<title>Page not found', $body);
        $this->assertStringContainsString('name="q"', $body, 'a search to go on from');
        $this->assertStringContainsString('<meta name="csrf-token"', $body, 'drawn in the storefront layout');
        $this->assertSame(1, substr_count($body, '<h1'));
    }

    /**
     * A scanner's address is not a visitor's dead link: `/.env` was served the
     * whole storefront — 26 queries a request on a live shop (2026-11-16).
     */
    public function test_an_address_no_person_typed_gets_the_bare_page(): void
    {
        foreach (['/.env', '/src/.env', '/.git/config', '/wp-login.php', '/wp-admin/setup', '/backup.zip', '/ru/old.php', '/images/gone.jpg'] as $address) {
            $body = $this->get('http://localhost'.$address)->assertNotFound()->getContent();

            // The words are in the address's language (`/ru/…`); the page is the same.
            $this->assertStringContainsString('<meta name="robots" content="noindex">', $body, $address);
            $this->assertStringNotContainsString('<meta name="csrf-token"', $body, $address.' is not drawn in the storefront layout');
            $this->assertStringNotContainsString('name="q"', $body, $address);
        }

        // Counted from a second request: the first warms the per-request memos.
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->get('http://localhost/api/.env.production')->assertNotFound();

        $this->assertLessThanOrEqual(8, $queries, 'a scanner costs the lookups that decline it, not a page');
    }

    public function test_what_a_page_of_the_shop_may_be_called_is_never_stray(): void
    {
        foreach (['', 'delivery', 'info/delivery.html', 'product/phone.md', 'catalog/v2.0', '.well-known/security.txt-page', 'ru/about'] as $path) {
            $this->assertFalse(\App\Core\Http\StrayAddress::matches($path), $path);
        }

        foreach (['.env', 'a/.git/HEAD', 'WP-Login.PHP', 'x/%2Eenv', 'cgi-bin/test', 'dump.sql.gz'] as $path) {
            $this->assertTrue(\App\Core\Http\StrayAddress::matches($path), $path);
        }
    }

    public function test_a_server_error_gets_a_page_that_reads_nothing(): void
    {
        $body = $this->get('http://localhost/_error-probe')->assertStatus(500)->getContent();

        $this->assertStringContainsString('Something went wrong', $body);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $body);
        $this->assertStringNotContainsString('<meta name="csrf-token"', $body);
        $this->assertStringNotContainsString('boom', $body);
    }

    public function test_the_admin_gets_the_plain_page_with_the_way_to_its_dashboard(): void
    {
        $this->get('http://localhost/admin/no-such-screen')->assertNotFound()
            ->assertSee('Page not found')->assertSee('http://localhost/admin', false)->assertDontSee('name="q"', false);
    }

    public function test_an_expired_form_leads_back_to_it(): void
    {
        $this->get('http://localhost/_expired-probe', ['Referer' => 'http://localhost/account/login'])
            ->assertStatus(419)->assertSee('The form has expired')
            ->assertSee('href="http://localhost/account/login" class="btn btn-primary"', false);
    }
}
