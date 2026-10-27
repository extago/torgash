<?php

namespace Torgash\Speed;

use Illuminate\Support\Facades\DB;

/**
 * Nightly: measurements older than `keep_days` go, and the timelines are kept
 * to the newest few hundred whatever their age — a timeline is the heavy row,
 * and a shop with a slow page writes one per measured visit to it.
 */
class Prune
{
    public const MAX_TRACES = 500;

    public function run(): void
    {
        $cutoff = now()->subDays(max(1, (int) setting('torgash.speed', 'keep_days')));

        DB::table('m_speed_routes')->where('bucket_at', '<', $cutoff)->delete();
        DB::table('m_speed_modules')->where('bucket_at', '<', $cutoff)->delete();
        DB::table('m_speed_queries')->where('day', '<', $cutoff->toDateString())->delete();
        DB::table('m_speed_traces')->where('created_at', '<', $cutoff)->delete();

        $keepFrom = DB::table('m_speed_traces')->orderByDesc('id')->skip(self::MAX_TRACES)->value('id');

        if ($keepFrom !== null) {
            DB::table('m_speed_traces')->where('id', '<=', $keepFrom)->delete();
        }
    }
}
