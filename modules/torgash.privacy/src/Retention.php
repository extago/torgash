<?php

namespace Torgash\Privacy;

use App\Core\Privacy\PersonalData;

/**
 * How long each kind of personal data is kept (PLAN §49), and the nightly
 * clearing of what has outlived it. The days are the shop's (a preset fills
 * them in); an entry the shop never set keeps its owner's default; 0 keeps it.
 */
class Retention
{
    public function __construct(protected PersonalData $data) {}

    /** @return array<string, int> entry key => days */
    public function days(): array
    {
        $set = (array) setting('torgash.privacy', 'retention', []);
        $days = [];

        foreach ($this->data->retained() as $key => $entry) {
            $days[$key] = max(0, (int) ($set[$key] ?? $entry['default_days'] ?? 0));
        }

        return $days;
    }

    /** @return array<string, int> entry key => rows cleared */
    public function run(): array
    {
        $done = [];

        foreach ($this->days() as $key => $days) {
            if ($days > 0) {
                $done[$key] = $this->data->expire($key, now()->subDays($days));
            }
        }

        return $done;
    }
}
