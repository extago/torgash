<?php

namespace Tests\Feature\Core;

use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;

/**
 * The account's side column: the page you are on is marked — under a
 * language prefix too, where the route is called `ru.account.dashboard` and
 * a bare `routeIs('account.dashboard')` marked nothing.
 */
class AccountNavTest extends TestCase
{
    public function test_the_current_page_is_marked_with_and_without_a_language_prefix(): void
    {
        $storefront = Storefront::query()->firstOrFail();
        $storefront->update(['locales' => array_values(array_unique([...($storefront->locales ?? []), 'en', 'ru']))]);
        $user = User::factory()->create();

        $this->get('http://localhost/');

        foreach (['http://localhost/account', 'http://localhost/ru/account'] as $url) {
            $html = $this->nav($this->actingAs($user)->get($url)->assertOk()->getContent());

            $this->assertSame(1, substr_count($html, 'aria-current="page"'), $url);
            $this->assertMatchesRegularExpression('#<a href="[^"]*/account"\s+aria-current="page"#', $html, $url);
        }

        $orders = $this->nav($this->actingAs($user)->get('http://localhost/ru/account/orders')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('#<a href="[^"]*/account/orders"\s+aria-current="page"#', $orders);
    }

    /** The column alone: the phone's tab bar marks the account too. */
    protected function nav(string $html): string
    {
        $this->assertSame(1, preg_match('#<aside class="torgash-account-nav.*?</aside>#s', $html, $m));

        return $m[0];
    }
}
