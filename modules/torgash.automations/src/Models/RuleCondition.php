<?php

namespace Torgash\Automations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleCondition extends Model
{
    public $timestamps = false;

    protected $table = 'm_automations_conditions';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Automation condition';
    }

    protected function casts(): array
    {
        return ['value' => 'array', 'position' => 'int'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /**
     * The value is jsonb because different kinds need different shapes — a list
     * of statuses, an amount, a piece of text. These are the readers, so nothing
     * else has to know how a kind stores itself.
     */
    public function text(string $key = 'text'): string
    {
        return trim((string) (($this->value ?? [])[$key] ?? ''));
    }

    /** @return list<string> */
    public function list(string $key = 'list'): array
    {
        return array_values(array_filter(array_map('strval', (array) (($this->value ?? [])[$key] ?? []))));
    }

    /** Integer ids — category rows and anything else picked from a list of rows. @return list<int> */
    public function ids(string $key = 'ids'): array
    {
        return array_values(array_filter(array_map('intval', (array) (($this->value ?? [])[$key] ?? []))));
    }

    /** Minor units, like every other amount in the shop. */
    public function amount(): int
    {
        return (int) (($this->value ?? [])['amount'] ?? 0);
    }
}
