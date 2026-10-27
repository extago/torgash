<?php
/**
 * Vendors the Lucide icon set (ISC) into resources/icons/lucide/.
 *
 *   php.exe tools/vendor-lucide.php [version]
 *
 * Only icons/*.svg is taken — lucide-static also ships PNG exports and an icon
 * font that together weigh ~47 MB and that we have no use for.
 *
 * Each file is normalised to a single line with its class attribute stripped,
 * so <x-icon> can inject classes with one deterministic str_replace and never
 * has to parse SVG at render time. The per-file licence comment is dropped in
 * favour of one LICENSE next to them.
 */

$version = $argv[1] ?? '1.34.0';
$root = dirname(__DIR__);
$out = $root.'/resources/icons/lucide';
$tarball = sys_get_temp_dir().'/lucide-static-'.$version.'.tgz';
$staging = sys_get_temp_dir().'/lucide-staging-'.$version;

if (! is_file($tarball)) {
    echo "downloading lucide-static@{$version}…\n";
    $url = "https://registry.npmjs.org/lucide-static/-/lucide-static-{$version}.tgz";
    file_put_contents($tarball, file_get_contents($url)) ?: exit("download failed\n");
}

// GNU tar ships with Git for Windows; extracting a subset beats unpacking 47 MB.
@mkdir($staging, 0775, true);
// --force-local: without it GNU tar reads the "C:" in a Windows path as a
// remote host and tries to connect to it.
exec(sprintf(
    'tar --force-local -xzf %s -C %s --strip-components=2 --wildcards %s 2>&1',
    escapeshellarg($tarball),
    escapeshellarg($staging),
    escapeshellarg('package/icons/*.svg'),
), $output, $status);

if ($status !== 0) {
    exit("tar failed: ".implode("\n", $output)."\n");
}

if (! is_dir($out)) {
    mkdir($out, 0775, true);
}

array_map('unlink', glob($out.'/*.svg') ?: []);

$written = 0;
$bytes = 0;

foreach (glob($staging.'/*.svg') ?: [] as $file) {
    $svg = (string) file_get_contents($file);

    $svg = preg_replace('~<!--.*?-->~s', '', $svg);          // per-file licence comment
    $svg = preg_replace('~\s+class="[^"]*"~', '', $svg);     // "lucide lucide-<name>"
    $svg = preg_replace('~\s*\n\s*~', ' ', $svg);            // one line
    $svg = preg_replace('~\s{2,}~', ' ', trim($svg));
    $svg = str_replace(['> <', ' >', ' />'], ['><', '>', '/>'], $svg);

    file_put_contents($out.'/'.basename($file), $svg);
    $written++;
    $bytes += strlen($svg);
}

// Keep the upstream licence next to the files it covers.
file_put_contents($out.'/LICENSE', <<<TXT
Lucide icon set — ISC License
Vendored from lucide-static@{$version} by tools/vendor-lucide.php
https://lucide.dev · https://github.com/lucide-icons/lucide

ISC License

Copyright (c) for portions of Lucide are held by Cole Bemis 2013-2022 as part of
Feather (MIT). All other copyright (c) for Lucide are held by Lucide Contributors
2022.

Permission to use, copy, modify, and/or distribute this software for any purpose
with or without fee is hereby granted, provided that the above copyright notice
and this permission notice appear in all copies.

THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH
REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY AND
FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT,
INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES WHATSOEVER RESULTING FROM LOSS
OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR OTHER
TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR PERFORMANCE OF
THIS SOFTWARE.
TXT);

file_put_contents($out.'/VERSION', $version."\n");

printf("%d icons, %.1f KB → %s\n", $written, $bytes / 1024, $out);
