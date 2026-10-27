<?php

namespace Torgash\Attachments;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Torgash\Attachments\Models\Attachment;

/**
 * The one place a document is written, listed and thrown away.
 *
 * The bytes are kept as they arrived — a specification sheet re-encoded is no
 * longer the maker's sheet — so the safety is the same four rules as
 * `Torgash\Options\Files`: the type comes from what the BYTES are and must be
 * on the shop's list; `NEVER` refuses programs and anything a browser runs in
 * the shop's own origin whatever the list says; the disk is private and the
 * stored name is ours; and the file is handed back only through the shop's
 * route, which decides who may have it.
 */
class Documents
{
    public const NEVER = [
        'php', 'phar', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht',
        'htaccess', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'js', 'mjs',
        'exe', 'msi', 'dll', 'com', 'scr', 'sh', 'bat', 'cmd', 'ps1', 'vbs', 'jar',
    ];

    /** @return Collection<int, Attachment> */
    public function of(string $ownerType, int $ownerId): Collection
    {
        return Attachment::query()->ownedBy($ownerType, $ownerId)->get();
    }

    /**
     * What the shop takes, from the module's setting, minus what is never
     * taken.
     *
     * @return list<string>
     */
    public function types(): array
    {
        $listed = preg_split('/[\s,;]+/', strtolower((string) setting('torgash.attachments', 'types')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_diff(array_unique(array_map(fn ($t) => ltrim($t, '.'), $listed)), self::NEVER));
    }

    public function maxBytes(): int
    {
        return max(1, (int) setting('torgash.attachments', 'max_mb', 50)) * 1048576;
    }

    /** A file of the shop's own, after the record's other documents. */
    public function upload(string $ownerType, int $ownerId, UploadedFile $file): Attachment
    {
        if (! $file->isValid()) {
            throw new RuntimeException(__('The file did not arrive. It may be bigger than the server accepts.'));
        }

        $name = Str::limit(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 180, '');

        if ($file->getSize() > $this->maxBytes()) {
            throw new RuntimeException(__(':name: the shop takes files up to :max MB.', ['name' => $name, 'max' => (int) ($this->maxBytes() / 1048576)]));
        }

        $extension = $this->extension($file);

        if ($extension === null) {
            throw new RuntimeException(__(':name: the shop takes :types.', ['name' => $name, 'types' => strtoupper(implode(', ', $this->types()))]));
        }

        $path = $file->storeAs(Attachment::DIRECTORY.'/'.$ownerType.'/'.$ownerId, Str::random(32).'.'.$extension, ['disk' => Attachment::DISK]);

        if (! $path) {
            throw new RuntimeException(__('The file could not be saved.'));
        }

        return Attachment::create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'path' => $path,
            'original_name' => $name,
            'extension' => $extension,
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size' => (int) $file->getSize(),
            'position' => $this->nextPosition($ownerType, $ownerId),
        ]);
    }

    /** A link to a document somewhere else — http(s) only. */
    public function link(string $ownerType, int $ownerId, string $url): Attachment
    {
        $url = trim($url);

        if (! preg_match('#^https?://[^\s/]+#i', $url) || mb_strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException(__('A link starts with https:// or http://.'));
        }

        return Attachment::create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'url' => $url,
            'position' => $this->nextPosition($ownerType, $ownerId),
        ]);
    }

    /**
     * Titles, who sees each and the order, as the owner's form posted them —
     * a title in the form's content language only (put_translation), every
     * other language kept.
     *
     * @param  array<int|string, mixed>  $input  documents[<id>][title|access|position]
     */
    public function applyForm(string $ownerType, int $ownerId, array $input): void
    {
        foreach (Attachment::query()->ownedBy($ownerType, $ownerId)->get() as $attachment) {
            $row = $input[$attachment->id] ?? null;

            if (! is_array($row)) {
                continue;
            }

            if (array_key_exists('title', $row)) {
                $attachment->title = put_translation($attachment->title, mb_substr((string) $row['title'], 0, 255)) ?: null;
            }

            if (isset($row['access']) && in_array($row['access'], Attachment::ACCESS, true)) {
                $attachment->access = $row['access'];
            }

            if (isset($row['position']) && is_numeric($row['position'])) {
                $attachment->position = (int) $row['position'];
            }

            $attachment->save();
        }
    }

    /** Every document of a record, files and all — the record is gone for good. */
    public function forget(string $ownerType, int $ownerId): void
    {
        Attachment::query()->ownedBy($ownerType, $ownerId)->get()->each->delete();
    }

    /**
     * The extension we store it under: what the bytes say, if the shop takes
     * it. A name ending in .pdf whose bytes are a script has no extension we
     * accept. Formats the sniffer only knows as their container (a .docx is a
     * zip, a .step or .dwg is "text" or "binary") keep the name's extension
     * when the shop lists it and the container agrees.
     */
    protected function extension(UploadedFile $file): ?string
    {
        $allowed = $this->types();
        $sniffed = strtolower((string) ($file->guessExtension() ?: ''));
        $named = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($named, self::NEVER, true) || in_array($sniffed, self::NEVER, true)) {
            return null;
        }

        if ($sniffed !== '' && in_array($sniffed, $allowed, true) && ($named === '' || $named === $sniffed || ! in_array($named, $allowed, true))) {
            return $sniffed;
        }

        $containers = [
            'zip' => ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'],
            'txt' => ['csv', 'step', 'stp', 'dxf', 'stl'],
            'bin' => ['dwg', 'stl', 'step', 'stp', '7z', 'rar'],
        ];
        $mime = strtolower((string) $file->getMimeType());
        $container = match (true) {
            $sniffed === 'zip' || str_contains($mime, 'zip') || str_contains($mime, 'openxmlformats') || str_contains($mime, 'opendocument') => 'zip',
            str_starts_with($mime, 'text/') && $mime !== 'text/html' && $mime !== 'text/xml' => 'txt',
            $mime === 'application/octet-stream' || $sniffed === 'bin' => 'bin',
            default => null,
        };

        if ($container !== null && in_array($named, $allowed, true) && in_array($named, $containers[$container], true)) {
            return $named;
        }

        return ($sniffed !== '' && in_array($sniffed, $allowed, true)) ? $sniffed : null;
    }

    protected function nextPosition(string $ownerType, int $ownerId): int
    {
        return (int) Attachment::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->max('position') + 1;
    }
}
