<?php

namespace Torgash\Exim\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An uploaded file not imported yet (see the migration): reopened from the
 * import screen's list or by its address, as it was left.
 */
class ImportDraft extends Model
{
    /** Days a draft nobody touches is kept, file and all. */
    public const DAYS = 7;

    /** Where the files wait, on the private disk. */
    public const DIR = 'exim/imports';

    protected $table = 'm_exim_import_drafts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'options' => 'array',
            'row_count' => 'integer',
            'column_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /** Columns the draft's mapping imports. */
    public function mappedCount(): int
    {
        return count(array_filter((array) $this->mapping, fn ($key) => $key !== '' && $key !== null));
    }

    public function expiresAt(): \Illuminate\Support\Carbon
    {
        return $this->updated_at->copy()->addDays(self::DAYS);
    }

    /** Gone, with its file. */
    public function discard(): void
    {
        Storage::disk('private')->delete($this->path);
        $this->delete();
    }

    /**
     * Drafts nobody touched for DAYS, and files in the folder that belong to
     * no draft — an import whose job failed leaves its file behind.
     */
    public static function sweep(): int
    {
        $swept = 0;

        foreach (static::query()->where('updated_at', '<', now()->subDays(self::DAYS))->get() as $draft) {
            $draft->discard();
            $swept++;
        }

        $disk = Storage::disk('private');
        $kept = static::query()->pluck('path')->flip();

        foreach ($disk->files(self::DIR) as $file) {
            if (! isset($kept[$file]) && $disk->lastModified($file) < now()->subDays(self::DAYS)->getTimestamp()) {
                $disk->delete($file);
            }
        }

        return $swept;
    }
}
