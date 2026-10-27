<?php

/**
 * Vendors the TipTap + ProseMirror ESM graph from esm.sh into a flat directory
 * of "<package>.mjs" files whose imports point at each other relatively, so the
 * browser loads them straight from disk with no CDN, no import map and exactly
 * one copy of every ProseMirror package.
 *
 * This is how modules/torgash.editor/assets/vendor/ was produced. Re-run it to
 * upgrade TipTap (bump the two version constants below):
 *
 *   php.exe modules/torgash.editor/tools/vendor-tiptap.php modules/torgash.editor/assets/vendor
 *
 * Why not @tiptap/pm bundled too: every bundle that inlines its own copy of
 * prosemirror-model/state/view gives the editor two class identities for the
 * same schema, and plugin/node identity checks then fail at runtime.
 *
 * MIND THE UNPINNED HALF. The two constants below pin @tiptap/*, and nothing
 * pins the prosemirror-* packages esm.sh resolves underneath them — so a
 * re-run to add ONE extension also brings whatever ProseMirror has released
 * since. Adding @tiptap/extension-image did exactly that (prosemirror-transform
 * and prosemirror-view moved), and only the new file was copied in: an
 * extension is a feature, a ProseMirror bump is a separate change with its own
 * testing. Write into a temp directory, diff it against assets/vendor, and
 * decide what you are actually taking.
 */
$out = $argv[1] ?? exit("usage: vendor-tiptap.php <out-dir>\n");
@mkdir($out, 0775, true);

// TipTap 3 pins every first-party dependency to an exact version, so esm.sh
// resolves one consistent set — v2's caret ranges silently mixed a 2.11 core
// with 2.27 extensions and broke on missing exports.
const TIPTAP_VERSION = '3.30.3';
const PM_VERSION = '3.30.3';

$queue = [
    '@tiptap/core',
    '@tiptap/starter-kit',
    // Not in StarterKit 3, and needed for images inside a description: the
    // editor extends this node with width/height/size attributes rather than
    // writing its own, so that pasting an <img> and inserting one from the
    // media panel produce the same node.
    '@tiptap/extension-image',
];
$seen = [];
$written = [];

function fetchBody(string $url): string
{
    $ctx = stream_context_create(['http' => ['timeout' => 120, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);

    if ($body === false) {
        throw new RuntimeException("failed: {$url}");
    }

    return $body;
}

/** esm.sh answers bare package requests with a shim that re-exports the real file. */
function followShim(string $body): ?string
{
    if (strlen($body) > 2000 || ! str_contains($body, 'esm.sh')) {
        return null;
    }

    // A shim may also carry side-effect imports (node polyfills); the real
    // module is whatever the re-export points at.
    preg_match_all('~export\s+\*\s+from\s*["\'](/[^"\']+\.mjs)["\']~', $body, $m);
    $targets = array_unique($m[1]);

    return count($targets) === 1 ? $targets[0] : null;
}

function localName(string $spec): string
{
    if (str_starts_with($spec, '@tiptap/') && ! str_starts_with($spec, '@tiptap/pm/')) {
        return str_replace('/', '-', ltrim($spec, '@'));
    }

    if (str_starts_with($spec, '@tiptap/pm/')) {
        return 'tiptap-pm-'.substr($spec, strlen('@tiptap/pm/'));
    }

    $path = ltrim((string) parse_url($spec, PHP_URL_PATH), '/');

    if (preg_match('~^(@[^/]+/[^@/]+|[^@/]+)@~', $path, $m)) {
        return str_replace('/', '-', ltrim($m[1], '@'));
    }

    // esm.sh's unversioned helpers, e.g. /node/process.mjs
    return str_replace('/', '-', preg_replace('~\.m?js$~', '', $path));
}

function fetchUrl(string $spec): string
{
    if (str_starts_with($spec, '@tiptap/')) {
        [$scope, $package, $subpath] = array_pad(explode('/', $spec, 3), 3, null);
        $name = $scope.'/'.$package;

        // @tiptap/pm re-exports ProseMirror; leave those unbundled so every
        // consumer shares one copy of each prosemirror-* package.
        if ($name === '@tiptap/pm') {
            return 'https://esm.sh/@tiptap/pm@'.PM_VERSION.($subpath ? '/'.$subpath : '').'?target=es2020';
        }

        $external = $name === '@tiptap/core' ? '@tiptap/pm' : '@tiptap/core,@tiptap/pm';

        return 'https://esm.sh/'.$name.'@'.TIPTAP_VERSION.($subpath ? '/'.$subpath : '')
            .'?bundle&external='.$external.'&target=es2020';
    }

    return 'https://esm.sh'.$spec;
}

while ($queue) {
    $spec = array_shift($queue);
    $name = localName($spec);

    if (isset($seen[$name])) {
        continue;
    }
    $seen[$name] = $spec;

    $body = fetchBody(fetchUrl($spec));

    if ($real = followShim($body)) {
        $body = fetchBody('https://esm.sh'.$real);
    }

    // Rewrite every dependency to its flat sibling, queueing anything new.
    $body = preg_replace_callback(
        '~(from\s*|import\s*|import\(\s*)(["\'])((?:@[^"\']+|/[^"\']+))\2~',
        function ($m) use (&$queue, &$seen) {
            $dep = $m[3];
            $depName = localName($dep);

            if (! isset($seen[$depName])) {
                $queue[] = $dep;
            }

            return $m[1].$m[2].'./'.$depName.'.mjs'.$m[2];
        },
        $body,
    );

    file_put_contents($out.DIRECTORY_SEPARATOR.$name.'.mjs', $body);
    $written[$name] = strlen($body);
}

ksort($written);
foreach ($written as $name => $size) {
    printf("  %-32s %7d\n", $name.'.mjs', $size);
}
printf("%d files, %d bytes\n", count($written), array_sum($written));
