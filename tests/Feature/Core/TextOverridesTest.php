<?php

namespace Tests\Feature\Core;

use App\Core\Translation\TextOverrides;
use App\Models\TextOverride;
use App\Models\User;
use Tests\TestCase;

/**
 * Admin → Texts: the shop's own wording of what the engine prints, read
 * over the lang files in any language, English included; words only, the
 * key's placeholders kept; back to the file on request.
 */
class TextOverridesTest extends TestCase
{
    public function test_the_shops_wording_is_read_over_the_file_in_any_language(): void
    {
        $texts = app(TextOverrides::class);

        $this->assertSame('Бонусные баллы', __('Bonus points', [], 'ru'));
        $this->assertSame('Bonus points', __('Bonus points', [], 'en'));

        $texts->put('ru', 'Bonus points', 'Кешбэк');
        $texts->put('en', 'Bonus points', 'Cashback');

        $this->assertSame('Кешбэк', __('Bonus points', [], 'ru'));
        $this->assertSame('Cashback', __('Bonus points', [], 'en'));
        // A text with a count keeps its forms and its placeholder.
        $texts->put('ru', '+:points point|+:points points', '+:points ₽ кешбэком|+:points ₽ кешбэком|+:points ₽ кешбэком');
        $this->assertSame('+28 ₽ кешбэком', trans_choice('+:points point|+:points points', 28, ['points' => 28], 'ru'));

        $texts->forget('Bonus points');

        $this->assertSame('Бонусные баллы', __('Bonus points', [], 'ru'));
        $this->assertSame('Bonus points', __('Bonus points', [], 'en'));
    }

    public function test_words_only_with_the_keys_placeholders_and_forms(): void
    {
        $texts = app(TextOverrides::class);

        $this->assertNull($texts->refusal('Was: :text', 'Было: :text'));
        $this->assertNull($texts->refusal('Hello :name', 'Привет, :NAME'));
        $this->assertNotNull($texts->refusal('Bonus points', '<b>Кешбэк</b>'));
        $this->assertNotNull($texts->refusal('Was: :text', 'Было'));
        $this->assertNotNull($texts->refusal('Bonus points', 'Кешбэк :sum'));
        $this->assertNotNull($texts->refusal('+:points point|+:points points', '+:points баллов'));
    }

    public function test_the_screen_lists_searches_saves_and_takes_the_file_back(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/texts?q=Bonus+points')
            ->assertOk()->assertSee('Bonus points')->assertSee('Бонусные баллы');

        // English typed as it already is: no row for it; Russian changed: one.
        $this->actingAs($admin)->post('http://localhost/admin/texts', [
            'key' => 'Bonus points', 'action' => 'save',
            'values' => ['en' => 'Bonus points', 'ru' => 'Кешбэк', 'uk' => ''],
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(['ru' => 'Кешбэк'], TextOverride::where('key', 'Bonus points')->pluck('value', 'locale')->all());
        $this->assertSame('Кешбэк', __('Bonus points', [], 'ru'));

        $this->actingAs($admin)->get('http://localhost/admin/texts?changed=1')
            ->assertOk()->assertSee('Кешбэк')->assertSee('badge-primary', false);

        // Markup is refused, and the typed text comes back to be corrected.
        $this->actingAs($admin)->post('http://localhost/admin/texts', [
            'key' => 'Bonus points', 'action' => 'save', 'values' => ['ru' => '<b>Кешбэк</b>'],
        ])->assertRedirect()->assertSessionHasErrors('text');
        $this->assertSame('Кешбэк', __('Bonus points', [], 'ru'));

        $this->actingAs($admin)->post('http://localhost/admin/texts', ['key' => 'Bonus points', 'action' => 'reset'])
            ->assertRedirect();

        $this->assertSame(0, TextOverride::where('key', 'Bonus points')->count());
        $this->assertSame('Бонусные баллы', __('Bonus points', [], 'ru'));
    }

    public function test_a_reader_without_the_right_is_refused(): void
    {
        $this->actingAs(User::factory()->create())->get('http://localhost/admin/texts')->assertForbidden();
    }
}
