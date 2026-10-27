<?php

namespace App\Core\Modules\Commands;

use App\Core\Admin\AdminMenu;
use App\Core\Install\Installer;
use App\Core\Modules\ModuleDocs;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `torgash:site-export <site>` — the engine's modules, as pages of its
 * website's documentation (a Hugo site: extago.com/solutions/torgash/docs/).
 *
 * The site never describes a module by hand: one page per module, made from
 * what the engine already knows — its name and description in both
 * languages, icon, admin section, countries, what it needs, which sets
 * install it — and below that the module's own documentation, the same
 * `docs/*.md` the admin opens under its book icon. Run before a release, so
 * the site and the archive say the same thing.
 *
 * Everything under `content/solutions/torgash/docs/modules/` is rewritten;
 * nothing there is to be edited by hand. Icons a page names and the site
 * lacks are copied from the engine's Lucide set.
 */
class SiteExportCommand extends Command
{
    protected $signature = 'torgash:site-export {site : The Hugo site folder (the one with hugo.toml)}';

    protected $description = 'Write the modules and their documentation into the engine\'s website as documentation pages';

    public const LOCALES = ['en', 'ru'];

    protected const DIR = 'content/solutions/torgash/docs/modules';

    public function handle(ModuleManager $modules, AdminMenu $menu): int
    {
        $site = rtrim((string) $this->argument('site'), '/\\');
        if (! is_file($site.'/hugo.toml')) {
            $this->error("No hugo.toml in {$site}.");

            return self::FAILURE;
        }

        $out = $site.'/'.self::DIR;
        File::deleteDirectory($out);
        File::ensureDirectoryExists($out);

        $examples = (array) config('torgash.example_modules', []);
        $installer = new Installer;
        $sets = [
            'minimal' => $installer->resolve('minimal'),
            'store' => $installer->resolve('store'),
        ];

        $manifests = array_filter($modules->all(), fn (ModuleManifest $m) => ! in_array($m->id, $examples, true));
        $icons = [];
        $previous = app()->getLocale();

        foreach (self::LOCALES as $locale) {
            app()->setLocale($locale);
            $headings = $menu->sectionHeadings();
            $positions = array_flip(array_keys($headings));

            $this->index($out, $locale);

            foreach ($manifests as $manifest) {
                $section = $menu->sectionOf($manifest);
                $icon = $manifest->look['icon'] ?? ($headings[$section]['icon'] ?? 'blocks');
                $icons[$icon] = true;

                $this->page($out, $manifest, $locale, [
                    'section' => $section,
                    'section_title' => $headings[$section]['label'] ?? $section,
                    // A zero-padded string: Hugo groups pages by a NUMBER param into nothing.
                    'section_order' => sprintf('%02d', ($positions[$section] ?? 98) + 1),
                    'icon' => $icon,
                    'sets' => array_keys(array_filter($sets, fn (array $ids) => in_array($manifest->id, $ids, true))),
                ], $manifests);
            }
        }

        app()->setLocale($previous);

        $copied = $this->icons($site, array_keys($icons));

        $this->info(count($manifests).' modules written to '.$out.($copied ? ", {$copied} icons added" : '').'.');

        return self::SUCCESS;
    }

    protected function index(string $out, string $locale): void
    {
        $front = [
            'title' => $locale === 'ru' ? 'Модули' : 'Modules',
            'weight' => 15,
            'layout' => 'modules',
            'cascade' => ['layout' => 'module'],
        ];
        $body = $locale === 'ru'
            ? "Всё, что умеет Torgash, — модули. Они лежат в одном архиве; при установке ставится выбранный набор, остальные ставятся и удаляются в админке в любой момент.\n"
            : "Everything Torgash does is a module. They all come in one archive: the installer puts in the set you pick, and any other is installed or removed in the admin at any time.\n";

        file_put_contents($out.'/_index.'.$locale.'.md', $this->frontMatter($front).$body);
    }

    /**
     * One module's page: a bundle (`<slug>/index.<locale>.md`) so the
     * pictures its documentation embeds sit beside it and resolve as written.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, ModuleManifest>  $all
     */
    protected function page(string $out, ModuleManifest $manifest, string $locale, array $facts, array $all): void
    {
        $slug = self::slug($manifest->id);
        $dir = $out.'/'.$slug;
        File::ensureDirectoryExists($dir);

        $requires = [];
        foreach ($manifest->requires['modules'] ?? [] as $id) {
            if (isset($all[$id])) {
                $requires[] = ['slug' => self::slug($id), 'title' => label_text($all[$id]->name, $locale)];
            }
        }

        [$body, $translated] = $this->documentation($manifest, $locale, $dir);

        $front = [
            'title' => label_text($manifest->name, $locale),
            'summary' => trim(label_text($manifest->description, $locale)),
            'id' => $manifest->id,
            'version' => $manifest->version,
            'icon' => $facts['icon'],
            'section' => $facts['section'],
            'section_title' => $facts['section_title'],
            'section_order' => $facts['section_order'],
            'regions' => $manifest->regions,
            'sets' => $facts['sets'],
            'requires' => $requires,
            'has_docs' => $body !== '',
            'docs_translated' => $translated,
        ];

        file_put_contents($dir.'/index.'.$locale.'.md', $this->frontMatter($front).$body);
    }

    /**
     * The module's documentation as one page: each tab a `##` section (its
     * own `#` heading becomes that), every heading below moved one level
     * down. Pictures it names are copied beside the page.
     *
     * @return array{0: string, 1: bool} the Markdown, and whether every tab was in this language
     */
    protected function documentation(ModuleManifest $manifest, string $locale, string $dir): array
    {
        $docs = new ModuleDocs($manifest);
        $parts = [];
        $translated = true;

        foreach ($docs->pages($locale) as $page) {
            $source = $docs->source($page['slug'], $locale);
            if ($source === null) {
                continue;
            }

            $translated = $translated && $source['in_locale'];
            $text = (string) file_get_contents($source['file']);

            // Headings down a level, in code-free lines only.
            $inCode = false;
            $lines = [];
            // `u`: without it \R also matches the byte 0x85, which is half of
            // a Cyrillic letter, and splits the word in two.
            foreach (preg_split('/\R/u', $text) as $line) {
                if (preg_match('/^\s*(```|~~~)/', $line)) {
                    $inCode = ! $inCode;
                }
                $lines[] = ! $inCode && preg_match('/^#{1,5}\s/', $line) ? '#'.$line : $line;
            }
            $parts[] = trim(implode("\n", $lines));

            foreach ($this->pictures($text) as $relative) {
                $from = $docs->media($relative);
                if ($from !== null) {
                    File::ensureDirectoryExists(dirname($dir.'/'.$relative));
                    File::copy($from, $dir.'/'.$relative);
                }
            }
        }

        return [$parts === [] ? '' : implode("\n\n", $parts)."\n", $parts !== [] && $translated];
    }

    /** @return list<string> relative picture paths a Markdown text embeds */
    protected function pictures(string $text): array
    {
        preg_match_all('/!\[[^\]]*\]\(([^)\s]+)/', $text, $m);

        return array_values(array_filter($m[1], fn (string $src) => ! preg_match('~^(?:[a-z][a-z0-9+.\-]*:|/|#)~i', $src)));
    }

    /** @param  list<string>  $names */
    protected function icons(string $site, array $names): int
    {
        $copied = 0;
        foreach ($names as $name) {
            // A path in the Hugo site's folder, not an address of this shop.
            $target = implode('/', [$site, 'assets', 'icons', 'lucide', $name.'.svg']);
            $source = resource_path('icons/lucide/'.$name.'.svg');
            if (! is_file($target) && is_file($source)) {
                File::ensureDirectoryExists(dirname($target));
                File::copy($source, $target);
                $copied++;
            }
        }

        return $copied;
    }

    /** YAML front matter written as JSON — every JSON document is valid YAML, and no quoting can go wrong. */
    protected function frontMatter(array $front): string
    {
        return "---\n".json_encode($front, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n---\n\n";
    }

    public static function slug(string $id): string
    {
        return preg_replace('/^torgash\./', '', $id);
    }
}
