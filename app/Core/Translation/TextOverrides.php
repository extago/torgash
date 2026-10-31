<?php

namespace App\Core\Translation;

use App\Core\Support\WebWorkers;
use App\Models\TextOverride;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The shop's own wording of the engine's texts (Admin → Texts).
 *
 * Every string the code prints is an English key translated by lang files
 * (ADR-005), and a shop may want another word for one of them — "Cashback"
 * where the module says "Bonus points", in Russian or in English alike.
 * Written into a lang file that word would go with the next update, so it
 * is a ROW here, read over the files by TextLoader: one map per language,
 * kept in the application cache for ever and dropped when a row changes.
 *
 * Words only. A text is printed escaped wherever it goes — a menu item, a
 * button's aria-label, a placeholder, a letter's subject, an SMS, a string
 * inside a script — so markup would show as markup at best and, printed
 * raw, be a script run by whoever may edit texts on a shop whose admin
 * shares the storefront's origin. The shop's own HTML has its own places
 * (HTML blocks, snippets). A text keeps the key's `:placeholders`: the
 * shop fills them in, and one left out prints nothing where a number was.
 */
class TextOverrides
{
    protected const CACHE = 'texts.overrides.';

    /** @return array<string, string> key => the shop's wording, for one language */
    public function for(string $locale): array
    {
        try {
            return Cache::rememberForever(self::CACHE.$locale, fn () => TextOverride::where('locale', $locale)
                ->pluck('value', 'key')
                ->all());
        } catch (Throwable) {
            // No table yet (the installer, the test schema being built), or
            // no database at all: the files are all there is.
            return [];
        }
    }

    /** Every language that has a row. @return list<string> */
    public function locales(): array
    {
        return TextOverride::distinct()->orderBy('locale')->pluck('locale')->all();
    }

    /**
     * Why a wording may not be kept, or null when it may.
     *
     * @param  string  $key  the English the code prints
     */
    public function refusal(string $key, string $value): ?string
    {
        if (preg_match('/<[a-zA-Z\/!]/', $value)) {
            return __('A text is plain words — no HTML. Your own markup goes into an HTML block.');
        }

        $wanted = self::placeholders($key);
        $given = self::placeholders($value);

        if ($missing = array_diff($wanted, $given)) {
            return __('Keep :placeholders in the text — the shop fills them in.', [
                'placeholders' => implode(', ', array_map(fn ($name) => ':'.$name, $missing)),
            ]);
        }

        if ($unknown = array_diff($given, $wanted)) {
            return __('There is no :placeholders in this text; it would be printed as written.', [
                'placeholders' => implode(', ', array_map(fn ($name) => ':'.$name, $unknown)),
            ]);
        }

        if (str_contains($key, '|') && ! str_contains($value, '|')) {
            return __('This text has a form for each count, divided by "|" — keep them.');
        }

        return null;
    }

    /** The `:names` a text expects — lowercased, since `:Name` and `:NAME` are the same one capitalised. @return list<string> */
    public static function placeholders(string $text): array
    {
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $text, $found);

        return array_values(array_unique(array_map('strtolower', $found[1])));
    }

    /** Keeps the shop's wording; an empty one takes the file's back. */
    public function put(string $locale, string $key, string $value): void
    {
        if ($value === '') {
            TextOverride::where('locale', $locale)->where('key', $key)->delete();
        } else {
            TextOverride::updateOrCreate(['locale' => $locale, 'key' => $key], ['value' => $value]);
        }

        $this->changed($locale);
    }

    /** Every language back to the file's text for this key. */
    public function forget(string $key): void
    {
        $locales = TextOverride::where('key', $key)->pluck('locale')->all();
        TextOverride::where('key', $key)->delete();

        foreach ($locales as $locale) {
            $this->changed($locale);
        }
    }

    /**
     * A row changed: the memo goes, the translator forgets what it loaded,
     * kept pages are drawn again, and the workers — web and queue, which
     * keep the texts they booted with — are told to start afresh.
     */
    public function changed(string $locale): void
    {
        Cache::forget(self::CACHE.$locale);
        app('translator')->setLoaded([]);
        page_cache()->flush('texts');
        WebWorkers::restart();

        try {
            Artisan::call('queue:restart');
        } catch (Throwable) {
            // A letter written by the next job will still say the new word
            // once the worker is restarted by hand.
        }
    }
}
