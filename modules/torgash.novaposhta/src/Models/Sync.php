<?php

namespace Torgash\NovaPoshta\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Last-synced markers, keyed by a string. See the migration.
 */
class Sync extends Model
{
    protected $table = 'm_np_sync';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Nova Poshta sync';
    }

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** Is the marker for $key missing or older than $ttl? */
    public static function stale(string $key, \DateInterval $ttl): bool
    {
        $row = static::find($key);

        return $row === null || $row->synced_at === null || $row->synced_at->add($ttl)->isPast();
    }

    public static function mark(string $key, ?array $meta = null): void
    {
        static::updateOrCreate(['key' => $key], ['synced_at' => now(), 'meta' => $meta]);
    }
}
