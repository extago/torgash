<?php

namespace Torgash\Blocks;

use App\Core\Support\SafeSvg;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Torgash\Blocks\Models\Block;

/**
 * Pictures a block's settings name — an `image` part of a `rows` field (a
 * category tile's own picture). Stored on the public disk under one folder,
 * declared to core (`core.files.directories`) so the copy command and the
 * storage screen know whose they are.
 *
 * A file is written the moment it is picked (the form gets its path back and
 * posts it like any other value), as the theme's pictures are, so a file
 * never rides the block's own form. A value is accepted only when it is
 * shaped like a path WE wrote: anything else a request carries is refused
 * rather than printed into an <img>. When a block stops naming a picture —
 * replaced, taken out, the block deleted — the file goes.
 */
class BlockImages
{
    public const DIR = 'block-images';

    /** Store an upload; answers its path on the public disk. */
    public static function store(UploadedFile $file): string
    {
        $name = self::DIR.'/'.now()->format('Ym').'/tile-'.Str::random(16);

        // An SVG is a document that can carry a script, and the shop's own
        // origin would run it: rebuilt from an allow-list first, refused when
        // nothing drawable is left — the same as a theme's pictures.
        if (strtolower($file->getClientOriginalExtension()) === 'svg' || $file->getMimeType() === 'image/svg+xml') {
            $svg = SafeSvg::clean((string) file_get_contents($file->getRealPath()));

            if ($svg === null) {
                throw ValidationException::withMessages(['file' => __('This SVG could not be read as a drawing.')]);
            }

            Storage::disk('public')->put($name.'.svg', $svg);

            return $name.'.svg';
        }

        return $file->storeAs(dirname($name), basename($name).'.'.strtolower($file->extension()), 'public');
    }

    /** A value an image part may hold: a file we wrote, named the way we name them. */
    public static function isOurs(mixed $path): bool
    {
        return is_string($path)
            && preg_match('#^'.self::DIR.'/\d{6}/tile-[A-Za-z0-9]{16}\.(png|jpe?g|webp|gif|svg)$#', $path) === 1;
    }

    public static function url(?string $path): ?string
    {
        return self::isOurs($path) ? Storage::disk('public')->url($path) : null;
    }

    /**
     * Every picture a block's settings name, by walking its `rows` fields.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public static function named(string $type, array $settings): array
    {
        $paths = [];

        foreach (BlockTypes::schema($type) as $field) {
            if (($field['type'] ?? null) !== 'rows') {
                continue;
            }

            $parts = array_column(array_filter((array) ($field['fields'] ?? []), fn ($part) => ($part['type'] ?? null) === 'image'), 'key');

            foreach ((array) ($settings[$field['key']] ?? []) as $row) {
                foreach ($parts as $part) {
                    if (is_array($row) && self::isOurs($row[$part] ?? null)) {
                        $paths[] = $row[$part];
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * The pictures $before named and $after does not — deleted, unless some
     * other block still names the same file.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public static function forget(array $before, array $after, ?int $exceptBlock = null): void
    {
        $gone = array_values(array_diff($before, $after));

        if ($gone === []) {
            return;
        }

        $stillNamed = [];
        Block::query()->when($exceptBlock, fn ($query) => $query->whereKeyNot($exceptBlock))
            ->get(['id', 'type', 'settings'])
            ->each(function (Block $other) use (&$stillNamed) {
                foreach (self::named($other->type, (array) $other->settings) as $path) {
                    $stillNamed[$path] = true;
                }
            });

        $disk = Storage::disk('public');
        foreach ($gone as $path) {
            if (! isset($stillNamed[$path])) {
                $disk->delete($path);
            }
        }
    }
}
