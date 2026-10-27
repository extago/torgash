<?php

namespace Tests\Feature\Core;

use App\Models\Language;
use App\Models\User;
use Tests\TestCase;

/**
 * The base language and a storefront's own can be neither switched off nor
 * deleted, and no language's code moves once made.
 */
class LanguagesScreenTest extends TestCase
{
    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    public function test_the_base_language_cannot_be_switched_off_or_deleted(): void
    {
        $base = Language::where('code', config('app.fallback_locale'))->firstOrFail();

        $this->actingAs($this->admin())
            ->patch('http://localhost/admin/languages/'.$base->id.'/status', ['enabled' => 0])
            ->assertStatus(422);

        $this->actingAs($this->admin())
            ->delete('http://localhost/admin/languages/'.$base->id)
            ->assertSessionHasErrors('code');

        $this->actingAs($this->admin())
            ->put('http://localhost/admin/languages/'.$base->id, [
                'code' => $base->code, 'name' => $base->name, 'native_name' => $base->native_name,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($base->fresh()->enabled);
    }

    /**
     * A Russian-only shop wants Russian as its base and English off — the
     * first live shop could do neither (2026-10-22). With one language left,
     * the admin's content switcher has nothing to switch and is gone.
     */
    public function test_the_owner_chooses_the_base_and_can_then_switch_english_off(): void
    {
        $english = Language::where('code', 'en')->firstOrFail();
        $russian = Language::where('code', 'ru')->firstOrFail();
        \App\Models\Storefront::query()->update(['default_locale' => 'ru', 'locales' => ['ru']]);
        Language::whereNotIn('code', ['en', 'ru'])->update(['enabled' => false]);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/languages/'.$russian->id.'/base')
            ->assertRedirect();

        $this->assertSame('ru', content_base_locale());

        $this->patch('http://localhost/admin/languages/'.$english->id.'/status', ['enabled' => 0])->assertOk();
        $this->assertFalse($english->fresh()->enabled);

        // The admin had been writing in English; that language is gone, so
        // its forms write the base.
        $this->withSession(['admin_content_locale' => 'en']);
        $this->get('http://localhost/admin/languages')->assertOk()
            ->assertDontSee(route('admin.content-locale.set', ['locale' => 'ru']), false);
        $this->assertSame('ru', admin_content_locale());
    }

    public function test_a_language_that_is_off_cannot_be_made_the_base(): void
    {
        $language = Language::create(['code' => 'pl', 'name' => 'Polish', 'native_name' => 'Polski', 'enabled' => false, 'sort' => 50]);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/languages/'.$language->id.'/base')
            ->assertSessionHasErrors('language');

        $this->assertNotSame('pl', content_base_locale());
    }

    public function test_another_language_is_switched_in_place_and_its_code_stays(): void
    {
        $language = Language::create(['code' => 'pl', 'name' => 'Polish', 'native_name' => 'Polski', 'enabled' => true, 'sort' => 50]);

        $this->actingAs($this->admin())
            ->patch('http://localhost/admin/languages/'.$language->id.'/status', ['enabled' => 0])
            ->assertOk()
            ->assertSee('language-status-'.$language->id, false);
        $this->assertFalse($language->fresh()->enabled);

        $this->actingAs($this->admin())
            ->put('http://localhost/admin/languages/'.$language->id, [
                'code' => 'po', 'name' => 'Polish', 'native_name' => 'Polski',
            ])
            ->assertSessionHasErrors('code');
        $this->assertSame('pl', $language->fresh()->code);

        $this->actingAs($this->admin())
            ->delete('http://localhost/admin/languages/'.$language->id)
            ->assertRedirect(route('admin.languages.index'));
        $this->assertNull(Language::find($language->id));
    }
}
