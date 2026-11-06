<?php

namespace Torgash\Files\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A folder of the file storage — a row only, see the migration. */
class Folder extends Model
{
    protected $table = 'm_files_folders';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Storage folder';
    }

    protected static function booted(): void
    {
        // The database would cascade the rows, but not the bytes: each file
        // and each folder inside goes through its own model first.
        static::deleting(function (Folder $folder) {
            $folder->children()->get()->each->delete();
            $folder->files()->get()->each->delete();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'folder_id');
    }

    /**
     * This folder and the ones above it, the top first — for the path over
     * the list. A folder tree of a shop is a few dozen rows: read whole.
     *
     * @return list<Folder>
     */
    public function trail(): array
    {
        $trail = [];
        $folder = $this;
        $seen = [];

        while ($folder !== null && ! isset($seen[$folder->id])) {
            $seen[$folder->id] = true;
            array_unshift($trail, $folder);
            $folder = $folder->parent;
        }

        return $trail;
    }

    /** Is `$other` this folder or somewhere inside it — where it may not be moved. */
    public function contains(Folder $other): bool
    {
        foreach ($other->trail() as $step) {
            if ($step->id === $this->id) {
                return true;
            }
        }

        return false;
    }
}
