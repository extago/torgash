<?php

namespace App\Core\Seo;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The picture a link shows when it is pasted into a messenger or posted to a
 * social network (og:image): the storefront's own — its home page and every
 * page with no picture of its own — and a record's, from the SEO card
 * (`share_image` columns). One file on the public disk, its path in the
 * owner's column, `<share>_file` uploads it and `<share>_image_remove`
 * drops it (the category pictures' convention).
 *
 * Never an SVG: no messenger draws one, and the shop's origin would serve a
 * document that can carry a script. Kept as uploaded otherwise — the
 * networks scale what they are given themselves, and a converted copy would
 * be a second file to keep in step.
 */
class SharePicture
{
    public const DIR = 'share-images';

    /** @return array<string, list<string>> validation for a form carrying one, as `<share>_file` / `<share>_image_remove` */
    public static function rules(string $share = 'share'): array
    {
        return [
            $share.'_file' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120'],
            $share.'_image_remove' => ['nullable', 'boolean'],
        ];
    }

    /** Whether the form said anything about the picture — a form without the field leaves the column alone. */
    public static function submitted(Request $request, string $share = 'share'): bool
    {
        return $request->hasFile($share.'_file') || $request->boolean($share.'_image_remove');
    }

    /** The column's new value: the upload stored (the old file goes), or none when "remove" is ticked, or what it was. */
    public static function fromRequest(Request $request, ?string $current, string $share = 'share'): ?string
    {
        if ($request->hasFile($share.'_file')) {
            $file = $request->file($share.'_file');
            $path = $file->storeAs(self::DIR, Str::random(40).'.'.strtolower($file->extension()), 'public');
            self::forget($current);

            return $path ?: $current;
        }

        if ($request->boolean($share.'_image_remove')) {
            self::forget($current);

            return null;
        }

        return $current;
    }

    /** The picture's address, or null for none — or for a path that is not one of these files. */
    public static function url(?string $path): ?string
    {
        return $path && str_starts_with($path, self::DIR.'/') ? Storage::disk('public')->url($path) : null;
    }

    /**
     * A file of its own for a copy of the record (a duplicated product): two
     * rows sharing one file would lose it together the day one of them
     * replaces or removes its picture.
     */
    public static function copy(?string $path): ?string
    {
        if (! $path || ! str_starts_with($path, self::DIR.'/') || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $copy = self::DIR.'/'.Str::random(40).'.'.pathinfo($path, PATHINFO_EXTENSION);

        return Storage::disk('public')->copy($path, $copy) ? $copy : null;
    }

    public static function forget(?string $path): void
    {
        if ($path && str_starts_with($path, self::DIR.'/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
