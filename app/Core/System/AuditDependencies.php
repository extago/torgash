<?php

namespace App\Core\System;

use App\Core\Settings\CoreSettings;
use App\Core\Settings\SettingsManager;
use App\Core\Tasks\TrackedJob;
use Illuminate\Support\Facades\Process;

/**
 * `composer audit` — are any of the engine's libraries known to be vulnerable
 * (PLAN §45) — so a shop learns it from its own admin, not only from whoever
 * built it.
 *
 * A background task: it asks the advisories database over the network, which
 * may take a while or not answer at all. The finding is kept as a core setting
 * (`dependency_audit`: when, what was found, or why it could not ask), not in
 * the cache, which "Clear the cache" would empty.
 */
class AuditDependencies extends TrackedJob
{
    public function label(): string
    {
        return 'Check the libraries for known vulnerabilities';
    }

    protected function run(): void
    {
        $composer = base_path('tools/composer.phar');

        if (! is_file($composer)) {
            $this->keep(['error' => 'composer.phar is not in tools/ on this server.']);
            $this->result([], 'composer.phar is not in tools/ on this server.');

            return;
        }

        $this->message('Asking the advisories database');

        $process = Process::path(base_path())->timeout(180)->run([
            ...\App\Core\Support\Php::command(), $composer, 'audit', '--format=json', '--no-interaction', '--locked', '--abandoned=ignore',
        ]);

        $report = json_decode($process->output(), true);

        if (! is_array($report)) {
            $error = trim($process->errorOutput()) ?: 'composer audit gave no answer.';
            $this->keep(['error' => mb_substr($error, 0, 500)]);
            $this->result([], 'The check could not be made.');

            return;
        }

        $advisories = [];
        foreach ((array) ($report['advisories'] ?? []) as $package => $list) {
            foreach ((array) $list as $advisory) {
                $advisories[] = [
                    'package' => (string) $package,
                    'title' => (string) ($advisory['title'] ?? ''),
                    'cve' => (string) ($advisory['cve'] ?? ''),
                    'link' => (string) ($advisory['link'] ?? ''),
                    'affected' => (string) ($advisory['affectedVersions'] ?? ''),
                ];
            }
        }

        $this->keep(['advisories' => $advisories]);
        $this->result(['found' => count($advisories)], ':count known vulnerabilities', ['count' => count($advisories)]);
    }

    /** @param  array<string, mixed>  $finding */
    protected function keep(array $finding): void
    {
        app(SettingsManager::class)->set(CoreSettings::ID, 'dependency_audit', $finding + ['checked_at' => now()->toIso8601String()]);
    }
}
