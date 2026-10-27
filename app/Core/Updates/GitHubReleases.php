<?php

namespace App\Core\Updates;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GitHub's Releases as an update source, beside the update delivery service.
 *
 * Some shops cannot reach extago.com (the first live one, in Russia, gets 16 KB
 * and a cut-off) but can reach GitHub — so a release published there as well
 * is something they can install by themselves. A release is a tag `vX.Y.Z`
 * with the update package `torgash-X.Y.Z.zip` among its assets (and, if GitHub
 * gives no digest, `torgash-X.Y.Z.zip.sha256` beside it). Drafts and
 * pre-releases are not offered.
 *
 * Trusted exactly as the delivery service is — with availability only: the
 * package carries its own signature (release.json + release.sig), checked
 * against the keys in this code before anything is installed (UpdatePackage).
 * The sum guards against a cut-off download, nothing more.
 *
 * Asked anonymously (60 requests an hour per address, plenty for one check a
 * day); a token (`TORGASH_GITHUB_TOKEN`) is for a private repository.
 */
class GitHubReleases
{
    /** `https://github.com/owner/repo` (or `github.com/owner/repo`, `owner/repo` in .env) → [owner, repo]. */
    public static function repository(string $address): ?array
    {
        $address = trim($address);

        if (preg_match('#^(?:https?://)?(?:www\.)?github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $address, $m)) {
            return [$m[1], $m[2]];
        }

        return null;
    }

    /**
     * What is newer than $current, oldest first — the same shape the delivery
     * service answers with: version, notes, download, sha256.
     *
     * @return array{latest: string, updates: list<array{version: string, notes: string, download: string, sha256: string, released_at: ?string}>}
     */
    public function check(string $owner, string $repo, string $current): array
    {
        $releases = $this->http()->timeout(15)
            ->get("https://api.github.com/repos/{$owner}/{$repo}/releases", ['per_page' => 30])
            ->throw()->json();

        if (! is_array($releases) || ! array_is_list($releases)) {
            throw new RuntimeException('GitHub answered something else.');
        }

        $updates = [];

        foreach ($releases as $release) {
            if (! is_array($release) || ($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
                continue;
            }

            $version = ltrim((string) ($release['tag_name'] ?? ''), 'vV');

            if (! preg_match('/^\d+\.\d+\.\d+$/', $version) || ! version_compare($version, $current, '>')) {
                continue;
            }

            $assets = collect((array) ($release['assets'] ?? []))->keyBy('name');
            $package = $assets->get('torgash-'.$version.'.zip');

            // A release without the update package (only the -install
            // archive, say) has nothing a running shop can install.
            if (! is_array($package)) {
                continue;
            }

            $updates[] = [
                'version' => $version,
                'notes' => trim((string) ($release['body'] ?? '')),
                'download' => $this->downloadUrl($package),
                'sha256' => $this->sum($package, $assets->get('torgash-'.$version.'.zip.sha256')),
                'released_at' => $release['published_at'] ?? null,
            ];
        }

        usort($updates, fn ($a, $b) => version_compare($a['version'], $b['version']));

        return [
            'latest' => $updates === [] ? $current : end($updates)['version'],
            'updates' => $updates,
        ];
    }

    /**
     * The asset's sum: GitHub's own digest when it gives one (`sha256:…`), the
     * published `.sha256` file otherwise. Empty when neither exists — the
     * download is then refused, because a sum nobody announced checks nothing.
     */
    protected function sum(array $package, mixed $sumFile): string
    {
        $digest = (string) ($package['digest'] ?? '');

        if (str_starts_with($digest, 'sha256:')) {
            return strtolower(substr($digest, 7));
        }

        if (! is_array($sumFile)) {
            return '';
        }

        $url = $this->downloadUrl($sumFile);
        $text = (string) $this->prepare($this->http(), $url)->timeout(15)->get($url)->throw()->body();

        return preg_match('/\b([0-9a-f]{64})\b/i', $text, $m) ? strtolower($m[1]) : '';
    }

    /**
     * Where to fetch an asset: its public address, or — for a private
     * repository, with a token — the API address that answers with the bytes.
     */
    protected function downloadUrl(array $asset): string
    {
        return $this->token() !== '' && ! empty($asset['url'])
            ? (string) $asset['url']
            : (string) ($asset['browser_download_url'] ?? '');
    }

    /** What a download from GitHub needs on top of the plain request. */
    public function prepare(PendingRequest $request, string $url): PendingRequest
    {
        if ($this->token() !== '' && str_starts_with($url, 'https://api.github.com/')) {
            return $request->withToken($this->token())->accept('application/octet-stream');
        }

        return $request;
    }

    protected function token(): string
    {
        return (string) config('torgash.updates.github_token', '');
    }

    protected function http(): PendingRequest
    {
        $request = Http::withUserAgent('Torgash/'.config('torgash.version'))
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);

        return $this->token() !== '' ? $request->withToken($this->token()) : $request;
    }
}
