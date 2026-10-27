<?php

namespace Torgash\Guard\Files;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * An executable file where the pictures are supposed to be (PLAN §26.3).
 *
 * The owner's version of the idea was "stop PHP running if an image with a
 * shell in it gets uploaded". Taken apart, rather than taken as a scare:
 *
 * **The upload path is already safe by NAME.** `Torgash\Media\ImageProcessor`
 * takes the extension from the SNIFFED mime and not from what the browser sent,
 * so `shell.php` pretending to be a picture lands on disk as `.jpg`. A polyglot
 * (a valid GIF with PHP in its tail) stays a polyglot, but a `.gif` is not
 * handed to PHP by any web server.
 *
 * So exactly two things are actually dangerous: a path that writes a file
 * WITHOUT going through the image pipeline (an import, a parser, somebody
 * else's archive, CommerceML's `mode=file`), and a server configuration where
 * `*.php` goes to FastCGI wherever it happens to exist — which is precisely how
 * `/1c_exchange.php` works here, so it is not hypothetical.
 *
 * Hence two answers, and both are needed:
 *
 * 1. **The self-test.** The module cannot write the web server's configuration
 *    (under OSPanel the Caddyfile is generated), so it does the one thing that
 *    can actually be trusted: it writes a harmless `.php` into the uploads
 *    directory, asks the web server for it over HTTP, and reads what comes
 *    back. Source text means PHP is not executed there; the script's output
 *    means it is. Nothing else is evidence — a configuration file that looks
 *    right is not evidence.
 * 2. **The scan.** What is already lying there, by extension (checked per
 *    SEGMENT, so `pic.php.jpg` is caught — the rule CommerceML already learned)
 *    and by content. Never deleted: a shop half-migrated from another engine
 *    has that engine's real script in public/, and deleting it breaks something
 *    that works. It is listed, and the shop decides.
 *
 * Both are declared as invariants, so the finding, the screen, the exemption,
 * the retention and the nightly run all come from machinery that already
 * exists. The self-test is `manual`: it makes an HTTP request to the shop
 * itself, which is not something to do unasked in a nightly sweep on a server
 * with one FastCGI worker.
 */
class Uploads
{
    /** Segments that a web server may hand to an interpreter. */
    public const EXECUTABLE = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
        'htaccess', 'cgi', 'pl', 'py', 'sh',
    ];

    /**
     * Extensions whose CONTENT is not read.
     *
     * Not a safety judgement — a polyglot GIF is still a polyglot — but the
     * arithmetic of the scan: these are the bulk of what is in an uploads
     * directory, and reading the head of fifty thousand photographs every
     * night to look for a tag no web server would act on is how a watchman
     * becomes the load. What matters about them is their NAME, and that is
     * checked for every file without exception.
     */
    public const MEDIA = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff',
        'mp4', 'webm', 'mov', 'mp3', 'ogg', 'woff', 'woff2', 'ttf', 'eot', 'otf',
        'pdf', 'zip',
    ];

    /** What the probe prints when it is EXECUTED rather than served. */
    protected const EXECUTED = 'TORGASH-GUARD-EXECUTED';

    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'guard.php_not_executed_in_uploads' => [
                'label' => 'The web server does not execute PHP where uploads are stored',
                'explain' => 'A file written past the image pipeline — by an import, a parser, an archive — becomes a shell the moment the server is willing to run it. The configuration for your server is under this statement; run the self-test again after changing it.',
                // Not part of the nightly sweep: it asks the shop for a page over
                // HTTP, and a server with a single FastCGI worker would be
                // asking itself a question it cannot answer until it times out.
                'manual' => true,
                'check' => fn () => $this->selfTest(),
            ],

            'guard.no_executable_where_uploads_are' => [
                'label' => 'Nothing executable is lying where the pictures are',
                'explain' => 'A file with an executable extension, or with a PHP tag inside it, in a directory that is served to the public. Look at it before deleting it: a shop half-migrated from another engine legitimately has that engine\'s script there, and this list never removes anything by itself.',
                'check' => fn (int $limit) => $this->scan($limit),
            ],
        ];
    }

    // ------------------------------------------------------------ the self-test

    /**
     * Write a probe, ask the web server for it, read what comes back.
     *
     * Two probes, because two configurations fail differently: a plain `.php`
     * and a double extension (`.php.jpg`), which some Apache setups still hand
     * to PHP on the strength of the inner segment.
     *
     * @return list<array<string, mixed>>
     */
    public function selfTest(): array
    {
        // Uploads in a bucket are served by the bucket, not by this server's
        // PHP: there is nothing here that could run them, so nothing to ask.
        if (app(\App\Core\Files\FileStorage::class)->driver('public') !== 'local') {
            return [];
        }

        $nonce = Str::random(12);
        $problems = [];

        foreach (['php' => 'probe-'.$nonce.'.php', 'double' => 'probe-'.$nonce.'.php.jpg'] as $kind => $name) {
            $path = storage_path('app/public/'.$name);

            try {
                file_put_contents($path, "<?php echo '".self::EXECUTED."';\n");

                $verdict = $this->ask(url('/storage/'.$name));

                if ($verdict !== null) {
                    $problems[] = [
                        'id' => $kind,
                        'title' => ['/storage/*.:extension', ['extension' => $kind === 'php' ? 'php' : 'php.jpg']],
                        'detail' => $verdict,
                    ];
                }
            } catch (Throwable $e) {
                $problems[] = [
                    'id' => $kind,
                    'title' => 'storage/app/public',
                    'detail' => ['The self-test could not run: :reason', ['reason' => $e->getMessage()]],
                ];
            } finally {
                // A probe must never outlive the test that wrote it — that
                // would be this module leaving behind exactly what it warns
                // about.
                @unlink($path);
            }
        }

        return $problems;
    }

    /**
     * One probe's verdict, or null when the answer is a safe one.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    protected function ask(string $url): ?array
    {
        try {
            // The certificate is not checked, and that is not a shortcut: we
            // are asking OURSELVES, by a name we already answer to, and the
            // only thing read from the response is whether the bytes of a file
            // we just wrote came back as text or as output. A shop behind an
            // internal or self-signed certificate must still be able to run
            // its own self-test.
            $response = Http::withoutVerifying()->timeout(8)->get($url);
        } catch (Throwable $e) {
            return ['The self-test could not reach the shop: :reason', ['reason' => $e->getMessage()]];
        }

        $body = (string) $response->body();

        if (str_contains($body, self::EXECUTED)) {
            return ['The server EXECUTED it — a file written past the image pipeline would run as code', []];
        }

        if ($response->status() >= 400) {
            // The best outcome there is: not served at all.
            return null;
        }

        if (str_contains($body, '<?php')) {
            // Served as text. Not a hole — the file cannot run — so nothing to
            // report; whether uploads should be downloadable at all is the
            // shop's decision, not an invariant.
            return null;
        }

        return ['The answer was neither the file nor a refusal (:status) — check by hand', ['status' => $response->status()]];
    }

    // ----------------------------------------------------------------- the scan

    /**
     * What is already there. A generator, so the engine's limit stops the walk
     * rather than filtering a finished list — these directories hold every
     * picture the shop has.
     */
    public function scan(int $limit): iterable
    {
        $expected = array_map(
            fn (string $path) => str_replace('\\', '/', $path),
            // What the ENGINE itself puts in public/: the front controller and
            // the skeleton's Apache rules. A module that legitimately writes an
            // executable file there adds its own through the filter — the one
            // that exists is CommerceML's `/1c_exchange.php`, and only while
            // the file is still ours.
            hook_filter('guard.files.expected', [
                public_path('index.php'),
                public_path('.htaccess'),
            ]),
        );

        $found = 0;

        foreach ($this->roots() as $label => $root) {
            if (! is_dir($root)) {
                continue;
            }

            $finder = (new Finder)->files()->in($root)->ignoreDotFiles(false)->ignoreVCS(true);

            foreach ($finder as $file) {
                $real = str_replace('\\', '/', $file->getPathname());

                if (in_array($real, $expected, true)) {
                    continue;
                }

                $reason = $this->suspicious($file);

                if ($reason === null) {
                    continue;
                }

                yield [
                    'id' => mb_substr($label.':'.$file->getRelativePathname(), 0, 190),
                    'title' => $label.'/'.str_replace('\\', '/', $file->getRelativePathname()),
                    'detail' => $reason,
                ];

                if (++$found >= $limit) {
                    return;
                }
            }
        }
    }

    /** @return array<string, string> label => absolute path */
    protected function roots(): array
    {
        // The uploads folder only while uploads ARE a local folder; in a
        // bucket they are out of this server's reach, and so out of its PHP's.
        return array_filter([
            'storage/app/public' => app(\App\Core\Files\FileStorage::class)->driver('public') === 'local' ? storage_path('app/public') : null,
            'public' => public_path(),
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null why this file is
     *                                                        suspicious
     */
    protected function suspicious(SplFileInfo $file): ?array
    {
        // Per SEGMENT, never by stripping the last one: `pic.php.jpg` is a
        // `.jpg` to a naive check and a `.php` to more than one web server.
        // The same rule CommerceML learned about uploaded file names.
        foreach (explode('.', mb_strtolower($file->getFilename())) as $segment) {
            if (in_array($segment, self::EXECUTABLE, true)) {
                return ['An executable name (.:segment)', ['segment' => $segment]];
            }
        }

        if (in_array(mb_strtolower($file->getExtension()), self::MEDIA, true)) {
            return null;
        }

        $head = (string) @file_get_contents($file->getPathname(), false, null, 0, 2048);

        foreach (['<?php', '<?=', '<script language="php"'] as $tag) {
            if (str_contains($head, $tag)) {
                return ['Code inside a file that is served to the public (:tag)', ['tag' => $tag]];
            }
        }

        return null;
    }

    // ---------------------------------------------------------- what to change

    /** What the server calls itself, as far as this request can tell. */
    public function server(): string
    {
        return (string) (request()->server('SERVER_SOFTWARE') ?: __('unknown'));
    }

    /**
     * The configuration to add, for each server the engine is likely to be
     * behind. Printed rather than written: the Caddyfile here is generated by
     * OSPanel and an edit would not survive a restart, so the honest thing is
     * to hand over the lines and then PROVE the result with the self-test.
     *
     * @return array<string, string>
     */
    public function snippets(): array
    {
        return [
            'Caddy' => <<<'CADDY'
                # Hand PHP everything EXCEPT the uploads directory.
                @notUploads not path /storage/*
                php_fastcgi @notUploads 127.0.0.1:9000
                CADDY,

            'nginx' => <<<'NGINX'
                location ^~ /storage/ {
                    location ~ \.php$ { return 403; }
                }
                NGINX,

            'Apache' => <<<'APACHE'
                # storage/app/public/.htaccess
                <FilesMatch "\.(?i:php|phar|phtml|php[0-9])$">
                    Require all denied
                </FilesMatch>
                php_flag engine off
                APACHE,
        ];
    }
}
