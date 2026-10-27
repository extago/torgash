<?php

namespace Torgash\Payments\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    const UPDATED_AT = null;

    protected $table = 'm_payments_transactions';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Payment transaction';
    }

    protected function casts(): array
    {
        return ['raw' => 'array', 'verified' => 'bool', 'applied' => 'bool', 'amount' => 'int'];
    }

    public function badge(): string
    {
        return match (true) {
            ! $this->verified => 'badge-error',
            $this->applied => 'badge-success',
            default => 'badge-ghost',
        };
    }
}
