<?php

namespace Torgash\Automations\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram message that went out WITH buttons: what it was about and what
 * each button does, as they were then — a rule edited since does not change
 * what an old message's button means. Pressed once.
 */
class Message extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'm_automations_telegram_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['buttons' => 'array', 'pressed' => 'int', 'pressed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
