<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * Declared words are English and translated by lang files (docs/DECISIONS.md,
 * "Labels are English plus lang files") — and every JSON lang file, core's,
 * each module's and each theme's, is merged into ONE table per language. So
 * the same English string with two translations is not two translations: one
 * of them silently wins by load order ("Order" read as "Порядок" on the order
 * page). The fix is always to make the English say which one it means.
 */
class TranslationFilesTest extends TestCase
{
    public function test_no_english_string_has_two_translations(): void
    {
        foreach ($this->locales() as $locale) {
            $seen = [];
            $clashes = [];

            foreach ($this->files($locale) as $file) {
                foreach (json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $english => $translation) {
                    if (isset($seen[$english]) && $seen[$english]['text'] !== $translation) {
                        $clashes[] = "\"{$english}\": \"{$seen[$english]['text']}\" ({$seen[$english]['file']}) vs \"{$translation}\" ({$file})";
                    }

                    $seen[$english] ??= ['text' => $translation, 'file' => $file];
                }
            }

            $this->assertSame([], $clashes, "{$locale}: one English string, two translations — make the English say which one it means (tools/i18n-conflicts.py)");
        }
    }

    /**
     * A module translates what it prints in ITS OWN file (or relies on core's,
     * which is always loaded) — never on another module's. The table is one,
     * but only the files of ENABLED modules are in it: "Order status" on the
     * order page was translated by the automations module alone, and the first
     * live shop, which has no automations, printed it in English (2026-10-21).
     */
    public function test_every_module_translates_the_strings_it_prints(): void
    {
        $core = json_decode((string) file_get_contents(base_path('lang/ru.json')), true);
        $missing = [];

        foreach (glob(base_path('modules/*'), GLOB_ONLYDIR) as $module) {
            $own = is_file("{$module}/lang/ru.json") ? json_decode((string) file_get_contents("{$module}/lang/ru.json"), true) : [];
            $files = [
                ...(glob("{$module}/src/{,*/,*/*/,*/*/*/}*.php", GLOB_BRACE) ?: []),
                ...(glob("{$module}/views/{,*/,*/*/,*/*/*/}*.php", GLOB_BRACE) ?: []),
            ];

            foreach ($files as $file) {
                preg_match_all("/(?:__|trans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

                foreach ($matches[1] as $english) {
                    $english = str_replace("\\'", "'", $english);

                    // `validation.ip` and the like are the framework's PHP
                    // lang files, not the JSON table.
                    if (preg_match('/^[a-z_]+\.[a-z_.]+$/', $english) || isset($own[$english]) || isset($core[$english])) {
                        continue;
                    }

                    $missing[] = basename($module).': '.$english;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'translate these in the module\'s own lang/ru.json');
    }

    public function test_a_declared_label_is_translated_and_an_old_map_still_reads(): void
    {
        $this->assertSame('Меню', label_text('Menu', 'ru'), 'English through the lang files');
        $this->assertSame('Menu', label_text('Menu', 'en'));
        $this->assertSame('Своё', label_text(['en' => 'Own', 'ru' => 'Своё'], 'ru'), 'a map says it itself');
        $this->assertSame('Меню', label_text(['en' => 'Menu'], 'ru'), 'a map without the language falls to its English, translated');
        $this->assertSame('fallback', label_text(null, 'ru', 'fallback'));
        $this->assertSame('fallback', label_text('', 'ru', 'fallback'));
    }

    /**
     * Ukrainian is kept beside Russian file for file: a string added to one
     * and not the other prints in English for half the shops that speak it.
     */
    public function test_every_russian_file_has_a_ukrainian_one_with_the_same_strings(): void
    {
        $drift = [];

        foreach ($this->files('ru') as $ru) {
            $uk = substr($ru, 0, -strlen('ru.json')).'uk.json';
            $ruKeys = array_keys(json_decode((string) file_get_contents($ru), true));
            $ukKeys = is_file($uk) ? array_keys(json_decode((string) file_get_contents($uk), true)) : [];

            foreach (array_diff($ruKeys, $ukKeys) as $english) {
                $drift[] = "{$uk}: missing \"{$english}\"";
            }

            foreach (array_diff($ukKeys, $ruKeys) as $english) {
                $drift[] = "{$uk}: \"{$english}\" is not in ru.json";
            }
        }

        $this->assertSame([], $drift, 'translate it into uk.json too');
    }

    /** @return list<string> */
    protected function locales(): array
    {
        return array_values(array_unique(array_map(
            fn (string $f) => pathinfo($f, PATHINFO_FILENAME),
            $this->files('*'),
        )));
    }

    /** @return list<string> */
    protected function files(string $locale): array
    {
        return array_merge(
            glob(base_path("lang/{$locale}.json")) ?: [],
            glob(base_path("modules/*/lang/{$locale}.json")) ?: [],
            glob(base_path("themes/*/lang/{$locale}.json")) ?: [],
        );
    }
}
