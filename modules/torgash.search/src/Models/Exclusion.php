<?php

namespace Torgash\Search\Models;

use Illuminate\Database\Eloquent\Model;

/** "A query holding one of these words must not show that." See the rules migration. */
class Exclusion extends Model
{
    protected $table = 'm_search_exclusions';

    protected $fillable = ['when_words', 'exclude_words', 'exclude_category_id', 'note', 'enabled'];

    protected $casts = ['when_words' => 'array', 'exclude_words' => 'array', 'exclude_category_id' => 'integer', 'enabled' => 'boolean'];

    protected $attributes = ['when_words' => '[]', 'exclude_words' => '[]', 'enabled' => true];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Search exclusion';
    }
}
