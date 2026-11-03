<?php

namespace Torgash\GoogleDrive;

use App\Core\Backups\Proxy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Drive v3 — the dozen calls a backup storage needs, by hand over
 * the HTTP client rather than through google/apiclient: that SDK is tens of
 * megabytes of generated classes for every Google service, and a module
 * carries no composer dependencies of its own.
 *
 * Signed in with a REFRESH token the owner's consent handed the storage
 * (`drive.file` scope: the shop sees only what it put there itself). The
 * access token it buys lives an hour and is kept in the cache, keyed by the
 * refresh token's hash, so a page that lists backups and the job that
 * writes one share it.
 */
class DriveClient
{
    public const API = 'https://www.googleapis.com/drive/v3/';

    public const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/';

    public const TOKEN = 'https://oauth2.googleapis.com/token';

    public const FOLDER = 'application/vnd.google-apps.folder';

    /** A multiple of 256 KiB, as the resumable upload asks. */
    public const CHUNK = 16 * 1024 * 1024;

    protected ?string $accessToken = null;

    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected string $refreshToken,
        protected ?Proxy $proxy = null,
    ) {}

    /** Whose Drive this is and how much room it has: user, storageQuota. */
    public function about(): array
    {
        return $this->request()->get(self::API.'about', ['fields' => 'user(emailAddress,displayName),storageQuota(limit,usage)'])
            ->throw()->json();
    }

    /** A child of $parent by name, or null. Folders and files alike unless $folder says. */
    public function find(string $parent, string $name, ?bool $folder = null): ?array
    {
        $q = sprintf("name = '%s' and '%s' in parents and trashed = false", $this->quote($name), $parent);
        if ($folder !== null) {
            $q .= sprintf(" and mimeType %s '%s'", $folder ? '=' : '!=', self::FOLDER);
        }

        $found = $this->request()->get(self::API.'files', [
            'q' => $q,
            'fields' => 'files(id,name,mimeType,size,modifiedTime)',
            'pageSize' => 2,
            'spaces' => 'drive',
        ])->throw()->json('files', []);

        return $found[0] ?? null;
    }

    /** @return list<array{id: string, name: string, mimeType: string, size?: string, modifiedTime?: string}> */
    public function children(string $parent): array
    {
        $files = [];
        $page = null;

        do {
            $answer = $this->request()->get(self::API.'files', array_filter([
                'q' => sprintf("'%s' in parents and trashed = false", $parent),
                'fields' => 'nextPageToken,files(id,name,mimeType,size,modifiedTime)',
                'pageSize' => 1000,
                'spaces' => 'drive',
                'pageToken' => $page,
            ]))->throw()->json();

            array_push($files, ...($answer['files'] ?? []));
            $page = $answer['nextPageToken'] ?? null;
        } while ($page);

        return $files;
    }

    public function createFolder(string $parent, string $name): string
    {
        return (string) $this->request()
            ->post(self::API.'files?fields=id', ['name' => $name, 'mimeType' => self::FOLDER, 'parents' => [$parent]])
            ->throw()->json('id');
    }

    /**
     * A file written in one resumable session, chunk by chunk: a backup is
     * hundreds of megabytes and a single request of that size is what times
     * out. The stream is read from where it stands; its length comes from
     * the stream itself (a file), or the stream is copied to a temporary
     * file first.
     *
     * @param  resource  $stream
     */
    public function upload(string $parent, string $name, $stream): string
    {
        $size = $this->length($stream);

        $session = $this->request()
            ->withHeaders(['X-Upload-Content-Length' => (string) $size])
            ->post(self::UPLOAD.'files?uploadType=resumable&fields=id', ['name' => $name, 'parents' => [$parent]])
            ->throw()->header('Location');

        if ($session === '') {
            throw new RuntimeException('Google Drive did not open an upload session.');
        }

        if ($size === 0) {
            $answer = $this->request()->withHeaders(['Content-Range' => 'bytes */0'])->withBody('', 'application/octet-stream')->put($session);

            return $this->uploaded($answer);
        }

        $start = (int) ftell($stream);
        $sent = 0;
        while ($sent < $size) {
            // From where Google says it stands — a chunk it lost is read again.
            fseek($stream, $start + $sent);
            $chunk = (string) stream_get_contents($stream, min(self::CHUNK, $size - $sent));
            if ($chunk === '') {
                throw new RuntimeException('The backup ended before its declared size.');
            }

            $range = sprintf('bytes %d-%d/%d', $sent, $sent + strlen($chunk) - 1, $size);
            $answer = $this->request(upload: true)
                ->withHeaders(['Content-Range' => $range])
                ->withBody($chunk, 'application/octet-stream')
                ->put($session);

            if ($answer->status() === 308) {
                // "Resume Incomplete": Range says how much Google has.
                $sent = preg_match('/bytes=0-(\d+)/', (string) $answer->header('Range'), $m) ? (int) $m[1] + 1 : $sent + strlen($chunk);

                continue;
            }

            return $this->uploaded($answer);
        }

        throw new RuntimeException('Google Drive took every chunk and never said the file was complete.');
    }

    /**
     * The file's bytes into a temporary file, handed back rewound — a backup
     * is read by pg_restore from a file, and a network stream cannot be
     * sought in.
     *
     * @return resource
     */
    public function download(string $id)
    {
        $answer = $this->request()->withOptions(['stream' => true])->get(self::API.'files/'.rawurlencode($id), ['alt' => 'media']);
        $answer->throw();

        $file = tmpfile();
        $body = $answer->toPsrResponse()->getBody();
        while (! $body->eof()) {
            fwrite($file, $body->read(1024 * 1024));
        }
        $body->close();
        rewind($file);

        return $file;
    }

    /** Gone for good, not to the bin: a backup kept past its time would still count against the quota there. */
    public function delete(string $id): void
    {
        $answer = $this->request()->delete(self::API.'files/'.rawurlencode($id));

        if ($answer->status() !== 404) {
            $answer->throw();
        }
    }

    /** @param  array<string, mixed>  $patch  name, and/or addParents/removeParents as query */
    public function update(string $id, array $body, array $query = []): void
    {
        $this->request()->patch(self::API.'files/'.rawurlencode($id).($query ? '?'.http_build_query($query) : ''), $body)->throw();
    }

    public function copy(string $id, string $parent, string $name): string
    {
        return (string) $this->request()
            ->post(self::API.'files/'.rawurlencode($id).'/copy?fields=id', ['name' => $name, 'parents' => [$parent]])
            ->throw()->json('id');
    }

    /** The one-hour access token, bought with the refresh token and kept in the cache. */
    public function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $key = 'google_drive.token.'.hash('sha256', $this->clientId.'|'.$this->refreshToken);

        return $this->accessToken = (string) Cache::remember($key, 3300, function () {
            $answer = $this->bare()->asForm()->post(self::TOKEN, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if ($answer->failed()) {
                throw new RuntimeException(match ($answer->json('error')) {
                    'invalid_grant' => __('Google no longer accepts the storage\'s key: connect the Google account again.'),
                    'invalid_client' => __('Google does not know this client ID and secret: check the module\'s settings.'),
                    default => 'Google: '.($answer->json('error_description') ?: $answer->json('error') ?: $answer->status()),
                });
            }

            return (string) $answer->json('access_token');
        });
    }

    /** Swaps the code Google sent back for the tokens; static, there is no refresh token yet. */
    public static function exchange(string $clientId, string $clientSecret, string $code, string $redirectUri, ?Proxy $proxy = null): array
    {
        $answer = (new self($clientId, $clientSecret, '', $proxy))->bare()->asForm()->post(self::TOKEN, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if ($answer->failed()) {
            throw new RuntimeException('Google: '.($answer->json('error_description') ?: $answer->json('error') ?: $answer->status()));
        }

        return (array) $answer->json();
    }

    /** Tells Google the storage is done with its key. Best effort: a key already dead answers 400. */
    public static function revoke(string $token, ?Proxy $proxy = null): void
    {
        try {
            (new self('', '', '', $proxy))->bare()->asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
        } catch (\Throwable) {
            // The owner can also take the app's access away in the Google account.
        }
    }

    protected function request(bool $upload = false): PendingRequest
    {
        return $this->bare($upload)->withToken($this->accessToken())->acceptJson();
    }

    /** Without the token: the token endpoint itself, and the revoke. */
    protected function bare(bool $upload = false): PendingRequest
    {
        $request = Http::connectTimeout(20)
            // A chunk is 16 MiB; the rest answers in seconds.
            ->timeout($upload ? 600 : 60)
            ->withOptions(array_filter(['proxy' => $this->proxy?->guzzle()]))
            // Asked again only for what a second try can mend; every other
            // answer, a 308 "resume incomplete" included, is handed back as
            // it is for the caller to read.
            ->retry(3, 1000, fn (?\Throwable $e) => $e instanceof RequestException
                ? in_array($e->response->status(), [429, 500, 502, 503, 504], true)
                : $e instanceof ConnectionException, throw: false);

        return $request;
    }

    protected function uploaded(Response $answer): string
    {
        $answer->throw();
        $id = (string) $answer->json('id');

        if ($id === '') {
            throw new RuntimeException('Google Drive did not name the file it took.');
        }

        return $id;
    }

    /** @param  resource  $stream */
    protected function length($stream): int
    {
        $stat = fstat($stream);
        $position = ftell($stream);

        if ($stat !== false && ($stat['size'] ?? -1) >= 0 && $position !== false && stream_get_meta_data($stream)['seekable']) {
            return $stat['size'] - $position;
        }

        throw new RuntimeException('A backup is uploaded from a file, not a pipe.');
    }

    /** A name inside a Drive query: the backslash and the quote escaped. */
    protected function quote(string $name): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
    }
}
