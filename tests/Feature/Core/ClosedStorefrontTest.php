<?php

namespace Tests\Feature\Core;

use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;

/**
 * A closed storefront (App\Core\Storefronts\ClosedStorefront): visitors get
 * a 503 notice that search engines read as "come back later", staff get the
 * shop with a strip saying it is closed, the admin's sign-in stays open, and
 * the preview link lets one person in until a new link is made.
 */
class ClosedStorefrontTest extends TestCase
{
    protected function close(array $extra = []): Storefront
    {
        $storefront = Storefront::where('is_default', true)->firstOrFail();
        $storefront->update($extra + ['closed' => true, 'closed_token' => 'secret-preview-token', 'closed_message' => ['en' => 'Back on Monday.']]);

        return $storefront;
    }

    public function test_a_visitor_gets_the_notice_with_a_503(): void
    {
        $this->close();

        $response = $this->get('http://localhost/');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '3600');
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $response->assertSee('Back on Monday.');
        $response->assertDontSee('torgash-storefront', false);
    }

    public function test_the_contacts_are_shown_only_when_asked_for(): void
    {
        $storefront = $this->close();
        $storefront->update(['contacts' => ['phone' => '+380 44 000 00 01']]);

        $this->get('http://localhost/')->assertDontSee('+380 44 000 00 01');

        $storefront->update(['closed_contacts' => true]);

        $this->get('http://localhost/')->assertSee('+380 44 000 00 01');
    }

    public function test_a_page_that_does_not_exist_does_not_show_the_closed_shop_either(): void
    {
        $this->close();

        $response = $this->get('http://localhost/no-such-page-at-all');

        $response->assertNotFound();
        $response->assertDontSee('torgash-storefront', false);
    }

    public function test_staff_see_the_shop_and_are_told_it_is_closed(): void
    {
        $this->close();
        $this->actingAs(User::role('admin')->firstOrFail());

        $response = $this->get('http://localhost/');

        $response->assertOk();
        $response->assertSee('data-staff-bar', false);
    }

    public function test_a_customer_account_does_not_open_it(): void
    {
        $this->close();
        $this->actingAs(User::factory()->create());

        $this->get('http://localhost/')->assertStatus(503);
    }

    public function test_the_admin_sign_in_stays_open(): void
    {
        $this->close();

        $this->get('http://localhost/admin/login')->assertOk();
    }

    public function test_the_preview_link_lets_one_visitor_in_until_a_new_one_is_made(): void
    {
        $storefront = $this->close();

        $this->get('http://localhost/?preview=wrong')->assertStatus(503);

        $this->get('http://localhost/?preview=secret-preview-token&x=1')->assertRedirect('http://localhost?x=1');
        $this->get('http://localhost/')->assertOk();

        $storefront->update(['closed_token' => 'another-one']);

        $this->get('http://localhost/')->assertStatus(503);
    }

    public function test_an_open_storefront_shows_nobody_a_strip(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->get('http://localhost/')->assertOk()->assertDontSee('data-staff-bar', false);
    }

    public function test_the_form_closes_and_opens_it(): void
    {
        $storefront = Storefront::where('is_default', true)->firstOrFail();
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->put('http://localhost/admin/storefronts/'.$storefront->id, [
            'code' => $storefront->code,
            'name' => $storefront->name,
            'domains' => implode(', ', $storefront->domains ?? []),
            'theme' => $storefront->theme ?: 'default',
            'default_locale' => $storefront->default_locale,
            'locales' => $storefront->locales,
            'default_currency' => $storefront->default_currency,
            'currencies' => $storefront->currencies,
            'closed' => 1,
            'closed_message' => 'Soon.',
        ])->assertRedirect();

        $storefront->refresh();
        $this->assertTrue($storefront->closed);
        $this->assertSame('Soon.', pick_translation($storefront->closed_message));
        $this->assertNotEmpty($storefront->closed_token, 'closing makes the preview link');
        $this->assertNotNull($storefront->previewUrl());
    }
}
