<?php

namespace Torgash\Cdek\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Torgash\Cdek\Models\Waybill;
use Torgash\Cdek\Waybills\Waybills;

/**
 * Asks CDEK where every open parcel is — the ones registered, and the ones on
 * their way. A delivered or cancelled one is not asked about again.
 *
 * Not a TrackedJob: it runs every half hour, and a row on the background
 * tasks screen every half hour is noise. Unique while it runs, so a slow pass
 * is not joined by the next.
 */
class RefreshWaybills implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Waybills asked about per pass — oldest-checked first, so none starves. */
    public const BATCH = 300;

    public int $tries = 1;

    public int $timeout = 1200;

    public function handle(Waybills $waybills): void
    {
        Waybill::query()
            ->whereIn('state', [Waybill::REGISTERING, Waybill::ACTIVE])
            ->where(fn ($query) => $query->whereNull('status_code')->orWhereNotIn('status_code', Waybill::FINAL_STATUSES))
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(20)))
            ->orderByRaw('checked_at asc nulls first')
            ->limit(self::BATCH)
            ->get()
            ->each(fn (Waybill $waybill) => $waybills->refresh($waybill));
    }
}
