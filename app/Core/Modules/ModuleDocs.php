<?php

namespace App\Core\Modules;

use Illuminate\Support\Str;

/**
 * The Markdown pages a module ships in its docs/ folder, rendered for the admin.
 *
 * A file is `[<NN>-]<slug>.md` for the base language and `<locale>_<slug>.md`
 * for a translation of it — so `docs/` might hold `01-intro.md`, `usage.md`,
 * `ru_intro.md`, `ru_usage.md`. The base file's optional `NN-` prefix orders
 * the tabs; a translation carries no number, it inherits the base page's slug
 * and order. A page with only a translation (no base file) still shows.
 *
 * Presence of the folder is the whole opt-in — no manifest flag to keep in
 * sync, the same as hasSettings(). Markdown is rendered with raw HTML stripped:
 * the text is written by the module's vendor, a third party.
 */
class ModuleDocs
{
    /** Images a docs page may embed, served back through the docs media route. */
    public const MEDIA_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    private string $dir;

    public function __construct(private ModuleManifest $manifest)
    {
        $this->dir = (string) $manifest->docsPath();
    }

    /** The slug the changelog answers to, beside the docs/ pages. */
    public const CHANGELOG = 'changelog';

    public function exists(): bool
    {
        return $this->pages() !== [];
    }

    /**
     * The pages, tab order, each with the title to show and the slug to link.
     *
     * `$locale` is the language the tabs are being READ in: a page whose
     * translation exists is labelled by that translation's own heading, which
     * is the difference between a documentation screen that reads as
     * translated and one that answers a Russian page with an English tab.
     * The ORDER still comes from the base file alone — a translation that
     * numbered itself differently must not shuffle the book.
     *
     * @return list<array{slug: string, title: string, order: int}>
     */
    public function pages(?string $locale = null): array
    {
        // What changed is the last tab, after everything the author ordered.
        $changelog = $this->manifest->changelogFile($locale)
            ? [['slug' => self::CHANGELOG, 'title' => __('What\'s new'), 'order' => 10000]]
            : [];

        if ($this->dir === '' || ! is_dir($this->dir)) {
            return $changelog;
        }

        $pages = [];

        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*.md') ?: [] as $file) {
            [$fileLocale, $slug, $order] = $this->parse(basename($file, '.md'));

            $pages[$slug] ??= ['slug' => $slug, 'title' => '', 'order' => 999, 'has_base' => false, 'translated' => false];

            if ($fileLocale === null) {
                $pages[$slug]['order'] = $order;
                $pages[$slug]['has_base'] = true;

                // The base title is the fallback, so it must not overwrite a
                // translated one already found (glob order is not ours).
                if (! $pages[$slug]['translated']) {
                    $pages[$slug]['title'] = $this->titleOf($file, $slug);
                }
            } elseif ($locale !== null && $fileLocale === $locale) {
                $pages[$slug]['title'] = $this->titleOf($file, $slug);
                $pages[$slug]['translated'] = true;
            } elseif (! $pages[$slug]['has_base'] && $pages[$slug]['title'] === '') {
                // No base file at all: any translation is better than a slug.
                $pages[$slug]['title'] = $this->titleOf($file, $slug);
            }
        }

        $pages = array_values($pages);
        usort($pages, fn ($a, $b) => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);

        return [...array_map(fn ($p) => ['slug' => $p['slug'], 'title' => $p['title'], 'order' => $p['order']], $pages), ...$changelog];
    }

    /**
     * One page as sanitized HTML in the given locale, falling back to the base
     * language. Null when there is no such page in any language.
     */
    public function render(string $slug, string $locale): ?string
    {
        $file = match (true) {
            $slug === self::CHANGELOG => $this->manifest->changelogFile($locale),
            // No docs/ at all: fileFor() would glob whatever '' resolves to.
            $this->dir === '' => null,
            default => $this->fileFor($slug, $locale),
        };

        if ($file === null) {
            return null;
        }

        $html = Str::markdown((string) file_get_contents($file), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        // Relative image sources point inside docs/ — rewrite them to the media
        // route. An absolute URL or a rooted path is left as the author wrote it.
        return preg_replace_callback(
            '/\bsrc="([^"]+)"/',
            function (array $m) use ($slug) {
                $src = $m[1];

                // Leave absolute URLs (scheme:), rooted paths (/…) and in-page
                // anchors (#…) exactly as the author wrote them.
                if (preg_match('~^(?:[a-z][a-z0-9+.\-]*:|/|#)~i', $src)) {
                    return $m[0];
                }

                return 'src="'.e(route('admin.modules.docs.media', [
                    'id' => $this->manifest->id,
                    'path' => ltrim($src, './'),
                ])).'"';
            },
            $html,
        );
    }

    /**
     * The absolute path of an image a docs page embeds, or null when the
     * request escapes docs/ or names a type that is not an image.
     */
    public function media(string $relativePath): ?string
    {
        if ($this->dir === '') {
            return null;
        }

        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        if (! in_array($ext, self::MEDIA_EXTENSIONS, true)) {
            return null;
        }

        $real = realpath($this->dir.DIRECTORY_SEPARATOR.$relativePath);
        $root = realpath($this->dir);

        return $real !== false && $root !== false && str_starts_with($real, $root.DIRECTORY_SEPARATOR) && is_file($real)
            ? $real
            : null;
    }

    /** @return array{0: ?string, 1: string, 2: int} [locale|null, slug, order] */
    private function parse(string $name): array
    {
        $locale = null;

        if (preg_match('/^([a-z]{2})_(.+)$/', $name, $m)) {
            $locale = $m[1];
            $name = $m[2];
        }

        $order = 999;

        if (preg_match('/^(\d+)[-_](.+)$/', $name, $m)) {
            $order = (int) $m[1];
            $name = $m[2];
        }

        return [$locale, Str::slug($name) ?: $name, $order];
    }

    /**
     * The file for a slug in the wanted locale, or the base-language file, or
     * — failing both — any translation of it. Filenames carry an optional
     * number and prefix, so they are matched by parsing, not by reconstruction.
     */
    /**
     * The file one page is written in for a reader of `$locale` — its
     * translation, else the base — and whether it really is in that
     * language. For a reader outside the admin: the engine's website
     * (`torgash:site-export`) says so when a page is still in English.
     *
     * @return array{file: string, in_locale: bool}|null
     */
    public function source(string $slug, string $locale): ?array
    {
        if ($this->dir === '' || $slug === self::CHANGELOG || ($file = $this->fileFor($slug, $locale)) === null) {
            return null;
        }

        [$fileLocale] = $this->parse(basename($file, '.md'));

        return ['file' => $file, 'in_locale' => $fileLocale === $locale || ($fileLocale === null && $locale === 'en')];
    }

    private function fileFor(string $slug, string $locale): ?string
    {
        $wanted = null;
        $base = null;
        $any = null;

        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*.md') ?: [] as $file) {
            [$fileLocale, $fileSlug] = $this->parse(basename($file, '.md'));

            if ($fileSlug !== $slug) {
                continue;
            }

            match (true) {
                $fileLocale === $locale => $wanted = $file,
                $fileLocale === null => $base = $file,
                default => $any ??= $file,
            };
        }

        return $wanted ?? $base ?? $any;
    }

    private function titleOf(string $file, string $slug): string
    {
        $handle = fopen($file, 'rb');

        if ($handle !== false) {
            for ($i = 0; $i < 30 && ($line = fgets($handle)) !== false; $i++) {
                if (preg_match('/^\s{0,3}#\s+(.+?)\s*#*\s*$/', $line, $m)) {
                    fclose($handle);

                    return trim($m[1]);
                }
            }

            fclose($handle);
        }

        return Str::headline($slug);
    }
}
