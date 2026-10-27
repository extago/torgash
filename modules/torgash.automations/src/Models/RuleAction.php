<?php

namespace Torgash\Automations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleAction extends Model
{
    public $timestamps = false;

    protected $table = 'm_automations_actions';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Automation action';
    }

    protected function casts(): array
    {
        return ['config' => 'array', 'position' => 'int'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }
}
