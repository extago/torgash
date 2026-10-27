<?php

namespace Torgash\Automations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rule extends Model
{
    protected $table = 'm_automations_rules';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Automation rule';
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'fields' => 'array',
            'delay_minutes' => 'int',
            'position' => 'int',
        ];
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(RuleCondition::class)->orderBy('position');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(RuleAction::class)->orderBy('position');
    }

    /** Which subject this rule is about — read off its event, never stored. */
    public function subjectKey(): string
    {
        return \Torgash\Automations\Subjects\Subjects::keyFor((string) $this->event);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(RuleRun::class)->latest('id');
    }

    /**
     * Does this rule care about the fields that moved?
     *
     * An empty list means "any change", which is what a rule without a field
     * filter should mean. A rule that names fields fires when ANY of them is in
     * the diff — "the status or the manager changed" is one rule, not two.
     *
     * @param  list<string>  $changed
     */
    public function wantsFields(array $changed): bool
    {
        $wanted = array_filter((array) ($this->fields ?? []));

        return $wanted === [] || array_intersect($wanted, $changed) !== [];
    }
}
