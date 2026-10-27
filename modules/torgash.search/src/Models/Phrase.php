<?php

namespace Torgash\Search\Models;

use Illuminate\Database\Eloquent\Model;

/** A phrase suggested while typing, and the products it puts first. See the rules migration. */
class Phrase extends Model
{
    protected $table = 'm_search_phrases';

    protected $fillable = ['phrase', 'priority', 'product_ids', 'enabled'];

    protected $casts = ['product_ids' => 'array', 'priority' => 'integer', 'enabled' => 'boolean'];

    protected $attributes = ['product_ids' => '[]', 'priority' => 0, 'enabled' => true];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Pinned search phrase';
    }
}
