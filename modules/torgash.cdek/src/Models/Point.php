<?php

namespace Torgash\Cdek\Models;

use Illuminate\Database\Eloquent\Model;
use Torgash\Cdek\PointType;

/**
 * One CDEK pickup point or parcel locker, cached per city on a TTL.
 */
class Point extends Model
{
    protected $table = 'm_cdek_points';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'CDEK pickup point';
    }

    protected function casts(): array
    {
        return [
            'city_code' => 'int',
            'latitude' => 'float',
            'longitude' => 'float',
            'weight_min' => 'float',
            'weight_max' => 'float',
            'dimensions' => 'array',
            'schedule' => 'array',
            'is_handout' => 'bool',
            'allowed_cod' => 'bool',
            'have_cashless' => 'bool',
            'is_dressing_room' => 'bool',
            'synced_at' => 'datetime',
        ];
    }

    public function kindLabel(): string
    {
        return PointType::label((string) $this->type);
    }

    /**
     * The week's hours with consecutive days that share a time folded into a
     * range — `["Пн–Пт" => "10:00–20:00", "Сб–Вс" => "10:00–16:00"]`.
     *
     * Read from `work_time_list` (day 1 = Monday, "10:00/20:00"), which is
     * what CDEK's own free-text `work_time` is written from; a day the list
     * leaves out is closed, and says so rather than vanishing.
     *
     * @return array<string, string>
     */
    public function openingHours(): array
    {
        $byDay = $this->hoursByDay();

        if ($byDay === []) {
            return [];
        }

        $monday = now()->startOfWeek();
        $folded = [];
        $run = [];
        $time = null;

        foreach (range(1, 7) as $day) {
            $hours = $byDay[$day] ?? '—';

            if ($hours !== $time) {
                if ($run !== []) {
                    $folded[$this->label($run)] = $time;
                }

                $run = [];
                $time = $hours;
            }

            $run[] = $monday->copy()->addDays($day - 1)->isoFormat('dd');
        }

        if ($run !== []) {
            $folded[$this->label($run)] = $time;
        }

        return $folded;
    }

    /** Today's hours, or null when the point gives none for today. */
    public function hoursToday(): ?string
    {
        return $this->hoursByDay()[now()->isoWeekday()] ?? null;
    }

    /** @return array<int, string> ISO weekday => "10:00–20:00" */
    protected function hoursByDay(): array
    {
        $byDay = [];

        foreach ((array) ($this->schedule ?? []) as $row) {
            $day = (int) ($row['day'] ?? 0);
            $time = trim((string) ($row['time'] ?? ''));

            if ($day >= 1 && $day <= 7 && $time !== '') {
                $byDay[$day] = str_replace('/', '–', $time);
            }
        }

        return $byDay;
    }

    /** @param  list<string>  $days */
    protected function label(array $days): string
    {
        return count($days) === 1 ? $days[0] : $days[0].'–'.end($days);
    }

    /** The heaviest parcel this point takes, in kg, or null when it names none. */
    public function weightLimitKg(): ?float
    {
        return $this->weight_max > 0 ? $this->weight_max : null;
    }

    /**
     * What one method may offer in one city: the point types the admin ticked,
     * only points that hand parcels OUT (some only take them in), and only
     * those that can physically take this parcel.
     *
     * Shared by the quote and the picker's search so the two can never
     * disagree about what is on offer.
     *
     * @param  list<string>  $types  empty = every type
     */
    public function scopeOffered($query, int $cityCode, array $types = [], int $weightGrams = 0, int $volumeCm3 = 0)
    {
        $kg = $weightGrams / 1000;

        return $query
            ->where('city_code', $cityCode)
            ->where('is_handout', true)
            ->when($types !== [], fn ($builder) => $builder->whereIn('type', $types))
            // A zero is "no limit stated", not "takes nothing".
            ->when($weightGrams > 0, fn ($builder) => $builder
                ->where(fn ($sub) => $sub->where('weight_max', 0)->orWhere('weight_max', '>=', $kg))
                ->where('weight_min', '<=', $kg))
            // A locker publishes its cells and nothing else; a parcel bigger
            // than its biggest cell does not fit, whatever it weighs. Compared
            // by volume because that is all a cart knows about its shape.
            ->when($volumeCm3 > 0, fn ($builder) => $builder->where(fn ($sub) => $sub
                ->whereNull('dimensions')
                ->orWhereRaw('jsonb_array_length(dimensions) = 0')
                ->orWhereRaw(
                    "exists (select 1 from jsonb_array_elements(dimensions) cell where (cell->>'width')::numeric * (cell->>'height')::numeric * (cell->>'depth')::numeric >= ?)",
                    [$volumeCm3],
                )));
    }

    /** Offices before lockers, then by the street — how a list of them is scanned. */
    public function scopeInListOrder($query)
    {
        return $query->orderByRaw("case when type = 'PVZ' then 0 else 1 end")->orderBy('address')->orderBy('code');
    }
}
