<?php

namespace Tests\Feature\Core;

use App\Models\Storefront;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The default storefront serves every address nobody else claims, so no door
 * may switch it off, un-default it or delete it; a domain answers for one
 * storefront only; and the screen is behind a permission of its own.
 */
class StorefrontsScreenTest extends TestCase
{
    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    protected function main(): Storefront
    {
        return Storefront::where('is_default', true)->firstOrFail();
    }

    /** @return array<string, mixed> */
    protected function payload(Storefront $storefront, array $overrides = []): array
    {
        return $overrides + [
            'code' => $storefront->code,
            'name' => $storefront->name,
            'domains' => implode(', ', $storefront->domains ?? []),
            'theme' => $storefront->theme ?: 'default',
            'default_locale' => $storefront->default_locale,
            'locales' => $storefront->locales,
            'default_currency' => $storefront->default_currency,
            'currencies' => $storefront->currencies,
            'enabled' => 1,
        ];
    }

    protected function second(array $attributes = []): Storefront
    {
        $main = $this->main();

        return Storefront::create($attributes + [
            'code' => 'second',
            'name' => 'Second',
            'domains' => ['second.test'],
            'theme' => $main->theme,
            'default_locale' => $main->default_locale,
            'locales' => [$main->default_locale],
            'default_currency' => $main->default_currency,
            'currencies' => [$main->default_currency],
            'is_default' => false,
            'enabled' => true,
        ]);
    }

    public function test_the_default_storefront_cannot_be_switched_off_undefaulted_or_deleted(): void
    {
        $main = $this->main();

        $this->actingAs($this->admin())
            ->patch('http://localhost/admin/storefronts/'.$main->id.'/status', ['enabled' => 0])
            ->assertStatus(422);

        $this->actingAs($this->admin())
            ->delete('http://localhost/admin/storefronts/'.$main->id)
            ->assertSessionHasErrors('code');

        // A form posted with both boxes unticked keeps it the default and on.
        $payload = $this->payload($main);
        unset($payload['enabled']);
        $this->actingAs($this->admin())
            ->put('http://localhost/admin/storefronts/'.$main->id, $payload)
            ->assertRedirect(route('admin.storefronts.edit', $main));

        $main->refresh();
        $this->assertTrue($main->is_default);
        $this->assertTrue($main->enabled);
    }

    public function test_the_default_moves_in_one_click_and_the_old_one_can_then_go(): void
    {
        $main = $this->main();
        $second = $this->second(['enabled' => false]);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/storefronts/'.$second->id.'/default')
            ->assertRedirect(route('admin.storefronts.index'));

        $this->assertTrue($second->fresh()->is_default);
        $this->assertTrue($second->fresh()->enabled);
        $this->assertFalse($main->fresh()->is_default);

        $this->actingAs($this->admin())
            ->patch('http://localhost/admin/storefronts/'.$main->id.'/status', ['enabled' => 0])
            ->assertOk()
            ->assertSee('storefront-status-'.$main->id, false);
    }

    public function test_domains_are_cleaned_and_one_domain_belongs_to_one_storefront(): void
    {
        $main = $this->main();
        $second = $this->second();

        $this->actingAs($this->admin())
            ->put('http://localhost/admin/storefronts/'.$second->id, $this->payload($second, [
                'domains' => 'https://Shop2.test/, www.shop2.test',
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['shop2.test', 'www.shop2.test'], $second->fresh()->domains);

        $this->actingAs($this->admin())
            ->put('http://localhost/admin/storefronts/'.$second->id, $this->payload($second, [
                'domains' => 'shop2.test, '.$main->domains[0],
            ]))
            ->assertSessionHasErrors('domains');
    }

    public function test_the_default_language_and_currency_are_always_offered(): void
    {
        $second = $this->second();

        $this->actingAs($this->admin())
            ->put('http://localhost/admin/storefronts/'.$second->id, $this->payload($second, [
                'locales' => [], 'currencies' => [],
            ]))
            ->assertSessionHasNoErrors();

        $second->refresh();
        $this->assertSame([$second->default_locale], $second->locales);
        $this->assertSame([$second->default_currency], $second->currencies);
    }

    /** The home page's title and description are the storefront's, one language a save, and the home page shows them. */
    public function test_the_home_pages_words_are_the_storefronts(): void
    {
        $main = $this->main();

        $this->actingAs($this->admin())
            ->put('http://localhost/admin/storefronts/'.$main->id, $this->payload($main, [
                'home_title' => 'Gadgets with a warranty',
                'home_description' => 'Phones and laptops, delivered tomorrow.',
            ]))->assertRedirect();

        $this->assertSame(['en' => 'Gadgets with a warranty'], $main->fresh()->home_title);

        auth()->logout();
        $page = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Gadgets with a warranty</title>', $page);
        $this->assertStringContainsString('<meta name="description" content="Phones and laptops, delivered tomorrow.">', $page);
    }

    public function test_the_screen_needs_its_own_permission(): void
    {
        $role = Role::findOrCreate('storefront-less');
        $role->syncPermissions(['admin.access']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->get('http://localhost/admin/storefronts')->assertForbidden();

        $role->givePermissionTo('storefronts.manage');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh())->get('http://localhost/admin/storefronts')->assertOk();
    }
    public function test_more_phones_and_social_networks_are_lists_with_tokens_of_their_own(): void
    {
        $main = $this->main();

        $this->actingAs($this->admin())
            ->put('http://localhost/admin/storefronts/'.$main->id, $this->payload($main, ['contacts' => [
                'phone' => '+380 44 393 31 47',
                'phones' => [
                    ['number' => '+380 67 000 00 01', 'label' => 'Kyivstar'],
                    ['number' => '', 'label' => 'A row left empty'],
                ],
                'socials' => [
                    ['url' => 'https://t.me/torgash', 'network' => ''],
                    ['url' => 'https://www.instagram.com/torgash', 'network' => ''],
                    ['url' => 'https://example.org/our-page', 'network' => 'Mastodon!'],
                ],
            ]]))
            ->assertRedirect();

        $main->refresh();
        // The empty row is dropped; a network left blank is read off the address.
        $this->assertSame([['number' => '+380 67 000 00 01', 'label' => 'Kyivstar']], $main->contactList('phones'));
        $this->assertSame(['telegram', 'instagram', 'mastodon'], array_column($main->contactList('socials'), 'network'));
        $this->assertSame('+380 44 393 31 47', $main->contact('phone'));
        $this->assertSame('', $main->contact('phones'));

        $this->get('http://localhost/');
        $phones = expand_placeholders('{phones}');
        $this->assertStringContainsString('href="tel:+380670000001"', $phones);
        $this->assertStringContainsString('Kyivstar', $phones);

        $socials = expand_placeholders('{socials}');
        $this->assertStringContainsString('href="https://t.me/torgash"', $socials);
        $this->assertStringContainsString('aria-label="Telegram"', $socials);
        // Every link carries a mark: a brand's from the blocks module, a globe for one it lacks.
        $this->assertSame(3, substr_count($socials, '<svg'));

        // A shop that filled in neither: both tokens print nothing.
        $main->update(['contacts' => ['phone' => '+380 44 393 31 47']]);
        $this->get('http://localhost/');
        $this->assertSame('', trim(expand_placeholders('{phones}{socials}')));
    }
}
