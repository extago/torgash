<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A code sent to prove an address (App\Core\Verification\Verifier). Never
 * holds the code itself — only its hash.
 */
class VerificationCode extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'browser_hash'];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
            'sends' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->verified_at === null && $this->expires_at->isFuture();
    }

    /** How the guard's audit names this row. */
    public static function auditLabel(): string
    {
        return 'Verification code';
    }
}
