<?php

namespace Torgash\Options\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One file a customer sent as an answer (PLAN §33.7).
 *
 * The row is what the cart line's configuration points at, by `token`. What it
 * is NOT is a media-library attachment: the media module re-encodes and
 * resizes, which is right for a photograph of a broken kettle and wrong for
 * the thing being bought — a print layout shrunk to 2000px is no longer a
 * print layout. So this is bytes as they arrived, on a private disk, under a
 * name of ours.
 */
class OptionFile extends Model
{
    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Uploaded option file';
    }

    /** Never the public one: this is somebody's artwork, not a catalogue photo. */
    public const DISK = 'private';

    /** Where on that disk, so nothing else in the shop writes here. */
    public const DIRECTORY = 'option-files';

    protected $table = 'm_options_files';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'int',
            'order_id' => 'int',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'group_id');
    }

    /**
     * The public handle. Not the id — that would let anybody count the shop's
     * uploads and ask for the next one — and not a uuid, which says when it
     * was made; the same reasoning as a cart's own token.
     */
    public static function newToken(): string
    {
        return Str::random(40);
    }

    /** Is the file still there? A row whose bytes are gone is not an answer. */
    public function exists(): bool
    {
        return Storage::disk(self::DISK)->exists($this->path);
    }

    /** The row and its bytes, together — the one way a file leaves. */
    public function purge(): void
    {
        Storage::disk(self::DISK)->delete($this->path);

        $this->delete();
    }

    /** "визитка-final-2.pdf (1.4 MB)", for a manager and for the workshop. */
    public function label(): string
    {
        return $this->original_name.' ('.$this->humanSize().')';
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $size < 10 && $unit > 0 ? 1 : 0).' '.$units[$unit];
    }
}
