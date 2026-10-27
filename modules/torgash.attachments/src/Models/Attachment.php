<?php

namespace Torgash\Attachments\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One document beside a record: a file of the shop's own, or a link to one
 * somewhere else. See the migration.
 */
class Attachment extends Model
{
    use HasTranslations;

    public const DISK = 'private';

    public const DIRECTORY = 'attachments';

    public const ACCESS = ['all', 'account'];

    protected $table = 'm_attachments_files';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Document';
    }

    protected static function booted(): void
    {
        // The row and its bytes go together: a file nothing names is a file
        // nobody will ever find to delete.
        static::deleted(function (Attachment $attachment) {
            if ($attachment->path) {
                Storage::disk(self::DISK)->delete($attachment->path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'size' => 'int',
            'downloads' => 'int',
            'position' => 'int',
        ];
    }

    public function scopeOwnedBy(Builder $query, string $ownerType, int $ownerId): Builder
    {
        return $query->where('owner_type', $ownerType)->where('owner_id', $ownerId)->orderBy('position')->orderBy('id');
    }

    public function isLink(): bool
    {
        return $this->path === null && $this->url !== null;
    }

    public function forAccountsOnly(): bool
    {
        return $this->access === 'account';
    }

    /** The typed title in the page's language, else the file's own name without its extension. */
    public function label(?string $locale = null): string
    {
        $title = trim((string) $this->t('title', $locale));

        if ($title !== '') {
            return $title;
        }

        if ($this->original_name) {
            return pathinfo($this->original_name, PATHINFO_FILENAME) ?: $this->original_name;
        }

        return $this->host();
    }

    /** What the reader is told it is: "PDF · 672 KB", or the site a link leads to. */
    public function details(): string
    {
        if ($this->isLink()) {
            return $this->host();
        }

        return trim(strtoupper((string) $this->extension).($this->size ? ' · '.self::humanSize($this->size) : ''), ' ·');
    }

    /** A Lucide icon for the kind of thing it is. */
    public function icon(): string
    {
        if ($this->isLink()) {
            return 'external-link';
        }

        return match ((string) $this->extension) {
            'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt' => 'file-text',
            'xls', 'xlsx', 'ods', 'csv' => 'file-spreadsheet',
            'zip', '7z', 'rar' => 'file-archive',
            'jpg', 'jpeg', 'png', 'webp' => 'file-image',
            'dwg', 'dxf', 'step', 'stp', 'stl' => 'file-box',
            default => 'file',
        };
    }

    /** Opened in the browser's own viewer rather than saved — a PDF or a picture. */
    public function opensInline(): bool
    {
        return in_array($this->extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);
    }

    /**
     * Where a reader gets it. A file goes through the shop (who may, the
     * count) under its own name, so the saved file is called what it was; a
     * link goes straight where it points.
     */
    public function href(): string
    {
        if ($this->isLink()) {
            return (string) $this->url;
        }

        return route('attachments.download', ['attachment' => $this->id, 'name' => $this->downloadName()]);
    }

    /** `ef-core-datasheet.pdf` — the uploaded name made safe for an address and a header. */
    public function downloadName(): string
    {
        $name = Str::slug(pathinfo((string) $this->original_name, PATHINFO_FILENAME)) ?: 'document';

        return $name.'.'.$this->extension;
    }

    protected function host(): string
    {
        return (string) preg_replace('/^www\./', '', (string) (parse_url((string) $this->url, PHP_URL_HOST) ?: $this->url));
    }

    public static function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' '.__('MB'),
            $bytes >= 1024 => (int) round($bytes / 1024).' '.__('KB'),
            default => $bytes.' '.__('B'),
        };
    }
}
