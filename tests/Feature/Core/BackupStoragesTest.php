<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Destinations;
use App\Core\Backups\HostKeyNotConfirmed;
use App\Core\Backups\Proxy;
use App\Core\Backups\SftpConnection;
use App\Core\Backups\Store;
use App\Models\BackupDestination;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

/**
 * The server kinds of backup storage (PLAN §67, stage 2): SFTP, FTP(S),
 * WebDAV, and a proxy for the ones that take it. WebDAV and the proxy are
 * real servers started here (sabre/dav under `php -S`, a small CONNECT +
 * SOCKS5 proxy in PHP); SFTP and FTP are checked against a real server by
 * hand (SFTPGo), the screen and the input here.
 */
class BackupStoragesTest extends TestCase
{
    /** @var list<resource> */
    protected array $processes = [];

    protected string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torgash-storages-'.uniqid();
        File::ensureDirectoryExists($this->work.'/dav');
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            $status = proc_get_status($process);
            // proc_terminate stops cmd, not its child on Windows: the pid tree.
            PHP_OS_FAMILY === 'Windows'
                ? exec('taskkill /F /T /PID '.$status['pid'].' 2>NUL')
                : proc_terminate($process);
            proc_close($process);
        }
        File::deleteDirectory($this->work);

        parent::tearDown();
    }

    public function test_a_webdav_storage_writes_reads_and_deletes(): void
    {
        $url = $this->webdav();
        $destination = $this->destination('webdav', ['url' => $url, 'username' => 'shop', 'password' => 'dav-pass', 'root' => 'shop/backups']);

        $this->assertNull(app(Destinations::class)->test($destination));

        $store = app(Destinations::class)->open($destination);
        $store->put('main/torgash-1.zip', $this->stream('backup body'));
        $this->assertFileExists($this->work.'/dav/shop/backups/main/torgash-1.zip', 'folders made on the way');
        $this->assertSame(['main/torgash-1.zip'], $store->files('main'));
        $this->assertSame('backup body', stream_get_contents($store->read('main/torgash-1.zip')));
        $store->delete('main/torgash-1.zip');
        $this->assertFileDoesNotExist($this->work.'/dav/shop/backups/main/torgash-1.zip');

        $wrong = $this->destination('webdav', ['url' => $url, 'username' => 'shop', 'password' => 'wrong', 'root' => '']);
        $this->assertStringContainsString('401', (string) app(Destinations::class)->test($wrong));
    }

    public function test_webdav_goes_through_an_http_or_socks5_proxy_with_a_login(): void
    {
        $url = $this->webdav();
        [$proxyPort, $log] = $this->proxy('pu', 'pp');

        foreach (['http', 'socks5'] as $type) {
            $destination = $this->destination('webdav', [
                'url' => $url, 'username' => 'shop', 'password' => 'dav-pass', 'root' => '',
                'proxy_type' => $type, 'proxy_host' => '127.0.0.1', 'proxy_port' => $proxyPort,
                'proxy_username' => 'pu', 'proxy_password' => 'pp',
            ]);

            $this->assertNull(app(Destinations::class)->test($destination), $type);
            $this->assertStringContainsString($type.' 127.0.0.1:'.parse_url($url, PHP_URL_PORT), (string) file_get_contents($log));
        }

        $refused = $this->destination('webdav', [
            'url' => $url, 'username' => 'shop', 'password' => 'dav-pass', 'root' => '',
            'proxy_type' => 'http', 'proxy_host' => '127.0.0.1', 'proxy_port' => $proxyPort,
            'proxy_username' => 'pu', 'proxy_password' => 'not-it',
        ]);
        $this->assertNotNull(app(Destinations::class)->test($refused), 'a proxy that refuses the login is a failure');
    }

    /** The tunnel SFTP is handed — opened by the engine's own code, both ways. */
    public function test_the_proxy_tunnel_is_opened_by_hand_for_sftp(): void
    {
        $url = $this->webdav();
        $port = (int) parse_url($url, PHP_URL_PORT);
        [$proxyPort] = $this->proxy('pu', 'pp');

        foreach (['http', 'socks5'] as $type) {
            $socket = (new Proxy($type, '127.0.0.1', $proxyPort, 'pu', 'pp'))->open('127.0.0.1', $port);
            fwrite($socket, "OPTIONS / HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
            $this->assertMatchesRegularExpression('#^HTTP/1\.\d \d{3}#', (string) fgets($socket), $type.': the server answered through the tunnel');
            fclose($socket);
        }

        foreach (['http' => 'refused the tunnel', 'socks5' => 'did not take the login'] as $type => $said) {
            try {
                (new Proxy($type, '127.0.0.1', $proxyPort, 'pu', 'wrong'))->open('127.0.0.1', $port);
                $this->fail($type.': a wrong login was let through');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($said, $e->getMessage());
            }
        }
    }

    public function test_server_kinds_take_their_input_and_keep_secrets(): void
    {
        $destinations = app(Destinations::class);

        [$settings, $secrets, $errors] = $destinations->fromInput('sftp', [
            'host' => 'SFTP://Backup.Example.com:2222/home', 'port' => '2222', 'username' => 'shop',
            'auth' => 'password', 'password' => 'p1', 'root' => '/backups/../shop/', 'proxy_type' => 'none',
        ]);
        $this->assertSame([], $errors);
        $this->assertSame('backup.example.com', $settings['host']);
        $this->assertSame(2222, $settings['port']);
        $this->assertSame('backups/shop', $settings['root']);
        $this->assertSame('', $settings['fingerprint']);
        $this->assertSame('p1', $secrets['password']);

        $existing = new BackupDestination(['driver' => 'sftp', 'settings' => ['fingerprint' => 'SHA256:'.str_repeat('A', 43)] + $settings]);
        $existing->putSecrets($secrets);

        // A blank password keeps the stored one; the same server keeps its key.
        [$same, $kept] = $destinations->fromInput('sftp', ['host' => 'backup.example.com', 'username' => 'shop', 'auth' => 'password', 'fingerprint' => 'SHA256:'.str_repeat('A', 43)], $existing);
        $this->assertSame('p1', $kept['password']);
        $this->assertSame('SHA256:'.str_repeat('A', 43), $same['fingerprint']);

        // Another server forgets the key confirmed for the old one.
        [$moved] = $destinations->fromInput('sftp', ['host' => 'other.example.com', 'username' => 'shop', 'auth' => 'password', 'fingerprint' => 'SHA256:'.str_repeat('A', 43)], $existing);
        $this->assertSame('', $moved['fingerprint']);

        [, , $errors] = $destinations->fromInput('sftp', ['host' => '', 'username' => '', 'auth' => 'key', 'private_key' => 'hello', 'fingerprint' => 'abc']);
        $this->assertSame(['host', 'username', 'private_key', 'fingerprint'], array_keys($errors));

        [$ftp, , $errors] = $destinations->fromInput('ftp', ['host' => 'ftp.example.com', 'username' => 'u', 'password' => 'p', 'ssl' => '1']);
        $this->assertSame([], $errors);
        $this->assertTrue($ftp['ssl']);
        $this->assertFalse($ftp['passive'], 'an unticked box is off');
        $this->assertArrayNotHasKey('proxy_type', $ftp, 'FTP takes no proxy');

        [$dav, $davSecrets, $errors] = $destinations->fromInput('webdav', [
            'url' => 'https://cloud.example.com/dav/', 'username' => 'u', 'password' => 'p',
            'proxy_type' => 'socks5', 'proxy_host' => 'proxy.example.com', 'proxy_username' => 'pu', 'proxy_password' => 'pp',
        ]);
        $this->assertSame([], $errors);
        $this->assertSame('https://cloud.example.com/dav', $dav['url']);
        $this->assertSame(1080, $dav['proxy_port']);
        $this->assertSame('pp', $davSecrets['proxy_password']);

        [, , $errors] = $destinations->fromInput('webdav', ['url' => 'ftp://x', 'proxy_type' => 'http', 'proxy_host' => '']);
        $this->assertSame(['url', 'proxy_host'], array_keys($errors));
    }

    public function test_an_sftp_fingerprint_is_compared_in_one_form(): void
    {
        $key = 'ssh-ed25519 '.base64_encode(random_bytes(51)).' host';
        $seen = SftpConnection::fingerprintOf($key);

        $this->assertMatchesRegularExpression('#^SHA256:[A-Za-z0-9+/]{43}$#', $seen);
        $this->assertSame($seen, SftpConnection::normalise(' '.substr($seen, 7).'= '), 'pasted without the prefix and with padding');
        $this->assertSame($seen, SftpConnection::normalise('sha256:'.substr($seen, 7)));
    }

    /** Found live on SFTPGo: flysystem wraps the refusal in "Unable to write file". */
    public function test_an_unconfirmed_key_is_found_inside_flysystems_wrapping(): void
    {
        $wrapped = UnableToWriteFile::atLocation('.probe', 'x', new HostKeyNotConfirmed('SHA256:'.str_repeat('E', 43)));
        $filesystem = \Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('writeStream')->andThrow($wrapped);

        $destinations = \Mockery::mock(Destinations::class)->makePartial();
        $destinations->shouldReceive('open')->andReturn(new Store($filesystem));

        try {
            $destinations->test(new BackupDestination(['driver' => 'sftp', 'settings' => []]));
            $this->fail('the key was not shown');
        } catch (HostKeyNotConfirmed $e) {
            $this->assertSame('SHA256:'.str_repeat('E', 43), $e->fingerprint);
        }
    }

    public function test_an_unconfirmed_sftp_server_is_shown_to_the_owner_not_used(): void
    {
        $destination = BackupDestination::create([
            'name' => 'Storage box', 'driver' => 'sftp', 'path_template' => '{plan}',
            'settings' => ['host' => 'box.example.com', 'port' => 22, 'username' => 'u', 'auth' => 'password', 'fingerprint' => ''],
        ]);
        $seen = 'SHA256:'.str_repeat('B', 43);

        $this->partialMock(Destinations::class, function ($mock) use ($seen) {
            $mock->shouldReceive('test')->andThrow(new HostKeyNotConfirmed($seen));
        });

        $admin = User::where('email', 'admin@torgash.local')->firstOrFail();
        $this->actingAs($admin)
            ->post(route('admin.system.backup-destinations.test', $destination))
            ->assertRedirect(route('admin.system.backup-destinations.edit', $destination));

        $this->actingAs($admin)->get(route('admin.system.backup-destinations.edit', $destination))
            ->assertOk()
            ->assertSee($seen)
            ->assertSee(route('admin.system.backup-destinations.trust', $destination));

        $this->assertSame('', $destination->fresh()->setting('fingerprint'), 'nothing is trusted by looking');
    }

    public function test_trusting_keeps_only_the_key_the_server_shows_now(): void
    {
        $destination = BackupDestination::create([
            'name' => 'Storage box', 'driver' => 'sftp', 'path_template' => '{plan}',
            'settings' => ['host' => 'box.example.com', 'port' => 22, 'username' => 'u', 'auth' => 'password', 'fingerprint' => ''],
        ]);
        $now = 'SHA256:'.str_repeat('C', 43);

        $this->mock(Destinations::class, function ($mock) use ($now) {
            $mock->shouldReceive('hostKey')->andReturn($now);
            $mock->shouldReceive('test')->andReturn(null);
            $mock->shouldReceive('drivers')->andReturn((new Destinations)->drivers());
        });
        $admin = User::where('email', 'admin@torgash.local')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.system.backup-destinations.trust', $destination), ['fingerprint' => 'SHA256:'.str_repeat('D', 43)]);
        $this->assertSame('', $destination->fresh()->setting('fingerprint'), 'a key the server no longer shows');

        $this->actingAs($admin)->post(route('admin.system.backup-destinations.trust', $destination), ['fingerprint' => $now]);
        $this->assertSame($now, $destination->fresh()->setting('fingerprint'));
    }

    public function test_the_storage_screen_offers_every_kind(): void
    {
        $admin = User::where('email', 'admin@torgash.local')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.system.backup-destinations.create'))
            ->assertOk()
            ->assertSee('value="sftp"', false)
            ->assertSee('value="ftp"', false)
            ->assertSee('value="webdav"', false)
            ->assertSee('settings[proxy_type]', false)
            ->assertSee('settings[private_key]', false);
    }

    // ------------------------------------------------------------------

    /** @param  array<string, mixed>  $fields */
    protected function destination(string $driver, array $fields): BackupDestination
    {
        [$settings, $secrets, $errors] = app(Destinations::class)->fromInput($driver, $fields);
        $this->assertSame([], $errors);

        $destination = new BackupDestination(['name' => $driver, 'driver' => $driver, 'settings' => $settings, 'path_template' => '']);
        $destination->putSecrets($secrets);

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

    protected function webdav(): string
    {
        $port = $this->freePort();
        $this->start([PHP_BINARY, '-S', '127.0.0.1:'.$port, base_path('tests/fixtures/backup-servers/webdav.php')], [
            'TORGASH_VENDOR' => base_path('vendor'),
            'DAV_ROOT' => $this->work.'/dav',
            'DAV_USER' => 'shop',
            'DAV_PASSWORD' => 'dav-pass',
        ], $port);

        return 'http://127.0.0.1:'.$port;
    }

    /** @return array{0: int, 1: string} the port and the log of tunnels */
    protected function proxy(string $user = '', string $password = ''): array
    {
        $port = $this->freePort();
        $log = $this->work.'/proxy.log';
        touch($log);
        $this->start([PHP_BINARY, base_path('tests/fixtures/backup-servers/proxy.php')], [
            'PROXY_PORT' => (string) $port,
            'PROXY_USER' => $user,
            'PROXY_PASSWORD' => $password,
            'PROXY_LOG' => $log,
        ], $port);

        return [$port, $log];
    }

    protected function start(array $command, array $env, int $port): void
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->work.'/out.log', 'a'], 2 => ['file', $this->work.'/err.log', 'a']], $pipes, null, $env + getenv());
        $this->assertIsResource($process);
        $this->processes[] = $process;

        for ($i = 0; $i < 100; $i++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $error, 0.2);
            if ($socket) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }

        $this->fail('The test server did not start: '.@file_get_contents($this->work.'/err.log'));
    }

    protected function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        return $port;
    }
}
