<?php

namespace Torgash\Files\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file of the storage. `path` is where the bytes are and what the link is
 * made of; `name` is only what the staff call it. See the migration.
 */
class StoredFile extends Model
{
    public const DISK = 'public';

    public const DIRECTORY = 'files';

    public const RASTER = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    protected $table = 'm_files_files';

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public static function auditLabel(): string
    {
        return 'Stored file';
    }

    protected static function booted(): void
    {
        // The row and its bytes go together.
        static::deleted(function (StoredFile $file) {
            $disk = Storage::disk(self::DISK);
            $disk->delete(array_values(array_filter([$file->path, $file->thumb_path])));
        });
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id');
    }

    public function isImage(): bool
    {
        return $this->extension === 'svg' || $this->extension === 'ico' || in_array($this->extension, self::RASTER, true);
    }

    /**
     * The permanent public link, written out in full: it is pasted into
     * feeds and other sites, where an address starting with "/" means nothing.
     */
    public function url(): string
    {
        return url(Storage::disk(self::DISK)->url($this->path));
    }

    /** A small picture for the grid: the thumbnail if one was made, else the file itself. */
    public function previewUrl(): ?string
    {
        if (! $this->isImage()) {
            return null;
        }

        return url(Storage::disk(self::DISK)->url($this->thumb_path ?: $this->path));
    }

    /**
     * What the preview dialog shows, as the data-* attributes of whatever
     * opens it (a tile or a list row) — the screen's one source for both.
     *
     * @return array<string, string>
     */
    public function dialogData(): array
    {
        return [
            'file-id' => (string) $this->id,
            'url' => $this->url(),
            'preview' => $this->isImage() ? $this->url() : '',
            'name' => (string) $this->name,
            'type' => strtoupper((string) $this->extension),
            'size' => $this->sizeText(),
            'dimensions' => $this->width ? $this->width.' × '.$this->height.' px' : '',
            'uploaded' => (string) $this->created_at?->format('d.m.Y H:i'),
            'rename' => route('admin.files.rename', $this),
            'delete' => route('admin.files.destroy', $this),
        ];
    }

    public function sizeText(): string
    {
        $bytes = (int) $this->size;

        return match (true) {
            $bytes >= 1048576 => rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.').' MB',
            $bytes >= 1024 => (int) round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
