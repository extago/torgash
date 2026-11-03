<?php

namespace Tests\Feature\GoogleDrive;

use App\Core\Backups\Destinations;
use App\Core\Settings\SettingsManager;
use App\Models\BackupDestination;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\GoogleDrive\GoogleDriveDestination;

/**
 * Google Drive as a backup storage (PLAN §67.1, the first kind a module
 * adds): the registry on `core.backup.destinations`, the store over a
 * Drive played by a fake (ids, names, a resumable upload that loses half a
 * chunk), and the OAuth doors — connect, callback, disconnect.
 */
class GoogleDriveStorageTest extends TestCase
{
    protected FakeDrive $drive;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.google-drive', 'client_id', 'shop.apps.googleusercontent.com');
        app(SettingsManager::class)->set('torgash.google-drive', 'client_secret', 'GOCSPX-test');

        $this->drive = new FakeDrive;
        Http::fake(fn (Request $request) => $this->drive->answer($request));
    }

    public function test_the_kind_is_registered_with_core(): void
    {
        $destinations = app(Destinations::class);

        $this->assertSame('Google Drive', $destinations->drivers()['google_drive']);
        $this->assertContains('google_drive', $destinations->proxied());
        $this->assertInstanceOf(GoogleDriveDestination::class, $destinations->extensions()['google_drive']);
    }

    public function test_the_input_keeps_the_account_and_cleans_the_folder(): void
    {
        $destinations = app(Destinations::class);

        [$settings, $secrets, $errors] = $destinations->fromInput('google_drive', ['root' => ' /Backups/../Shop/ ', 'proxy_type' => 'none']);
        $this->assertSame([], $errors);
        $this->assertSame('Backups/Shop', $settings['root']);
        $this->assertSame('', $settings['account']);
        $this->assertSame('', $secrets['refresh_token']);

        [$default] = $destinations->fromInput('google_drive', ['root' => '']);
        $this->assertSame('Torgash backups', $default['root']);

        $existing = $this->connected('Old');
        [$kept, $keptSecrets] = $destinations->fromInput('google_drive', ['root' => 'Old', 'proxy_type' => 'none'], $existing);
        $this->assertSame('owner@gmail.com', $kept['account'], 'the account is connected on its own screen, never typed');
        $this->assertSame('refresh-1', $keptSecrets['refresh_token']);
    }

    public function test_the_store_writes_lists_reads_and_deletes_in_the_drive(): void
    {
        $destination = $this->connected('Torgash backups/Shop');
        $destinations = app(Destinations::class);

        $this->assertNull($destinations->test($destination), 'a file written, read back and deleted');

        $store = $destinations->open($destination);
        $body = str_repeat('backup ', 200);
        $store->put('main/torgash-1.zip', $this->stream($body));

        $this->assertSame(['Torgash backups', 'Shop', 'main'], $this->drive->folderNames(), 'the root and the plan folder made on the way');
        $this->assertSame(['main/torgash-1.zip'], $store->files('main'));
        $this->assertSame(['main/torgash-1.zip'], $store->files('', true));
        $this->assertTrue($store->exists('main/torgash-1.zip'));
        $this->assertFalse($store->exists('main/torgash-2.zip'));
        $this->assertSame(strlen($body), $store->size('main/torgash-1.zip'));
        $this->assertSame($body, stream_get_contents($store->read('main/torgash-1.zip')));
        $this->assertGreaterThan(1, $this->drive->chunks, 'the upload resumed after Google kept only half');

        // The same name again is the same file, replaced — not a twin.
        $store->put('main/torgash-1.zip', $this->stream('second'));
        $this->assertSame(['main/torgash-1.zip'], $store->files('main'));
        $this->assertSame('second', stream_get_contents($store->read('main/torgash-1.zip')));

        $store->delete('main/torgash-1.zip');
        $this->assertSame([], $store->files('main'));

        // A backup "without an archive" is a folder, deleted whole.
        $store->put('main/torgash-2/manifest.json', $this->stream('{}'));
        $store->put('main/torgash-2/database.dump', $this->stream('pg'));
        $this->assertSame(['main/torgash-2/database.dump', 'main/torgash-2/manifest.json'], $store->files('main', true));
        $store->deleteFolder('main/torgash-2');
        $this->assertFalse($store->exists('main/torgash-2/manifest.json'));
        $this->assertSame([], $store->files('main', true));
    }

    public function test_a_storage_without_an_account_says_so(): void
    {
        $destination = new BackupDestination(['name' => 'Drive', 'driver' => 'google_drive', 'settings' => ['root' => 'X'], 'path_template' => '']);
        $destination->putSecrets([]);

        $this->assertStringContainsString('No Google account is connected', (string) app(Destinations::class)->test($destination));

        app(SettingsManager::class)->set('torgash.google-drive', 'client_secret', '');
        $this->assertStringContainsString('client ID and secret are not set', (string) app(Destinations::class)->test($destination));
    }

    public function test_a_dead_key_is_said_in_words(): void
    {
        $this->drive->tokenError = 'invalid_grant';

        $this->assertStringContainsString('connect the Google account again', (string) app(Destinations::class)->test($this->connected('X')));
    }

    public function test_connecting_goes_to_google_and_comes_back_with_a_key(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $destination = BackupDestination::create(['name' => 'Drive', 'driver' => 'google_drive', 'settings' => ['root' => 'Shop', 'account' => '', 'connected_at' => ''], 'path_template' => '']);

        $this->actingAs($admin)->get('http://localhost/admin/system/backup-destinations/'.$destination->id.'/edit')
            ->assertOk()->assertSee(__('Connect a Google account'))->assertSee('Google Drive');

        // The storage's own form sends the owner off with its PUT.
        $away = $this->put('http://localhost/admin/google-drive/connect/'.$destination->id, ['name' => 'Drive', 'driver' => 'google_drive']);
        $away->assertRedirect();
        $url = $away->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('shop.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame('http://localhost/admin/google-drive/callback', $query['redirect_uri']);
        $this->assertSame(GoogleDriveDestination::SCOPE, $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $state = $query['state'];

        // A callback nobody here started is refused.
        $this->get('http://localhost/admin/google-drive/callback?state=forged&code=abc')->assertForbidden();

        // The real one: the state was pulled by the forged attempt, start over.
        $this->put('http://localhost/admin/google-drive/connect/'.$destination->id);
        $state = session('google_drive.connect')['state'];

        $this->get('http://localhost/admin/google-drive/callback?state='.$state.'&code=code-1')
            ->assertRedirect('http://localhost/admin/system/backup-destinations/'.$destination->id.'/edit')
            ->assertSessionHas('status');

        $destination->refresh();
        $this->assertSame('owner@gmail.com', $destination->setting('account'));
        $this->assertSame('refresh-from-code-1', $destination->secret('refresh_token'));
        $this->assertSame('code-1', $this->drive->exchanged, 'the code was swapped at the token endpoint with the redirect URI');
        $this->assertNotEmpty($this->drive->probes, 'and the storage was checked at once');

        $this->get('http://localhost/admin/system/backup-destinations/'.$destination->id.'/edit')
            ->assertOk()->assertSee('owner@gmail.com')->assertSee(__('Disconnect'))->assertDontSee('refresh-from-code-1');
        $this->get('http://localhost/admin/system/backup-destinations')->assertOk()->assertSee('owner@gmail.com · Shop');

        // Declined on Google's page.
        $this->put('http://localhost/admin/google-drive/connect/'.$destination->id);
        $state = session('google_drive.connect')['state'];
        $this->get('http://localhost/admin/google-drive/callback?state='.$state.'&error=access_denied')->assertSessionHas('test_error');

        $this->put('http://localhost/admin/google-drive/disconnect/'.$destination->id)->assertRedirect()->assertSessionHas('status');
        $destination->refresh();
        $this->assertSame('', $destination->setting('account'));
        $this->assertSame('', $destination->secret('refresh_token'));
        $this->assertSame('refresh-from-code-1', $this->drive->revoked);
    }

    public function test_the_settings_refuse_a_client_id_that_is_not_one(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->post('http://localhost/admin/settings/torgash.google-drive', ['client_id' => 'not-a-client', 'client_secret' => 'x'])
            ->assertSessionHasErrors('client_id');
        $this->assertSame('shop.apps.googleusercontent.com', setting('torgash.google-drive', 'client_id'));

        $this->get('http://localhost/admin/settings/torgash.google-drive')->assertOk()
            ->assertSee('http://localhost/admin/google-drive/callback');
    }

    protected function connected(string $root): BackupDestination
    {
        $destination = new BackupDestination([
            'name' => 'Drive', 'driver' => 'google_drive', 'path_template' => '',
            'settings' => ['root' => $root, 'account' => 'owner@gmail.com', 'connected_at' => now()->toIso8601String(), 'proxy_type' => 'none'],
        ]);
        $destination->putSecrets(['refresh_token' => 'refresh-1']);

        return $destination;
    }

    /** @return resource */
    protected function stream(string $body)
    {
        $stream = tmpfile();
        fwrite($stream, $body);
        rewind($stream);

        return $stream;
    }
}

/**
 * A Drive in memory: nodes by id with a name, a parent and a mime type; the
 * token endpoint; a resumable upload whose first chunk is answered "I have
 * half of it" so the client has to resume.
 */
class FakeDrive
{
    /** @var array<string, array{name: string, parent: string, mime: string, body: string}> */
    public array $nodes = [];

    /** @var array<string, array{name: string, parent: string, size: int, got: string}> */
    public array $sessions = [];

    public int $chunks = 0;

    public ?string $tokenError = null;

    public ?string $exchanged = null;

    public ?string $revoked = null;

    /** @var list<string> */
    public array $probes = [];

    protected int $next = 1;

    public function answer(Request $request)
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $method = $request->method();

        if ($url === 'https://oauth2.googleapis.com/token') {
            $data = $request->data();
            if (($data['grant_type'] ?? '') === 'authorization_code') {
                $this->exchanged = $data['redirect_uri'] === 'http://localhost/admin/google-drive/callback' ? $data['code'] : null;

                return Http::response(['access_token' => 'at', 'refresh_token' => 'refresh-from-'.$data['code'], 'expires_in' => 3599]);
            }
            if ($this->tokenError) {
                return Http::response(['error' => $this->tokenError], 400);
            }

            return Http::response(['access_token' => 'at-'.$data['refresh_token'], 'expires_in' => 3599]);
        }

        if ($url === 'https://oauth2.googleapis.com/revoke') {
            $this->revoked = $request->data()['token'] ?? null;

            return Http::response('', 200);
        }

        if (! str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer at')) {
            return Http::response(['error' => ['message' => 'no token']], 401);
        }

        if ($path === '/drive/v3/about') {
            return Http::response(['user' => ['emailAddress' => 'owner@gmail.com'], 'storageQuota' => ['limit' => '16106127360', 'usage' => '100']]);
        }

        if ($path === '/drive/v3/files' && $method === 'GET') {
            return Http::response(['files' => $this->query((string) ($query['q'] ?? ''))]);
        }

        if ($path === '/drive/v3/files' && $method === 'POST') {
            $data = $request->data();
            $id = $this->add($data['name'], $data['parents'][0], $data['mimeType'], '');

            return Http::response(['id' => $id]);
        }

        if ($path === '/upload/drive/v3/files' && $method === 'POST') {
            $data = $request->data();
            $session = 'session-'.$this->next++;
            $this->sessions[$session] = ['name' => $data['name'], 'parent' => $data['parents'][0], 'size' => (int) $request->header('X-Upload-Content-Length')[0], 'got' => ''];

            return Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/session/'.$session]);
        }

        if (str_starts_with($path, '/upload/session/') && $method === 'PUT') {
            $session = &$this->sessions[basename($path)];
            $this->chunks++;
            preg_match('#bytes (\d+|\*)-?(\d+)?/(\d+)#', $request->header('Content-Range')[0] ?? '', $m);
            $from = $m[1] === '*' ? 0 : (int) $m[1];

            if ($from !== strlen($session['got'])) {
                return Http::response(['error' => ['message' => 'chunk out of order: have '.strlen($session['got']).', given '.$from]], 400);
            }

            $body = $request->body();
            // The first chunk of anything over a hundred bytes: half kept, the rest "lost".
            if ($this->chunks === 1 && strlen($body) > 100) {
                $body = substr($body, 0, intdiv(strlen($body), 2));
            }
            $session['got'] .= $body;

            if (strlen($session['got']) < $session['size']) {
                return Http::response('', 308, ['Range' => 'bytes=0-'.(strlen($session['got']) - 1)]);
            }

            $id = $this->add($session['name'], $session['parent'], 'application/zip', $session['got']);
            if (str_starts_with($session['name'], '.torgash-probe-')) {
                $this->probes[] = $session['name'];
            }

            return Http::response(['id' => $id]);
        }

        if (preg_match('#^/drive/v3/files/([^/]+)$#', $path, $m)) {
            $id = rawurldecode($m[1]);
            if ($method === 'GET' && ($query['alt'] ?? '') === 'media') {
                return isset($this->nodes[$id]) ? Http::response($this->nodes[$id]['body']) : Http::response('', 404);
            }
            if ($method === 'DELETE') {
                $this->remove($id);

                return Http::response('', 204);
            }
        }

        return Http::response(['error' => ['message' => 'unexpected '.$method.' '.$url]], 500);
    }

    /** @return list<string> every folder's name, in the order made */
    public function folderNames(): array
    {
        return array_values(array_map(fn ($n) => $n['name'], array_filter($this->nodes, fn ($n) => $n['mime'] === 'application/vnd.google-apps.folder')));
    }

    protected function add(string $name, string $parent, string $mime, string $body): string
    {
        $id = 'id-'.$this->next++;
        $this->nodes[$id] = ['name' => $name, 'parent' => $parent, 'mime' => $mime, 'body' => $body];

        return $id;
    }

    protected function remove(string $id): void
    {
        unset($this->nodes[$id]);
        foreach ($this->nodes as $childId => $node) {
            if ($node['parent'] === $id) {
                $this->remove($childId);
            }
        }
    }

    /** The subset of the Drive query language the client writes. */
    protected function query(string $q): array
    {
        preg_match("#'([^']+)' in parents#", $q, $parent);
        preg_match("#name = '((?:[^'\\\\]|\\\\.)*)'#", $q, $name);
        preg_match("#mimeType (=|!=) '([^']+)'#", $q, $mime);

        $found = [];
        foreach ($this->nodes as $id => $node) {
            if ($node['parent'] !== ($parent[1] ?? null)) {
                continue;
            }
            if ($name && $node['name'] !== stripslashes($name[1])) {
                continue;
            }
            if ($mime && (($mime[1] === '=') !== ($node['mime'] === $mime[2]))) {
                continue;
            }
            $found[] = ['id' => $id, 'name' => $node['name'], 'mimeType' => $node['mime'], 'size' => (string) strlen($node['body']), 'modifiedTime' => '2026-11-03T10:00:00.000Z'];
        }

        return $found;
    }
}
