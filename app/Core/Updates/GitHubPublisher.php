<?php

namespace App\Core\Updates;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Puts a built release on GitHub — from the developer's machine, beside the
 * delivery service (`torgash:release --github`). What GitHubReleases reads on
 * the shop's side is what this writes: a release tagged `vX.Y.Z` carrying the
 * update package, the distribution, and the .sha256 and .sig of each.
 *
 * The release is made as a DRAFT, the files go up, and only then is it
 * published: a shop asking in between must never be offered a version whose
 * package is not there yet. A version already on GitHub is refused, never
 * replaced — the same rule as the delivery service, because a shop that
 * installed it once must be able to trust the number.
 *
 * Needs a token with write access to the repository's contents
 * (`TORGASH_GITHUB_PUBLISH_TOKEN`, only on the publishing machine).
 */
class GitHubPublisher
{
    /**
     * @param  list<string>  $files  absolute paths, uploaded under their own names
     * @return array{url: string, tag: string, assets: list<string>}
     */
    public function publish(string $version, array $files, string $notes = ''): array
    {
        [$owner, $repo] = $this->repository();
        $tag = 'v'.$version;
        $api = "https://api.github.com/repos/{$owner}/{$repo}";

        $existing = $this->http()->get("{$api}/releases/tags/{$tag}");
        if ($existing->successful()) {
            throw new RuntimeException("{$tag} is already on GitHub ({$existing->json('html_url')}). A published version is never replaced — release a new one.");
        }

        $release = $this->http()->post("{$api}/releases", [
            'tag_name' => $tag,
            'name' => 'Torgash '.$version,
            'body' => $notes,
            'draft' => true,
            'prerelease' => false,
        ])->throw()->json();

        $id = (int) ($release['id'] ?? 0);
        $uploaded = [];

        try {
            foreach ($files as $file) {
                if (! is_file($file)) {
                    throw new RuntimeException('Missing file: '.$file);
                }

                $name = basename($file);
                $this->http()->timeout(1800)
                    ->withBody((string) file_get_contents($file), $this->type($name))
                    ->post("https://uploads.github.com/repos/{$owner}/{$repo}/releases/{$id}/assets?name=".rawurlencode($name))
                    ->throw();
                $uploaded[] = $name;
            }

            $published = $this->http()->patch("{$api}/releases/{$id}", ['draft' => false])->throw()->json();
        } catch (Throwable $e) {
            // A half-uploaded draft is nobody's release: take it away, so the
            // next attempt starts clean and no shop ever sees it.
            try {
                $this->http()->delete("{$api}/releases/{$id}");
            } catch (Throwable) {
            }

            throw new RuntimeException('GitHub: '.$e->getMessage(), 0, $e);
        }

        return ['url' => (string) ($published['html_url'] ?? ''), 'tag' => $tag, 'assets' => $uploaded];
    }

    /** @return array{0: string, 1: string} */
    protected function repository(): array
    {
        $configured = trim((string) config('torgash.updates.github', ''));
        $repo = $configured === '' ? null : GitHubReleases::repository(str_contains($configured, 'github.com') ? $configured : 'github.com/'.$configured);

        if (! $repo) {
            throw new RuntimeException('No TORGASH_GITHUB_REPO (owner/repo) — nowhere to publish.');
        }

        return $repo;
    }

    protected function type(string $name): string
    {
        return str_ends_with($name, '.zip') ? 'application/zip' : 'text/plain';
    }

    protected function http(): PendingRequest
    {
        $token = (string) config('torgash.updates.github_publish_token', '');

        if ($token === '') {
            throw new RuntimeException('No TORGASH_GITHUB_PUBLISH_TOKEN — this machine does not publish to GitHub.');
        }

        return Http::withToken($token)
            ->withUserAgent('Torgash/'.config('torgash.version'))
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(60);
    }
}
