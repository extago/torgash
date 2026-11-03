<?php

namespace Torgash\GoogleDrive;

use App\Core\Backups\Destination;
use App\Core\Backups\Proxy;
use App\Core\Backups\Store;
use App\Models\BackupDestination;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use RuntimeException;

/**
 * The kind of backup storage this module adds (`google_drive`). Settings:
 * `root` (the folder under "My Drive"), `account` (whose Drive, once
 * connected), `connected_at`; the secret is the `refresh_token` Google
 * handed the storage at the owner's consent. The client ID and secret are
 * the module's settings — the shop's one Google Cloud application.
 */
class GoogleDriveDestination implements Destination
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public function label(): string
    {
        return 'Google Drive';
    }

    public function open(BackupDestination $destination): Store
    {
        $client = $this->client($destination);
        $adapter = new DriveAdapter($client, (string) $destination->setting('root', ''));

        return new Store(new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => true]));
    }

    /** The API client of a connected storage; a RuntimeException in words when it is not. */
    public function client(BackupDestination $destination): DriveClient
    {
        [$id, $secret] = self::application();

        if ($id === '' || $secret === '') {
            throw new RuntimeException(__('The Google client ID and secret are not set: Settings → Google Drive.'));
        }

        if ($destination->secret('refresh_token') === '') {
            throw new RuntimeException(__('No Google account is connected to this storage yet.'));
        }

        return new DriveClient($id, $secret, $destination->secret('refresh_token'), Proxy::from((array) $destination->settings, $destination->secrets()));
    }

    public function fromInput(array $input, ?BackupDestination $existing = null): array
    {
        $root = collect(explode('/', str_replace('\\', '/', trim((string) ($input['root'] ?? '')))))
            ->map(fn (string $part) => trim($part))
            ->filter(fn (string $part) => $part !== '' && $part !== '.' && $part !== '..')
            ->implode('/');

        $settings = [
            'root' => $root !== '' ? $root : 'Torgash backups',
            // The account is connected on its own screen, never typed.
            'account' => (string) $existing?->setting('account', ''),
            'connected_at' => (string) $existing?->setting('connected_at', ''),
        ];
        $secrets = ['refresh_token' => (string) ($existing?->secret('refresh_token') ?? '')];

        return [$settings, $secrets, []];
    }

    public function form(): string
    {
        return 'torgash.google-drive::form';
    }

    public function where(BackupDestination $destination): string
    {
        $account = (string) $destination->setting('account', '');

        return rtrim(($account !== '' ? $account.' · ' : '').$destination->setting('root', ''), '/');
    }

    public function proxied(): bool
    {
        return true;
    }

    /** @return array{0: string, 1: string} the shop's Google client ID and secret */
    public static function application(): array
    {
        return [
            trim((string) setting(GoogleDriveServiceProvider::ID, 'client_id', '')),
            trim((string) setting(GoogleDriveServiceProvider::ID, 'client_secret', '')),
        ];
    }
}
