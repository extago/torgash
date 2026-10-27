<?php

namespace App\Core\Updates;

use App\Core\Settings\CoreSettings;
use App\Core\Settings\SettingsManager;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The update delivery service, from the shop's side (PLAN §45.8):
 * extago.com/uds/torgash (`torgash.updates.server`).
 *
 * It is trusted with nothing but availability: what it offers is installed
 * only if it is signed by a key in the shop's own code (UpdatePackage), and
 * only forwards. The download is also checked against the sum the server
 * announced, so a cut-off transfer says so instead of failing on a broken zip.
 */
class UpdateServer
{
    /** Core setting holding the last check: when, what is newer, or why it could not ask. */
    public const SETTING = 'update_check';

    /** Core setting: the address the owner typed on the System page, blank for the built-in one. */
    public const ADDRESS = 'update_server';

    /**
     * Where to ask. The owner may point the shop elsewhere (a mirror, the
     * server moving house) without touching `.env`: the address is trusted
     * with availability only, and whatever it offers must still be signed by
     * a key in this code.
     */
    public function url(): string
    {
        $own = trim((string) setting(CoreSettings::ID, self::ADDRESS));

        return rtrim($own !== '' ? $own : $this->defaultUrl(), '/').'/';
    }

    public function defaultUrl(): string
    {
        return rtrim((string) config('torgash.updates.server'), '/').'/';
    }

    /** Whether there is anywhere to ask at all — a blank built-in address and no own one switch checking off. */
    public function configured(): bool
    {
        return trim((string) setting(CoreSettings::ID, self::ADDRESS)) !== '' || trim((string) config('torgash.updates.server')) !== '';
    }

    /** Points the shop at another server (blank: back to the built-in one) and forgets what the old one said. */
    public function setAddress(?string $url): void
    {
        $settings = app(SettingsManager::class);
        $settings->set(CoreSettings::ID, self::ADDRESS, trim((string) $url));
        $settings->set(CoreSettings::ID, self::SETTING, null);
    }

    /**
     * Where to ask, in order: the address (the owner's or the built-in one —
     * either may be a GitHub repository, `https://github.com/owner/repo`),
     * then the GitHub repository of `TORGASH_GITHUB_REPO` when it is another
     * place. The second is for a shop that cannot reach the first: the first
     * live shop, in Russia, gets a cut-off from extago.com and GitHub works.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        $sources = [];

        if ($this->configured()) {
            $sources[] = $this->url();
        }

        $github = trim((string) config('torgash.updates.github', ''));
        if ($github !== '' && ($repo = GitHubReleases::repository(str_contains($github, 'github.com') ? $github : 'github.com/'.$github))) {
            $address = 'https://github.com/'.$repo[0].'/'.$repo[1];

            if (! in_array(rtrim(strtolower($address), '/'), array_map(fn ($s) => rtrim(strtolower($s), '/'), $sources), true)) {
                $sources[] = $address;
            }
        }

        return $sources;
    }

    /**
     * Asks what is newer than this version and remembers the answer — from
     * the first source that answers; when none does, why the first did not.
     *
     * @return array{checked_at: string, current: string, latest?: string, updates?: list<array>, source?: string, error?: string}
     */
    public function check(): array
    {
        $current = (string) config('torgash.version');
        $found = ['current' => $current, 'error' => 'No update server is set.'];
        $firstError = null;

        foreach ($this->sources() as $source) {
            try {
                $found = ['current' => $current, ...$this->ask($source, $current), 'source' => $source];
                $firstError = null;

                break;
            } catch (Throwable $e) {
                $firstError ??= mb_substr($e->getMessage(), 0, 300);
                $found = ['current' => $current, 'error' => $firstError];
            }
        }

        $found['checked_at'] = now()->toIso8601String();
        app(SettingsManager::class)->set(CoreSettings::ID, self::SETTING, $found);

        return $found;
    }

    /** @return array{latest: string, updates: list<array>} */
    protected function ask(string $source, string $current): array
    {
        if ($repo = GitHubReleases::repository($source)) {
            return app(GitHubReleases::class)->check($repo[0], $repo[1], $current);
        }

        $answer = $this->http()->timeout(15)
            ->get($source, ['action' => 'check', 'product' => Release::PRODUCT, 'version' => $current, 'php' => PHP_VERSION])
            ->throw()->json();

        if (! is_array($answer) || ! is_array($answer['updates'] ?? null)) {
            throw new RuntimeException('The update server answered something else.');
        }

        return ['latest' => (string) ($answer['latest'] ?? $current), 'updates' => array_values($answer['updates'])];
    }

    public function last(): ?array
    {
        $last = setting(CoreSettings::ID, self::SETTING);

        return is_array($last) ? $last : null;
    }

    /** The newest offer of the last check, if it is still newer than this code. */
    public function offer(): ?array
    {
        $updates = (array) ($this->last()['updates'] ?? []);
        $newest = $updates === [] ? null : end($updates);

        return is_array($newest ?? null) && version_compare((string) $newest['version'], (string) config('torgash.version'), '>') ? $newest : null;
    }

    /** Downloads an offered package into storage and checks it against the announced sum. */
    public function download(array $offer): string
    {
        $version = (string) $offer['version'];
        if (! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
            throw new RuntimeException('The update server offered an odd version.');
        }

        $target = storage_path('app/update/downloads/torgash-'.$version.'.zip');
        @mkdir(dirname($target), 0755, true);

        $url = (string) $offer['download'];
        app(GitHubReleases::class)->prepare($this->http(), $url)->timeout(600)->withOptions(['sink' => $target])->get($url)->throw();

        // A sum nobody announced checks nothing: refused, not skipped.
        if (($offer['sha256'] ?? '') === '' || ! hash_equals((string) $offer['sha256'], (string) hash_file('sha256', $target))) {
            @unlink($target);
            throw new RuntimeException('The download does not match what the update server announced.');
        }

        return $target;
    }

    /** From the developer's machine: puts a built package on the server. */
    public function publish(string $zip, string $notes = ''): array
    {
        $token = (string) config('torgash.updates.publish_token');
        if ($token === '') {
            throw new RuntimeException('No TORGASH_UDS_TOKEN — this machine does not publish.');
        }

        $response = $this->http()->timeout(600)->withToken($token)
            ->attach('package', fopen($zip, 'r'), basename($zip))
            ->post($this->url().'?action=publish', ['product' => Release::PRODUCT, 'notes' => $notes]);

        if (! $response->successful()) {
            throw new RuntimeException('The update server refused: '.($response->json('error') ?? $response->status()));
        }

        return (array) $response->json();
    }

    protected function http(): PendingRequest
    {
        return Http::withOptions(['verify' => (bool) config('torgash.updates.verify_tls', true)])
            ->withUserAgent('Torgash/'.config('torgash.version'));
    }
}
