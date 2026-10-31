<?php

namespace App\Core\Translation;

use App\Core\Modules\ModuleManager;
use App\Core\Themes\ThemeManager;

/**
 * Every text the shop prints, as the lang files declare it — what the
 * Texts screen lists. A text is its English key; with it, who declared it
 * (core, a module, a theme) and the file's translation in each language.
 *
 * Read from the files on every call: a few dozen small JSON files, asked
 * for by one admin screen. Only enabled modules' files, as the translator
 * loads only those; a text a disabled module declared is not printed
 * anywhere, so it is not offered. An override whose text no file declares
 * any more (its module went) is listed all the same, so it can be removed.
 */
class TextCatalogue
{
    public function __construct(protected ModuleManager $modules, protected ThemeManager $themes, protected TextOverrides $overrides) {}

    /**
     * @return array<string, array{owner: string, files: array<string, string>}> key => its owner's name and the file translations by locale
     */
    public function texts(): array
    {
        $texts = [];

        $this->read($texts, lang_path(), 'Core');

        foreach ($this->themes->chain() as $theme) {
            $this->read($texts, config('torgash.themes_path').DIRECTORY_SEPARATOR.$theme.DIRECTORY_SEPARATOR.'lang', $this->themes->meta($theme)['name'] ?? $theme);
        }

        foreach ($this->modules->enabled() as $manifest) {
            if ($lang = $manifest->langPath()) {
                $this->read($texts, $lang, $manifest->name);
            }
        }

        foreach ($this->overrides->locales() as $locale) {
            foreach ($this->overrides->for($locale) as $key => $value) {
                $texts[$key] ??= ['owner' => '', 'files' => []];
            }
        }

        return $texts;
    }

    /** @param  array<string, array{owner: string, files: array<string, string>}>  $texts */
    protected function read(array &$texts, string $dir, string $owner): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $locale = pathinfo($file, PATHINFO_FILENAME);
            $strings = json_decode((string) file_get_contents($file), true);

            if (! is_array($strings)) {
                continue;
            }

            foreach ($strings as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    continue;
                }

                // The first declaration names the owner: a theme's or a
                // module's word for a core key is still core's key.
                $texts[$key] ??= ['owner' => $owner, 'files' => []];
                $texts[$key]['files'][$locale] ??= $value;
            }
        }
    }
}
