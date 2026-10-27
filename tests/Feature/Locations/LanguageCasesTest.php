<?php

namespace Tests\Feature\Locations;

use App\Core\Support\GrammaticalCases;
use App\Models\Language;
use App\Models\User;
use Tests\TestCase;
use Torgash\Locations\Models\Place;

/**
 * A language's cases are the LANGUAGE's setting, so any language gets them
 * without code; deriving the forms is a registry of rules, and a language with
 * cases and no rules derives nothing — a town reads its nominative until the
 * shop types the form it needs.
 */
class LanguageCasesTest extends TestCase
{
    protected function place(array $names, array $forms = []): Place
    {
        return Place::create(['country' => 'ZQ', 'name' => $names, 'forms' => $forms ?: null, 'type' => 'city',
            'enabled' => true, 'source' => 'manual', 'population' => 1000]);
    }

    public function test_a_language_with_cases_and_no_rules_reads_the_nominative_until_a_form_is_typed(): void
    {
        Language::create(['code' => 'zz', 'name' => 'Zedish', 'native_name' => 'Zed', 'enabled' => true,
            'cases' => [['name' => 'Base', 'example' => 'town'], ['name' => 'Inside', 'example' => 'in-town']]]);

        $place = $this->place(['zz' => 'Kora'], ['zz' => ['2' => 'Korain']]);
        $plain = $this->place(['zz' => 'Mela']);

        $this->assertSame('Korain', $place->form(2, 'zz'), 'the typed form wins');
        $this->assertSame('Mela', $plain->form(2, 'zz'), 'no rules: the nominative, not an empty string');
        $this->assertSame('Mela', $plain->form(6, 'zz'), 'a case the language has not got');

        $rows = $plain->formRows('zz');
        $this->assertSame('Inside (in-town)', $rows[2]['label'], "the language's own words, as typed");
    }

    public function test_a_module_adds_the_rules_for_a_language(): void
    {
        Language::create(['code' => 'zy', 'name' => 'Zyish', 'native_name' => 'Zy', 'enabled' => true,
            'cases' => [['name' => 'Base', 'example' => ''], ['name' => 'Of', 'example' => '']]]);
        hook_listen('locations.declension.rules', fn (array $rules) => $rules + ['zy' => fn (string $name) => [2 => $name.'-of', 3 => $name.'-extra']]);

        $place = $this->place(['zy' => 'Tor']);

        $this->assertSame('Tor-of', $place->form(2, 'zy'));
        $this->assertSame('Tor', $place->form(3, 'zy'), 'only the cases the language declares');
    }

    public function test_russian_still_declines_from_its_defaults(): void
    {
        $this->assertSame('Предложный (в городе)', app(GrammaticalCases::class)->label('ru', 6));
        $this->assertSame('Одессе', $this->place(['ru' => 'Одесса'])->form(6, 'ru'));
    }

    public function test_the_language_form_saves_its_cases_and_defaults_never_overwrite_them(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $ru = Language::where('code', 'ru')->firstOrFail();

        $this->actingAs($admin)->put("http://localhost/admin/languages/{$ru->id}", [
            'code' => 'ru', 'name' => 'Russian', 'native_name' => 'Русский', 'enabled' => 1,
            'cases' => [['name' => 'Именительный', 'example' => 'город'], ['name' => '', 'example' => 'blank row'], ['name' => 'Местный', 'example' => 'в городе']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.languages.edit', $ru));

        $this->assertSame([['name' => 'Именительный', 'example' => 'город'], ['name' => 'Местный', 'example' => 'в городе']], $ru->fresh()->cases);

        GrammaticalCases::seedDefaults();
        $this->assertCount(2, $ru->fresh()->cases, "the seeder does not undo the shop's own list");

        // One row is "one form" — said, so the defaults stay away from it too.
        $this->put("http://localhost/admin/languages/{$ru->id}", [
            'code' => 'ru', 'name' => 'Russian', 'native_name' => 'Русский', 'enabled' => 1, 'cases' => [['name' => 'Only']],
        ])->assertSessionHasNoErrors();
        $this->assertSame([], $ru->fresh()->cases);
        GrammaticalCases::seedDefaults();
        $this->assertSame([], $ru->fresh()->cases);
    }
}
