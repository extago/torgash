<?php

namespace Torgash\Exim\Models;

use App\Core\Support\HasTranslations;
use App\Models\BackgroundTask;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EximProfile extends Model
{
    use HasTranslations;

    /**
     * The schedules the form offers in a list. The field itself takes any
     * cron expression — the list only writes one for somebody who does not
     * speak cron, and shows its name back when a typed one matches.
     */
    public const SCHEDULES = [
        '*/15 * * * *' => 'Every 15 minutes',
        '*/30 * * * *' => 'Every 30 minutes',
        '0 * * * *' => 'Every hour',
        '0 */3 * * *' => 'Every 3 hours',
        '0 */6 * * *' => 'Every 6 hours',
        '0 */12 * * *' => 'Every 12 hours',
        '0 3 * * *' => 'Every day at 03:00',
        '0 3 * * 1' => 'Every Monday at 03:00',
    ];

    /** How long a file rebuilt for a change rests before the next change may rebuild it, minutes. */
    public const REBUILD_EVERY = [5, 10, 15, 30, 60, 120, 180, 360, 720];

    protected $table = 'm_exim_profiles';

    protected $guarded = [];

    protected $hidden = ['basic_auth_pass'];

    protected static function booted(): void
    {
        // A profile's files go with it — the feed address has stopped
        // answering, and nothing else knows the folder is there.
        static::deleted(fn (self $profile) => Storage::disk('local')->deleteDirectory('exim/'.$profile->id));
    }

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Export profile';
    }

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'fields' => 'array',
            'locales' => 'array',
            'filters' => 'array',
            'enabled' => 'bool',
            'last_run_at' => 'datetime',
            'rebuild_on_change' => 'bool',
            'rebuild_every' => 'int',
            'last_rows' => 'int',
            'last_size' => 'int',
            'last_duration_ms' => 'int',
        ];
    }

    public function setBasicAuthPassAttribute(?string $value): void
    {
        $this->attributes['basic_auth_pass'] = $value ? Hash::make($value) : null;
    }

    public function checkBasicAuthPass(string $value): bool
    {
        return $this->basic_auth_pass && Hash::check($value, $this->basic_auth_pass);
    }

    public function requiresBasicAuth(): bool
    {
        return filled($this->basic_auth_user) && filled($this->basic_auth_pass);
    }

    /**
     * Whether the feed address alone may hand this out. Only what a module
     * declared as the shop's own public data (`exim.public_entities` — the
     * catalogue, banners) goes by address; orders, enquiries, reviews and
     * anything a module did not declare carry people's names and contacts,
     * and an address gets forwarded, logged and pasted into chats. Those need
     * the feed's login — a module that forgets to declare loses convenience,
     * never its customers' data.
     */
    public function isPublicData(): bool
    {
        return in_array($this->entity, (array) hook_filter('exim.public_entities', []), true);
    }

    public function feedNeedsLogin(): bool
    {
        return ! $this->isPublicData() && ! $this->requiresBasicAuth();
    }

    public function ensurePublicToken(): string
    {
        if (! $this->public_token) {
            $this->public_token = Str::random(40);
            $this->save();
        }

        return $this->public_token;
    }

    /** The schedule as a cron expression, or null for none. */
    public function cronExpression(): ?string
    {
        return self::cron($this->schedule);
    }

    /**
     * A typed schedule as cron reads it: blank is none, and the two words
     * this field took before it took cron ("hourly", "daily") are their
     * obvious expressions — a profile saved then keeps its times.
     */
    public static function cron(?string $schedule): ?string
    {
        $schedule = preg_replace('/\s+/', ' ', trim((string) $schedule));

        return match ($schedule) {
            '' => null,
            'hourly' => '0 * * * *',
            'daily' => '0 0 * * *',
            default => $schedule,
        };
    }

    /** Whether cron can read a typed schedule; blank (no schedule) can always be. */
    public static function readable(?string $schedule): bool
    {
        $expression = self::cron($schedule);

        return $expression === null || CronExpression::isValidExpression($expression);
    }

    /**
     * The next `$count` times a schedule names, on the shop's clock.
     *
     * @return list<Carbon>
     */
    public static function nextRuns(string $expression, int $count = 3, ?CarbonInterface $from = null): array
    {
        return array_map(
            fn (\DateTimeInterface $at) => Carbon::instance($at),
            (new CronExpression($expression))->getMultipleRunDates($count, $from ?? now()),
        );
    }

    /**
     * Whether the schedule has named a time since the last build started.
     * Strictly after: a build started in the very minute it was due — kept
     * to the second, so "10:15:00" — has already served that minute.
     * CronExpression is a dependency of Laravel's own scheduler, no new
     * package.
     */
    public function isDueForSchedule(): bool
    {
        $expression = $this->cronExpression();

        if ($expression === null) {
            return false;
        }

        if (! $this->last_run_at) {
            return true;
        }

        try {
            return (new CronExpression($expression))->getPreviousRunDate(now(), 0, true)->getTimestamp()
                > $this->last_run_at->getTimestamp();
        } catch (\Exception) {
            return false;
        }
    }

    /** Whether the file is built again without being asked: by a schedule, by a change. */
    public function rebuildsItself(): bool
    {
        return $this->cronExpression() !== null || $this->rebuild_on_change;
    }

    /** When a change may next rebuild the file — `rebuild_every` after the last build. */
    public function restsUntil(): ?Carbon
    {
        return $this->last_run_at?->copy()->addMinutes(max(1, (int) ($this->rebuild_every ?: 30)));
    }

    /** Whether the file has rested long enough for a change to rebuild it. */
    public function hasRested(): bool
    {
        return ! $this->last_run_at || $this->restsUntil()->lte(now());
    }

    /** The background task building the file now or last, if it is still on record. */
    public function buildTask(): ?BackgroundTask
    {
        return $this->build_task_id ? BackgroundTask::find($this->build_task_id) : null;
    }

    /** Whether a build is queued or running — a worker that died does not count. */
    public function isBuilding(): bool
    {
        $task = $this->buildTask();

        return $task !== null && $task->isActive() && ! $task->isStale();
    }

    /** The size of the last file, written for people. */
    public function fileSize(): ?string
    {
        if ($this->last_size === null) {
            return null;
        }

        return match (true) {
            $this->last_size >= 1048576 => round($this->last_size / 1048576, 1).' '.__('MB'),
            $this->last_size >= 1024 => (int) round($this->last_size / 1024).' '.__('KB'),
            default => $this->last_size.' '.__('B'),
        };
    }
}
