<?php

namespace App\Core\Http;

use Illuminate\Http\Request;

/**
 * An address no person typed and no page of the shop could have: `/.env`,
 * `/wp-login.php`, `/backup.zip`, a picture that is not there.
 *
 * It decides ONE thing — how a 404 is dressed. The shop's "page not found" is
 * drawn in the storefront's clothes (header, menu, search, footer) so that
 * someone who followed a dead link can go on; a scanner trying `/.env` is not
 * that someone, and it was served the whole storefront each time — 26 queries
 * and up to three seconds a request on a live shop, a third of everything the
 * server did that day (measured 2026-11-16). Such an address gets the bare
 * error page, which reads no table.
 *
 * Asked only once the address HAS failed: every route, the pages' fallback
 * and the redirects (a shop that moved from another engine redirects its old
 * `/index.php?…` and `/catalog.php` addresses) have all had their turn, so
 * nothing the shop serves or forwards is ever judged here.
 */
class StrayAddress
{
    /**
     * Endings of a FILE: a program, a configuration, an archive or dump, a
     * static asset. A page of the shop ends in a word, `.html` or `.md`.
     */
    protected const FILE_ENDINGS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'asp', 'aspx', 'ashx', 'jsp', 'jspx', 'do', 'action', 'cgi', 'pl', 'py', 'rb',
        'sh', 'bash', 'bat', 'cmd', 'exe', 'dll',
        'env', 'ini', 'conf', 'config', 'cfg', 'yml', 'yaml', 'toml', 'json', 'xml', 'txt', 'log',
        'sql', 'sqlite', 'db', 'bak', 'backup', 'old', 'orig', 'save', 'swp', 'tmp',
        'zip', 'rar', 'tar', 'gz', 'tgz', 'bz2', '7z',
        'pem', 'key', 'crt', 'map', 'js', 'css',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico',
        'woff', 'woff2', 'ttf', 'eot', 'mp4', 'webm', 'pdf',
    ];

    /** Folders of other software, which is what a scanner is looking for. */
    protected const FOREIGN_FOLDERS = ['cgi-bin', 'phpmyadmin', 'pma', 'wordpress'];

    public static function is(Request $request): bool
    {
        return self::matches($request->path());
    }

    public static function matches(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', mb_strtolower(rawurldecode($path))), fn (string $s) => $s !== ''));

        if ($segments === []) {
            return false;
        }

        foreach ($segments as $segment) {
            // A hidden file or folder (`.env`, `.git/config`); `.well-known`
            // is the one such name the web itself uses.
            if ($segment[0] === '.' && $segment !== '.well-known') {
                return true;
            }

            if (str_starts_with($segment, 'wp-') || in_array($segment, self::FOREIGN_FOLDERS, true)) {
                return true;
            }
        }

        $last = end($segments);
        $dot = strrpos($last, '.');

        return $dot !== false && in_array(substr($last, $dot + 1), self::FILE_ENDINGS, true);
    }
}
