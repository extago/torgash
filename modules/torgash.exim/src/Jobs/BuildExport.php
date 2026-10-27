<?php

namespace Torgash\Exim\Jobs;

use App\Core\Tasks\TrackedJob;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\Models\EximProfile;
use Torgash\Exim\Rebuilds;

/**
 * Builds an export profile's file in the background — the one its feed
 * address hands out — for its schedule, for a change in its data, or because
 * the admin asked (Rebuilds).
 *
 * The profile's name and the reason go in the message, never the name of the
 * task: that is stored as the English source string and translated when the
 * list renders it.
 */
class BuildExport extends TrackedJob
{
    /** Why it runs, as the list says it. */
    public const REASONS = [
        Rebuilds::SCHEDULE => 'Export ":name": by its schedule',
        Rebuilds::CHANGE => 'Export ":name": its data changed',
        Rebuilds::MANUAL => 'Export ":name": asked for from the admin',
    ];

    public function __construct(public int $profileId, public string $reason = Rebuilds::MANUAL) {}

    public function label(): string
    {
        return 'Build an export file';
    }

    public function module(): ?string
    {
        return 'torgash.exim';
    }

    protected function run(): void
    {
        $profile = EximProfile::find($this->profileId);

        // Deleted while it waited: nothing to build, nothing failed.
        if (! $profile) {
            $this->result([], 'The export profile is gone.');

            return;
        }

        $name = (string) $profile->t('name');
        $this->message(self::REASONS[$this->reason] ?? self::REASONS[Rebuilds::MANUAL], ['name' => $name]);

        $runner = app(ExportRunner::class);
        $this->total($runner->count($profile));

        $runner->run($profile, fn (int $rows) => $this->progress($rows));

        $profile->refresh();
        $this->result(
            ['rows' => (int) $profile->last_rows, 'bytes' => (int) $profile->last_size],
            'Export ":name": :rows rows',
            ['name' => $name, 'rows' => (int) $profile->last_rows],
        );
    }
}
