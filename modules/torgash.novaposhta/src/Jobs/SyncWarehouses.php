<?php

namespace Torgash\NovaPoshta\Jobs;

use App\Core\Tasks\TrackedJob;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Torgash\NovaPoshta\Models\Sync;
use Torgash\NovaPoshta\Sync\WarehouseSync;

/**
 * Pulls the branches of every town the shop can actually deliver to, so the
 * picker never waits for the carrier.
 *
 * This is what Nova Poshta's own plug-ins do — their "warehouses"
 * handbook walks every city with a carrier ref, one request each, refreshing
 * whatever is older than a day, driven by a button and by cron.
 * The lazy per-town fetch this module
 * already has is the fallback that makes a fresh install work with no setup;
 * this is for shops that would rather have the whole thing in their database.
 *
 * Only mapped towns that have branches at all: fetching the other 15 000 would
 * be 15 000 requests for towns nobody in this shop's registry can order from.
 * Even so it is ~10 600 requests against a key the carrier rate-limits, and the
 * pace it tolerates (Client::PACE, measured) makes a full pass of Ukraine about
 * four hours. So one run does what it can inside a budget and stops, and the
 * next one continues: every town carries its own freshness marker, and the list
 * is ordered biggest-town-first, so the places customers actually type are
 * covered by the first run and villages fill in over the following nights.
 * So it is something you re-run — today from the button, from a scheduler once
 * the project has one — rather than a button you sit and wait on.
 */
class SyncWarehouses extends TrackedJob
{
    /**
     * How long one run may take. Below the job's own hour-long timeout, with
     * room for the last town to finish — being killed mid-run would lose the
     * result line, not the data.
     */
    public const BUDGET = 'PT45M';

    /**
     * A refusal is a rolling budget, not a ban: measured, it clears within five
     * seconds. So a refused town is retried a couple of times before the run
     * gives up on the carrier entirely.
     */
    public const RETRIES = 3;

    public const BACKOFF = 8;

    public function label(): string
    {
        return 'Sync Nova Poshta branches';
    }

    public function module(): ?string
    {
        return 'torgash.novaposhta';
    }

    protected function run(): void
    {
        $sync = $this->sync();
        $towns = $this->towns();
        $deadline = now()->add(new DateInterval(self::BUDGET));

        $this->total(count($towns));
        $this->message('Asking Nova Poshta for branches, town by town');

        $done = 0;
        $branches = 0;
        $skipped = 0;

        foreach ($towns as $ref) {
            // Towns refreshed recently by a checkout (or by a previous run of
            // this job) are left alone: the point is to fill the gaps, not to
            // spend the request budget on what is already fresh.
            if (! Sync::stale('warehouses:'.$ref, new DateInterval('PT'.$this->ttlHours().'H'))) {
                $skipped++;
                $this->progress(++$done);

                continue;
            }

            if (now()->greaterThan($deadline)) {
                $this->finish($done, $branches, $skipped, 'out_of_time', count($towns));

                return;
            }

            $count = $this->fetch($sync, $ref);

            // The carrier kept refusing through every retry: it is rate-limited
            // for longer than a backoff, down, or the key is gone. Carrying on
            // would just collect ten thousand more refusals.
            if ($count === null) {
                $this->finish($done, $branches, $skipped, 'refused', count($towns));

                return;
            }

            $branches += $count;
            $this->progress(++$done);
        }

        $this->finish($done, $branches, $skipped, null, count($towns));
    }

    /**
     * One town, retried through a refusal. Null when the carrier would not
     * answer at all.
     */
    protected function fetch(WarehouseSync $sync, string $ref): ?int
    {
        for ($try = 1; $try <= self::RETRIES; $try++) {
            $count = $sync->refresh($ref);

            // refresh() swallows the failure and marks the town instead, so the
            // absence of a fresh marker is how a success is recognised. (It
            // clears the marker when a retry gets through.)
            if (Sync::stale('warehouses:'.$ref.':failed', new DateInterval(WarehouseSync::COOLDOWN))) {
                return $count;
            }

            if ($try < self::RETRIES) {
                sleep($this->pauseAfterRefusal());
            }
        }

        return null;
    }

    /**
     * Paced, because the carrier refuses a key that asks faster than it
     * tolerates and 10 000 towns is far past the point where that matters.
     * Overridable so a test can run without waiting out a real pace.
     */
    protected function sync(): WarehouseSync
    {
        return app(WarehouseSync::class)->paced();
    }

    protected function pauseAfterRefusal(): int
    {
        return self::BACKOFF;
    }

    /** The one place a run says how far it got and whether to run it again. */
    protected function finish(int $done, int $branches, int $skipped, ?string $stopped, int $total): void
    {
        $result = [
            'towns' => $done,
            'branches' => $branches,
            'skipped' => $skipped,
            'stopped_early' => $stopped !== null,
            'stopped_because' => $stopped,
        ];

        [$message, $params] = match ($stopped) {
            'out_of_time' => [
                ':branches branches in :towns towns; :left to go — run it again to continue.',
                ['branches' => $branches, 'towns' => $done, 'left' => $total - $done],
            ],
            'refused' => [
                'Stopped after :towns towns — the carrier stopped answering. Run it again later to continue.',
                ['towns' => $done],
            ],
            default => [
                ':branches branches in :towns towns (:skipped were already fresh)',
                ['branches' => $branches, 'towns' => $done, 'skipped' => $skipped],
            ],
        };

        $this->result($result, $message, $params);
    }

    protected function ttlHours(): int
    {
        return max(1, (int) setting('torgash.novaposhta', 'warehouse_ttl_hours', 2));
    }

    /**
     * Towns worth the request: mapped to this shop's registry and known to have
     * branches, biggest first — those are the ones customers type.
     *
     * @return list<string>
     */
    protected function towns(): array
    {
        return DB::table('m_np_place_map as m')
            ->join('m_np_settlements as s', 's.ref', '=', 'm.settlement_ref')
            ->join('m_locations_places as p', 'p.id', '=', 'm.place_id')
            ->where('s.has_warehouse', true)
            ->groupBy('m.settlement_ref')
            ->orderByRaw('max(p.population) desc')
            ->pluck('m.settlement_ref')
            ->all();
    }
}
