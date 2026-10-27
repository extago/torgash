<?php

namespace Torgash\NovaPoshta\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One branch or parcel locker, cached per settlement on a TTL.
 */
class Warehouse extends Model
{
    protected $table = 'm_np_warehouses';

    protected $primaryKey = 'ref';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Nova Poshta branch';
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'max_weight_kg' => 'int',
            'max_width_cm' => 'int',
            'max_height_cm' => 'int',
            'max_length_cm' => 'int',
            'total_max_weight_kg' => 'int',
            'deny_to_select' => 'bool',
            'schedule' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Today's opening hours, as the carrier last reported them — the one thing
     * a customer looks at before choosing between two branches on the same
     * street. Null when the carrier gave none.
     *
     * Deliberately "as of the last sync": schedules change, and a shop that
     * refreshes daily shows day-old hours. That is the same freshness the
     * carrier's own plug-ins offer, and better than showing nothing.
     */
    public function hoursToday(): ?string
    {
        return $this->hoursOn(now()->format('l'));
    }

    /**
     * The reception hours the carrier gives for one weekday, or null when it
     * gives none.
     *
     * "Closed" arrives in three spellings — a missing day, `00:00-00:00`, and a
     * bare `-` — and none of them is worth printing beside a branch. (The third
     * is what put "today -" on screen: a dash reads as a rendering failure, not
     * as information.)
     */
    protected function hoursOn(string $weekday): ?string
    {
        $reception = ($this->schedule ?? [])['reception'] ?? [];
        $hours = trim((string) ($reception[$weekday] ?? ''));

        return match ($hours) {
            '', '-', '—', '00:00-00:00' => null,
            default => $hours,
        };
    }

    /**
     * The week's reception hours, with consecutive days that share a time
     * folded into a range: `["Пн–Пт" => "08:00-21:00", "Сб–Нд" => "09:00-19:00"]`.
     *
     * Seven identical-looking lines is not a schedule anybody reads, and it is
     * how the carrier's own module (and every Ukrainian checkout) shows this.
     * Day names come from Carbon in the storefront's locale.
     *
     * @return array<string, string>
     */
    public function openingHours(): array
    {
        $reception = ($this->schedule ?? [])['reception'] ?? [];

        if ($reception === []) {
            return [];
        }

        $monday = Carbon::now()->startOfWeek();
        $folded = [];
        $run = [];
        $time = null;

        foreach (range(0, 6) as $offset) {
            $day = $monday->copy()->addDays($offset);
            // Here a closed day IS worth a line — a week missing Sunday reads
            // as a bug, where "Sun —" reads as closed.
            $hours = $this->hoursOn($day->format('l')) ?? '—';

            if ($hours !== $time) {
                if ($run !== []) {
                    $folded[$this->label($run)] = $time;
                }

                $run = [];
                $time = $hours;
            }

            $run[] = $day->isoFormat('dd');
        }

        if ($run !== []) {
            $folded[$this->label($run)] = $time;
        }

        return $folded;
    }

    /** @param  list<string>  $days */
    protected function label(array $days): string
    {
        return count($days) === 1 ? $days[0] : $days[0].'–'.end($days);
    }

    /**
     * The kind of pickup point, for a label in a mixed list — a "to a branch"
     * method that also offers lockers (they are one row of pickup points, not
     * a separate delivery). From the carrier's own category, falling back to
     * the one this module derives from the type UUID.
     */
    public function kindLabel(): string
    {
        if ($this->np_category === 'Postomat' || $this->category === 'locker') {
            return __('Parcel locker');
        }

        return $this->category === 'cargo' ? __('Cargo department') : __('Branch');
    }

    /**
     * What this branch will take, in kg — null when it names no limit.
     *
     * The SMALLER of the two limits when both are set (25 rows of 41 000): one
     * caps a single item and the other the whole shipment, so the binding one
     * is the lower. The carrier's own name text sometimes quotes the other one,
     * which is its business, not a number to copy.
     */
    public function weightLimitKg(): ?int
    {
        $limits = array_filter([(int) $this->max_weight_kg, (int) $this->total_max_weight_kg]);

        return $limits === [] ? null : min($limits);
    }

    /**
     * What one method may offer in one town: its variant's categories, the
     * warehouse types the admin ticked, and only branches that can physically
     * take this parcel — finding that out at the counter is the worst place to.
     *
     * Shared by the quote and by the picker's search so the two can never
     * disagree about what is on offer.
     *
     * @param  list<string>  $categories
     * @param  list<string>  $types  empty = every type
     */
    public function scopeOffered($query, string $settlementRef, array $categories, array $types = [], int $weightGrams = 0)
    {
        $kg = (int) ceil($weightGrams / 1000);

        return $query
            ->where('settlement_ref', $settlementRef)
            ->whereIn('category', $categories)
            // DropOff and Fulfilment points accept parcels but a customer
            // cannot collect from them. The carrier's own category says which
            // is which where our derived branch/cargo/locker cannot; a null is
            // a row not yet re-synced and stays in until it is.
            ->whereRaw("coalesce(np_category, '') not in ('DropOff', 'Fulfillment')")
            // Branches close and the carrier sometimes says "not this one" on
            // its own; both are fields, not something to discover when a
            // customer's parcel has nowhere to go.
            ->where('status', 'Working')
            ->where('deny_to_select', false)
            ->when($types !== [], fn ($builder) => $builder->whereIn('type_ref', $types))
            // Either weight column may hold the limit — a branch with place = 0
            // and total = 200 is a real row — so both are checked, and a zero
            // in one of them means "no limit here", not "no limit at all".
            ->when($weightGrams > 0, fn ($builder) => $builder
                ->where(fn ($sub) => $sub->where('max_weight_kg', 0)->orWhere('max_weight_kg', '>=', $kg))
                ->where(fn ($sub) => $sub->where('total_max_weight_kg', 0)->orWhere('total_max_weight_kg', '>=', $kg)));
    }

    /**
     * By branch number, numerically — "№ 2" before "№ 10", which is how a human
     * reads a list of them. The number is a string column because the carrier
     * sends things like "1а", so the digits are pulled out for the sort.
     */
    public function scopeInBranchOrder($query)
    {
        return $query->orderByRaw("coalesce(nullif(regexp_replace(number, '[^0-9]', '', 'g'), ''), '0')::int");
    }
}
