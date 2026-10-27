<?php

namespace Torgash\Landings;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Torgash\Landings\Models\Landing;
use ZipArchive;

/**
 * A landing as a ZIP and back — the way a designer hands one over:
 *
 *     index.html          the base language (index.<locale>.html for others)
 *     style.css           (any .css beside index.html is taken too)
 *     assets/…            its pictures, fonts, video
 *
 * all of it possibly inside one folder of the archive. A whole HTML document
 * is cut down to what its <body> holds; its <style> elements move to the CSS,
 * and what a landing never runs — <script>, <link>, <meta>, <title> — is
 * dropped and counted, so the screen can say what happened. Paths are cleaned
 * one by one (Files::clean): nothing in an archive writes outside the
 * landing's folder.
 */
class Zip
{
    public const MAX_FILES = 500;

    public const MAX_TOTAL = 100 * 1024 * 1024;

    public function __construct(protected Files $files) {}

    /**
     * Replace the landing's HTML (the languages the archive has), CSS and files.
     *
     * @return array{languages: list<string>, files: int, skipped: list<string>, moved_styles: int, removed: array<string, int>}
     */
    public function import(Landing $landing, string $zipPath, string $baseLocale): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(__('That is not a ZIP archive.'));
        }

        if ($zip->numFiles > self::MAX_FILES) {
            throw new RuntimeException(__('The archive holds more than :n files.', ['n' => self::MAX_FILES]));
        }

        // Where index.html is: the archive's root, or the one folder it is in.
        $root = null;
        $depth = PHP_INT_MAX;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));

            if (preg_match('~^(?:(.*)/)?index\.html?$~i', $name, $m) && substr_count($name, '/') < $depth) {
                $depth = substr_count($name, '/');
                $root = isset($m[1]) && $m[1] !== '' ? $m[1].'/' : '';
            }
        }

        if ($root === null) {
            throw new RuntimeException(__('There is no index.html in the archive.'));
        }

        $html = (array) ($landing->html ?? []);
        $css = '';
        $report = ['languages' => [], 'files' => 0, 'skipped' => [], 'moved_styles' => 0, 'removed' => []];
        $total = 0;
        $assets = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $stat = $zip->statIndex($i);

            if (str_ends_with($name, '/') || ! str_starts_with($name, $root)) {
                continue;
            }

            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_TOTAL) {
                throw new RuntimeException(__('The archive unpacks to more than :mb MB.', ['mb' => self::MAX_TOTAL / 1048576]));
            }

            $inside = substr($name, strlen($root));

            if (preg_match('~^index(?:\.([a-z]{2}(?:[-_][A-Za-z]{2})?))?\.html?$~i', $inside, $m)) {
                $locale = isset($m[1]) ? strtolower($m[1]) : $baseLocale;
                $html[$locale] = $this->body((string) $zip->getFromIndex($i), $css, $report);
                $report['languages'][] = $locale;
            } elseif (preg_match('~^[^/]+\.css$~i', $inside)) {
                $css .= trim((string) $zip->getFromIndex($i))."\n";
            } elseif (str_starts_with(strtolower($inside), 'assets/')) {
                $assets[$i] = substr($inside, 7);
            }
        }

        // The new files replace the old ones: a re-export of a fixed design
        // must not keep the pictures it no longer uses.
        Storage::disk('public')->deleteDirectory($landing->filesDir());

        foreach ($assets as $index => $relative) {
            $tmp = tempnam(sys_get_temp_dir(), 'landing');
            file_put_contents($tmp, (string) $zip->getFromIndex($index));
            $stored = $this->files->put($landing, basename($relative), $tmp, dirname($relative) === '.' ? '' : dirname($relative));
            @unlink($tmp);

            $stored === null ? $report['skipped'][] = 'assets/'.$relative : $report['files']++;
        }

        $zip->close();

        $landing->html = $html;
        $landing->css = trim($css);
        $landing->save();

        return $report;
    }

    /** A ZIP of the landing in a temporary file, ready to be sent. */
    public function export(Landing $landing, string $baseLocale): string
    {
        $path = tempnam(sys_get_temp_dir(), 'landing').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ((array) ($landing->html ?? []) as $locale => $html) {
            $zip->addFromString($locale === $baseLocale ? 'index.html' : 'index.'.$locale.'.html', (string) $html);
        }

        if (trim((string) $landing->css) !== '') {
            $zip->addFromString('style.css', (string) $landing->css);
        }

        $disk = Storage::disk('public');
        foreach ($this->files->of($landing) as $file) {
            $zip->addFromString($file['path'], (string) $disk->get($landing->folder().'/'.$file['path']));
        }

        $zip->close();

        return $path;
    }

    /**
     * What a landing keeps of an HTML file: the body's content, its <style>s
     * moved to the CSS, and nothing that would run or does not belong in a page's middle.
     */
    public function body(string $document, string &$css, array &$report): string
    {
        if (preg_match('~<body\b[^>]*>(.*)</body>~is', $document, $m)) {
            $headStyles = preg_match('~<head\b[^>]*>(.*)</head>~is', $document, $head) ? $head[1] : '';
            $document = $headStyles.$m[1];
        }

        $document = (string) preg_replace_callback('~<style\b[^>]*>(.*?)</style>~is', function ($m) use (&$css, &$report) {
            $css .= trim($m[1])."\n";
            $report['moved_styles']++;

            return '';
        }, $document);

        foreach (['script' => '~<script\b[^>]*>.*?</script>~is', 'link' => '~<link\b[^>]*>~i', 'meta' => '~<meta\b[^>]*>~i', 'title' => '~<title\b[^>]*>.*?</title>~is'] as $tag => $pattern) {
            $document = (string) preg_replace($pattern, '', $document, -1, $count);
            if ($count > 0) {
                $report['removed'][$tag] = ($report['removed'][$tag] ?? 0) + $count;
            }
        }

        return trim($document);
    }
}
