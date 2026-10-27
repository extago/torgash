<?php

namespace Torgash\Options;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Torgash\Options\Models\OptionFile;
use Torgash\Options\Models\OptionGroup;

/**
 * The one place a customer's file is written, read back and thrown away
 * (PLAN §33.7) — what `Configurator` is for an answer and `Attachments` is for
 * a photograph sent with an enquiry.
 *
 * It is deliberately NOT `Attachments`, and the difference is the whole
 * design. That one takes a photograph of a fault and hands it to
 * `ImageProcessor`, which sniffs the type, re-encodes and shrinks it: exactly
 * right, because what matters is that a human can see the damage. Here the
 * file IS the thing being bought — a layout, a logo, artwork for a mug — and
 * re-encoding it destroys it. So the bytes are stored as they arrived.
 *
 * Which means the safety has to come from somewhere else, and it comes from
 * four rules:
 *
 * 1. **No endpoint of its own.** The file rides the POST that was already
 *    going to happen (adding to the cart), so there is no second public door
 *    and the form's own rate limit counts it. An asynchronous uploader is a
 *    route that accepts files before anybody has said anything.
 * 2. **The shop's whitelist, checked against the SNIFFED type.** A name is
 *    what a stranger typed and is never trusted: the extension is taken from
 *    what the bytes say they are, and only if the shop listed it. A type the
 *    shop did not list is refused rather than sanitised.
 * 3. **A private disk, under a name of ours.** Nothing is ever written where a
 *    web server could be asked for it — which is the whole of §26.3's lesson
 *    about an executable file where the pictures are — and the stored name is
 *    a random token with an extension we chose, so a `logo.php.png` cannot
 *    become anything.
 * 4. **A refused file does not cost the rest of the answer.** The add is
 *    refused, but the form comes back with every other answer still in it and
 *    the reason beside the question — a browser cannot re-fill a file input
 *    anyway, so the file is the one thing that always has to be chosen again.
 *
 * Nothing here ever overwrites: every upload is a new row, because a cart line
 * that was bought must keep the bytes that were bought.
 */
class Files
{
    /** What the shop takes unless a question says otherwise. */
    public const DEFAULT_TYPES = 'jpg,jpeg,png,webp,pdf';

    /**
     * Extensions this engine will never take, whatever a shop types.
     *
     * Not a sanitising step — a refusal. Two families: what a web server might
     * execute if it ever escaped the private disk, and what a browser runs in
     * the page's own origin when it is served inline. SVG is in the second and
     * is the one that surprises people: it is an image everywhere until it
     * carries a script.
     */
    public const NEVER = [
        'php', 'phar', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht',
        'htaccess', 'html', 'htm', 'xhtml', 'svg', 'js', 'mjs', 'exe', 'sh', 'bat', 'cmd',
    ];

    /**
     * Take one file for one question, or say why not.
     *
     * @return array{file: OptionFile|null, error: string|null}
     */
    public function store(OptionGroup $group, UploadedFile $file, ?string $cartToken = null): array
    {
        if (! $file->isValid()) {
            return ['file' => null, 'error' => __('The file did not arrive in one piece.')];
        }

        $maxKb = $this->maxKb($group);

        if ($maxKb > 0 && $file->getSize() > $maxKb * 1024) {
            return ['file' => null, 'error' => __(':group takes files up to :n.', [
                'group' => $group->t('name'),
                'n' => $this->humanKb($maxKb),
            ])];
        }

        // What the BYTES say it is, never what it was called. `guessExtension()`
        // reads the mime from the content and maps it back to an extension, so
        // a `layout.pdf` that is really a script has no extension we accept.
        $extension = strtolower((string) ($file->guessExtension() ?: ''));
        $allowed = $this->types($group);

        if ($extension === '' || in_array($extension, self::NEVER, true) || ! in_array($extension, $allowed, true)) {
            return ['file' => null, 'error' => __(':group takes :types.', [
                'group' => $group->t('name'),
                'types' => strtoupper(implode(', ', $allowed)),
            ])];
        }

        $token = OptionFile::newToken();
        $path = $file->storeAs(
            OptionFile::DIRECTORY,
            $token.'.'.$extension,
            ['disk' => OptionFile::DISK],
        );

        if (! $path) {
            return ['file' => null, 'error' => __('The file could not be saved.')];
        }

        return ['file' => OptionFile::create([
            'token' => $token,
            'group_id' => $group->id,
            'path' => $path,
            // Kept for a human to read and shown as text — never used to open
            // anything, and stripped of its directories, because a browser is
            // allowed to send `../../etc/passwd` as a name.
            'original_name' => Str::limit(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 120, ''),
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size' => (int) $file->getSize(),
            'cart_token' => $cartToken,
        ]), 'error' => null];
    }

    /**
     * Take whatever files came with THIS request and put their tokens into the
     * configuration bag, so that everything downstream — `Configurator`, the
     * line key, the order — only ever deals in tokens.
     *
     * Reading the request here is deliberate and is the narrow version of rule
     * 1: a file cannot travel in `$request->input()`, so either the cart's
     * contract grows a second argument that only one module will ever use, or
     * the module that understands files fetches them itself. The second is the
     * smaller change, and `OptionsServiceProvider` already reads the request
     * for the line being edited.
     *
     * An answer already holding a token is left alone — that is the file the
     * line was bought with, and re-opening the form to change the processor
     * must not throw the artwork away.
     *
     * @param  iterable<OptionGroup>  $groups
     * @param  array<string, mixed>  $config  the whole bag
     * @return array{config: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function absorb(iterable $groups, array $config, ?string $cartToken = null): array
    {
        $answers = (array) ($config[Configurator::KEY] ?? []);
        $errors = [];

        foreach ($groups as $group) {
            if (! $group->isFile()) {
                continue;
            }

            $key = (string) $group->id;
            $uploaded = request()->file('config.'.Configurator::KEY.'.'.$key.'.file');

            if (! $uploaded instanceof UploadedFile) {
                continue;
            }

            $result = $this->store($group, $uploaded, $cartToken);

            if ($result['error'] !== null) {
                $errors['options.'.$group->id][] = $result['error'];

                continue;
            }

            // The new file replaces whatever this question was answered with;
            // the old row is left to the sweep rather than deleted here,
            // because a line that was already BOUGHT may still point at it.
            $answers[$key] = ['token' => $result['file']->token];
        }

        if ($answers !== []) {
            $config[Configurator::KEY] = $answers;
        }

        return ['config' => $config, 'errors' => $errors];
    }

    /** The row a configuration entry points at, or null when it has gone. */
    public function find(?string $token): ?OptionFile
    {
        return $token ? OptionFile::where('token', $token)->first() : null;
    }

    /**
     * An order has been placed: the files its lines point at stop being a
     * basket's business and become part of the order, so the sweep leaves them
     * alone for as long as the order is there.
     *
     * @param  list<string>  $tokens
     */
    public function claim(array $tokens, int $orderId): void
    {
        if ($tokens === []) {
            return;
        }

        OptionFile::whereIn('token', $tokens)->update([
            'order_id' => $orderId,
            'cart_token' => null,
        ]);
    }

    /**
     * Files nobody bought, after `files_keep_days`.
     *
     * A basket abandoned with a layout in it is the ordinary case, not the
     * exceptional one, and without this the disk grows for ever. Never touches
     * a file an order claimed: that one is part of a sale and is kept as long
     * as the sale is.
     */
    public function sweep(): int
    {
        $days = max(1, (int) setting('torgash.options', 'files_keep_days', 30));
        $removed = 0;

        OptionFile::whereNull('order_id')
            ->where('created_at', '<', now()->subDays($days))
            ->chunkById(100, function ($files) use (&$removed) {
                foreach ($files as $file) {
                    $file->purge();
                    $removed++;
                }
            });

        return $removed;
    }

    /** How big a file this question takes, in kilobytes; 0 is "no limit stated". */
    public function maxKb(OptionGroup $group): int
    {
        return max(0, (int) ($group->max_kb ?: setting('torgash.options', 'files_max_kb', 10240)));
    }

    /**
     * The extensions this question takes: its own, else the shop's, minus
     * whatever this engine refuses outright.
     *
     * @return list<string>
     */
    public function types(OptionGroup $group): array
    {
        $raw = (string) ($group->file_types ?: setting('torgash.options', 'files_types', self::DEFAULT_TYPES));

        $types = array_values(array_unique(array_filter(array_map(
            fn (string $type) => strtolower(trim($type, " \t\n\r\0\x0B.")),
            explode(',', $raw),
        ))));

        $types = array_values(array_diff($types, self::NEVER));

        return $types === [] ? explode(',', self::DEFAULT_TYPES) : $types;
    }

    /** What the form tells the browser to offer in its picker. */
    public function accept(OptionGroup $group): string
    {
        return implode(',', array_map(fn (string $type) => '.'.$type, $this->types($group)));
    }

    protected function humanKb(int $kb): string
    {
        return $kb >= 1024 ? round($kb / 1024, 1).' MB' : $kb.' KB';
    }
}
