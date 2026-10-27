<?php

namespace App\Core\Currency;

use App\Core\Tasks\TrackedJob;
use RuntimeException;

/**
 * Ask the shop's chosen source for today's rates.
 *
 * A tracked job rather than a plain one because it is the kind of work whose
 * failure has to be visible: a feed that quietly stopped answering leaves a
 * shop selling at last month's rate, and the only place that would otherwise
 * show up is a customer paying too little. System → Background tasks lists
 * every run, its counts and its errors.
 *
 * Scheduled by CoreServiceProvider at the frequency the shop picked, and
 * startIfIdle() there so an hourly schedule cannot stack runs on a slow feed.
 */
class FetchRates extends TrackedJob
{
    public function label(): string
    {
        return 'Update exchange rates';
    }

    protected function run(): void
    {
        $sources = app(RateSources::class)->chain();

        if (! $sources) {
            $this->result([], 'No rate source is configured.');

            return;
        }

        $this->message('Asking :source', ['source' => __($sources[0]::label())]);

        $report = app(RateUpdater::class)->updateFrom($sources);

        // Who answered and who broke, by name: "the NBU stopped answering and
        // the ECB covered for it" is the sentence the owner needs the day a
        // bank changes its API — and a key, so it reads in their language.
        $name = fn (string $key) => __((string) (app(RateSources::class)->all()[$key] ?? null)::label());

        $this->result($report, ':updated updated, :skipped left by hand:missing:refused:answered:failed', [
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
            'missing' => $report['missing'] ? '; not offered by the source: '.implode(', ', $report['missing']) : '',
            'refused' => $report['refused'] ? '; refused as too large a change: '.implode(', ', $report['refused']) : '',
            'answered' => $report['answered'] ? '; answered by '.implode(', ', array_map($name, array_keys($report['answered']))) : '',
            'failed' => $report['failed'] ? '; did not answer: '.implode(', ', array_map($name, array_keys($report['failed']))) : '',
        ]);

        // Nothing moved and something broke: that is a failed run, and has to
        // be red in the list rather than "0 updated" in a line nobody reads.
        if ($report['updated'] === 0 && ($report['failed'] || in_array($report['base'], $report['missing'], true))) {
            throw new RuntimeException($report['failed']
                ? 'No rate source answered: '.implode('; ', array_map(fn ($key, $error) => $key.': '.$error, array_keys($report['failed']), $report['failed']))
                : 'No source quotes '.$report['base'].", so nothing could be crossed into the shop's currency.");
        }
    }
}
