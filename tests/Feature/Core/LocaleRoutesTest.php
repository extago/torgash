<?php

namespace Tests\Feature\Core;

use App\Models\Storefront;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Storefront routes are registered in every locale shape up front — bare plus
 * one prefixed copy per non-default locale — and never from the current
 * request. That is what keeps route:cache and persistent workers possible: a
 * route table shaped by whoever happened to arrive first would serve the next
 * visitor another storefront's language.
 *
 * These tests are the guard rail for that property, because breaking it looks
 * completely fine in a single-request dev run.
 */
class LocaleRoutesTest extends TestCase
{
    public function test_both_shapes_exist_in_the_route_table(): void
    {
        $this->assertTrue(Route::has('catalog.index'), 'the bare shape is missing');
        $this->assertTrue(Route::has('ru.catalog.index'), 'the ru copy is missing');
    }

    public function test_both_shapes_answer(): void
    {
        $this->get('/catalog')->assertOk();
        $this->get('/ru/catalog')->assertOk();
    }

    public function test_the_page_renders_in_the_locale_its_url_asked_for(): void
    {
        $this->get('/ru/catalog')->assertOk()->assertSee('lang="ru"', false);
        $this->get('/catalog')->assertOk()->assertSee('lang="en"', false);
    }

    /**
     * The whole point of the named copies: a template just calls
     * route('catalog.index') and gets back the shape the visitor came in as.
     */
    public function test_links_stay_in_the_shape_the_request_arrived_in(): void
    {
        $this->get('/ru/catalog')->assertOk()->assertSee('/ru/catalog', false);

        $bare = $this->get('/catalog')->assertOk()->getContent();

        // The language switcher is the one link that deliberately points at the
        // other locale, so its presence alone must not count as a leak.
        $this->assertStringNotContainsString('href="http://localhost/ru/catalog?', $bare);
    }

    /**
     * Every locale prefix in the table exists application-wide, but a
     * storefront only offers its own. Serving the same page under a second
     * address would be a duplicate URL the shop never asked for.
     */
    public function test_a_storefront_that_does_not_offer_a_locale_returns_404_for_it(): void
    {
        Storefront::create([
            'code' => 'english-only',
            'name' => 'English only',
            'domains' => ['english.test'],
            'theme' => 'default',
            'default_locale' => 'en',
            'locales' => ['en'],
            'default_currency' => 'USD',
            'currencies' => ['USD'],
            'is_default' => false,
            'enabled' => true,
        ]);

        $this->get('http://english.test/catalog')->assertOk();
        $this->get('http://english.test/ru/catalog')->assertNotFound();

        // ...while the storefront that does offer Russian is unaffected.
        //
        // Spelled out with a host on purpose: the test client turns a relative
        // path into a URL through the application's own url generator, whose
        // root is left over from the previous request — so a bare '/ru/catalog'
        // here would quietly ask english.test again and "prove" the wrong thing.
        $this->get('http://localhost/ru/catalog')->assertOk();
    }
}
