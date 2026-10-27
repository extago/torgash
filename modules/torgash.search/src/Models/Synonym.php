<?php

namespace Torgash\Search\Models;

use Illuminate\Database\Eloquent\Model;

/** What customers type → what to look for. See the rules migration. */
class Synonym extends Model
{
    protected $table = 'm_search_synonyms';

    protected $fillable = ['phrases', 'target', 'mode', 'enabled'];

    protected $casts = ['phrases' => 'array', 'enabled' => 'boolean'];

    protected $attributes = ['phrases' => '[]', 'mode' => 'also', 'enabled' => true];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Search synonym';
    }
}
