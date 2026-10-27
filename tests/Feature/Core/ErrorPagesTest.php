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
