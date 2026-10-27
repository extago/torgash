<?php

namespace Torgash\Guard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing that is wrong right now.
 *
 * @property string $key
 * @property string $entity_id
 */
class Finding extends Model
{
    protected $table = 'm_guard_findings';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Check finding';
    }

    /** Written and rewritten by the runner; there is no "updated" to record. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'title_params' => 'array',
            'detail_params' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ignored_at' => 'datetime',
        ];
    }

    /**
     * The two sentences, in the language of whoever is reading — not of
     * whoever ran the check. A plain name (an order number, an sku) has no
     * translation and no parameters and comes back exactly as it went in.
     */
    public function titleText(): string
    {
        return __($this->title, $this->title_params ?? []);
    }

    public function detailText(): ?string
    {
        return $this->detail === null ? null : __($this->detail, $this->detail_params ?? []);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('ignored_at');
    }
}
