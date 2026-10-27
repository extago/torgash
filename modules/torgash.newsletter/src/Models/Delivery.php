<?php

namespace Torgash\Newsletter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One address of one campaign: queued, then sent or failed; opened, clicked, unsubscribed. */
class Delivery extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Unsubscribed (or removed) between the campaign's start and its turn. */
    public const SKIPPED = 'skipped';

    protected $table = 'm_newsletter_deliveries';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
