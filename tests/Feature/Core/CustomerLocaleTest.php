<?php

namespace Tests\Feature\Core;

use App\Models\Storefront;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A customer's language is kept on the account (CustomerLocale): set when the
 * account is first seen, changed when they SWITCH within a session — never by
 * a fresh session landing on the bare domain — and followed after signing in
 * on any device, once.
 */
class CustomerLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $storefront = Storefront::query()->firstOrFail();
        $storefront->update(['locales' => array_values(array_unique([...($storefront->locales ?? []), 'en', 'ru'])), 'default_locale' => 'en']);
    }

    public function test_the_language_is_remembered_on_a_switch_and_followed_after_signing_in(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.com', 'password' => Hash::make('secret-pass')]);

        // First seen: the language it is seen in.
        $this->actingAs($user)->get('http://localhost/account')->assertOk();
        $this->assertSame('en', $user->fresh()->locale);

        // A switch within the session.
        $this->actingAs($user)->get('http://localhost/ru/account')->assertOk();
        $this->assertSame('ru', $user->fresh()->locale);
        $this->assertSame('ru', $user->fresh()->preferredLocale());

        // Another device: a fresh session on the bare domain is not a choice.
        $this->flushSession();
        $this->actingAs($user)->get('http://localhost/account')->assertOk();
        $this->assertSame('ru', $user->fresh()->locale);

        // Signing in there: the next page follows the account's language — once.
        $this->flushSession();
        auth()->logout();
        $this->post('http://localhost/account/login', ['email' => 'reader@example.com', 'password' => 'secret-pass'])->assertRedirect();
        $this->get('http://localhost/account/orders')->assertRedirect('http://localhost/ru/account/orders');
        $this->get('http://localhost/account/orders')->assertOk();
    }
}
