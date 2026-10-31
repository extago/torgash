<?php

namespace Torgash\Automations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to act, kept whether it worked or not.
 *
 * `rule_name` is copied rather than joined: the log has to stay readable after
 * the rule is renamed or deleted, exactly like an order keeping the name of the
 * payment method it was sold under.
 */
class RuleRun extends Model
{
    const UPDATED_AT = null;

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Conditions no longer held when the delay ran out. */
    public const SKIPPED = 'skipped';

    /** The receiver was not reached; the same action is queued again (RunRule). */
    public const RETRY = 'retry';

    protected $table = 'm_automations_runs';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Automation run';
    }

    protected function casts(): array
    {
        return ['detail' => 'array'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    public function badge(): string
    {
        return match ($this->status) {
            self::SENT => 'badge-success',
            self::FAILED => 'badge-error',
            self::RETRY => 'badge-warning',
            default => 'badge-ghost',
        };
    }
}
