<?php

namespace Torgash\NovaPoshta\Sync;

use DateInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use Torgash\NovaPoshta\Api\Client;
use Torgash\NovaPoshta\Jobs\RefreshTownBranches;
use Torgash\NovaPoshta\Models\Sync;
use Torgash\NovaPoshta\Models\Warehouse;
use Torgash\NovaPoshta\WarehouseType;

/**
 * The branches of ONE town, and how they stay current.
 *
 * A shop needs the branches of the towns its customers type — a handful, over
 * and over — so the first checkout that names an unknown town pays for one
 * request and everybody after it reads the table, until the TTL (a module
 * setting, a day by default) says otherwise. That is the lazy-by-city model
 * Nova Poshta's own plug-ins use, and it is what makes a fresh install
 * work with no setup at all. SyncWarehouses fills the rest in bulk for shops
 * that would rather have the whole country in their database.
 *
 * Once a town IS in the table, going stale no longer costs a shopper anything:
 * the request serves what it has and queues the re-fetch (see fill()).
 *
 * A failed refresh keeps the stale rows rather than emptying the picker: a day
 * old branches are a far better checkout than none.
 */
class WarehouseSync
{
    public function __construct(protected Client $client) {}

    /**
     * The same sync, but throttled to the pace one API key survives — for bulk
     * work, which is the only thing that makes enough calls to be refused.
     */
    public function paced(): static
    {
        return new static($this->client->paced());
    }

    /**
     * @return Collection<int, Warehouse> ordered the way a human scans them:
     *     by branch number, numerically
     */
    public function for(string $settlementRef): Collection
    {
        $this->ready($settlementRef);

        return Warehouse::where('settlement_ref', $settlementRef)
            ->get()
            ->sortBy(fn (Warehouse $warehouse) => (int) $warehouse->number)
            ->values();
    }

    /**
     * The town's branches in the table, fetched or queued as for() does —
     * without loading them: a quote reads the table with its own query, and
     * Kyiv's eight thousand points made into models cost a fifth of a second
     * on every product page and every keystroke at checkout (measured).
     */
    public function ready(string $settlementRef): void
    {
        if ($this->stale($settlementRef)) {
            $this->fill($settlementRef);
        }
    }

    /** How long to leave the carrier alone after it refuses a request. */
    public const COOLDOWN = 'PT2M';

    /**
     * How long a queued refresh is given to happen before the next request
     * queues another. Long enough that a big city's sixteen paced pages finish;
     * short enough that a shop with no worker running is not stuck with a
     * "someone is on it" marker for a whole day.
     */
    public const QUEUE_WINDOW = 'PT15M';

    /**
     * A town whose branches have gone stale.
     *
     * Refreshing it inside the request that noticed is only right when there is
     * nothing to show: otherwise the shopper who happens to be first that day
     * pays for the whole town (Kyiv: sixteen pages, half a minute) to learn
     * what the next shopper gets for free. So a town we already have is served
     * from the table and re-fetched in the background — at most a day old,
     * which is the freshness the TTL promises either way.
     */
    protected function fill(string $settlementRef): void
    {
        if (! Warehouse::where('settlement_ref', $settlementRef)->exists()) {
            $this->refresh($settlementRef);

            return;
        }

        if (Sync::stale('warehouses:'.$settlementRef.':queued', new DateInterval(self::QUEUE_WINDOW))) {
            Sync::mark('warehouses:'.$settlementRef.':queued');
            RefreshTownBranches::dispatch($settlementRef);
        }
    }

    /**
     * "This customer has named that town" — get its branches ready, in the
     * background, before anybody waits for them.
     *
     * Never synchronous, unlike `for()`: nothing is being rendered from this
     * yet, and the whole point is that the customer does not wait. With no
     * queue worker running nothing happens at all, and the delivery step still
     * fetches the town itself, exactly as it did before this existed.
     */
    public function warm(string $settlementRef): void
    {
        if ($settlementRef === '' || ! $this->stale($settlementRef)) {
            return;
        }

        if (Sync::stale('warehouses:'.$settlementRef.':queued', new DateInterval(self::QUEUE_WINDOW))) {
            Sync::mark('warehouses:'.$settlementRef.':queued');
            RefreshTownBranches::dispatch($settlementRef);
        }
    }

    /**
     * A town we have nothing for and could not ask about — the carrier refused
     * the last attempt and is inside its cooldown.
     *
     * Worth telling apart from "this town has no branches", because the two
     * deserve opposite answers. Neither is OFFERED — a pickup method with
     * nothing to pick is a dead end at the last step either way — but a town
     * with none is answered with the nearest towns that have some, and this
     * one must not be: the branches are simply not in our table, this town
     * very likely has plenty, and sending that customer to the next town is
     * directions built on something we do not know. It is told to try again
     * instead, which is the truth and is over in two minutes.
     */
    public function pending(string $settlementRef): bool
    {
        if (Warehouse::where('settlement_ref', $settlementRef)->exists()) {
            return false;
        }

        return ! Sync::stale('warehouses:'.$settlementRef.':failed', new DateInterval(self::COOLDOWN));
    }

    /**
     * Ours: locker, cargo, or branch.
     *
     * `CategoryOfWarehouse` is the carrier's own word for it and the reliable
     * way to spot a parcel locker (Kyiv page one: Branch 423, Postomat 59,
     * DropOff 16, Fulfillment 2). The type UUID still decides cargo, which the
     * category does not distinguish.
     *
     * @param  array<string, mixed>  $row
     */
    protected function category(array $row, string $type): string
    {
        if (($row['CategoryOfWarehouse'] ?? '') === 'Postomat') {
            return 'locker';
        }

        return WarehouseType::category($type);
    }

    protected function stale(string $settlementRef): bool
    {
        $hours = max(1, (int) setting('torgash.novaposhta', 'warehouse_ttl_hours', 2));

        if (! Sync::stale('warehouses:'.$settlementRef, new DateInterval('PT'.$hours.'H'))) {
            return false;
        }

        // The carrier rate-limits per key, and a quote runs on every keystroke
        // in the town field: retrying a failed fetch on each of them is how a
        // brief "too many requests" becomes a permanent one. After a refusal
        // the town is left alone for a couple of minutes and checkout goes on
        // with whatever is cached.
        return Sync::stale('warehouses:'.$settlementRef.':failed', new DateInterval(self::COOLDOWN));
    }

    /** One town, all its branches, one marker. */
    public function refresh(string $settlementRef): int
    {
        $rows = [];

        try {
            foreach ($this->client->pages('Address', 'getWarehouses', ['SettlementRef' => $settlementRef]) as $row) {
                if (empty($row['Ref'])) {
                    continue;
                }

                // RECEIVING, not sending: this picker chooses where the parcel
                // is handed to the customer. They are usually the same numbers
                // and occasionally are not.
                $limits = (array) ($row['ReceivingLimitationsOnDimensions'] ?? $row['SendingLimitationsOnDimensions'] ?? []);
                $type = (string) ($row['TypeOfWarehouse'] ?? '');

                $rows[] = [
                    'ref' => $row['Ref'],
                    'settlement_ref' => $settlementRef,
                    'number' => (string) ($row['Number'] ?? ''),
                    'type_ref' => $type,
                    'category' => $this->category($row, $type),
                    // The carrier's own word for it, kept verbatim: `offered()`
                    // uses it to leave out DropOff and Fulfillment, which the
                    // derived category cannot tell from a plain branch.
                    'np_category' => ((string) ($row['CategoryOfWarehouse'] ?? '')) ?: null,
                    'name_uk' => (string) ($row['Description'] ?? ''),
                    'name_ru' => (string) ($row['DescriptionRu'] ?? ''),
                    'short_uk' => (string) ($row['ShortAddress'] ?? ''),
                    'short_ru' => (string) ($row['ShortAddressRu'] ?? ''),
                    'latitude' => is_numeric($row['Latitude'] ?? null) ? (float) $row['Latitude'] : null,
                    'longitude' => is_numeric($row['Longitude'] ?? null) ? (float) $row['Longitude'] : null,
                    // Two weight limits, because a branch may carry the number
                    // in either: place = 0 with total = 200 is a real Kyiv row,
                    // and reading only the first would offer it a 500 kg parcel.
                    'max_weight_kg' => (int) ($row['PlaceMaxWeightAllowed'] ?? 0),
                    'total_max_weight_kg' => (int) ($row['TotalMaxWeightAllowed'] ?? 0),
                    'max_width_cm' => (int) ($limits['Width'] ?? 0),
                    'max_height_cm' => (int) ($limits['Height'] ?? 0),
                    'max_length_cm' => (int) ($limits['Length'] ?? 0),
                    // Branches close, and the carrier says so rather than
                    // quietly dropping the row.
                    'status' => (string) ($row['WarehouseStatus'] ?? 'Working'),
                    'deny_to_select' => (string) ($row['DenyToSelect'] ?? '0') === '1',
                    'phone' => ($row['Phone'] ?? '') ?: null,
                    'postal_code' => ($row['PostalCodeUA'] ?? '') ?: null,
                    'schedule' => json_encode([
                        'reception' => (array) ($row['Reception'] ?? []),
                        'delivery' => (array) ($row['Delivery'] ?? []),
                    ], JSON_UNESCAPED_UNICODE),
                    'synced_at' => now(),
                ];
            }
        } catch (Throwable $e) {
            // The carrier is down, rate-limiting, or the key was revoked.
            // Keeping whatever is cached — and NOT marking it fresh — is a
            // checkout that still works; an empty picker is one that does not.
            // The failure is marked instead, so the next quote does not ask
            // again a second later.
            report($e);
            Sync::mark('warehouses:'.$settlementRef.':failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return Warehouse::where('settlement_ref', $settlementRef)->count();
        }

        DB::transaction(function () use ($settlementRef, $rows) {
            if ($rows !== []) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    Warehouse::upsert($chunk, ['ref'], array_keys($chunk[0]));
                }
            }

            // Branches close. A row this pass did not see is gone from the
            // carrier's list and must leave the picker with it.
            Warehouse::where('settlement_ref', $settlementRef)
                ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', now()->subMinutes(5)))
                ->delete();
        });

        Sync::mark('warehouses:'.$settlementRef, ['count' => count($rows)]);
        // The town answered, so it is no longer in the penalty box — otherwise
        // a caller that retries after a refusal cannot tell the retry worked.
        Sync::where('key', 'warehouses:'.$settlementRef.':failed')->delete();

        return count($rows);
    }
}
