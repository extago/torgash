<?php

namespace App\Core\Updates;

use Illuminate\Console\Command;

/** `torgash:release-key <file>` — a new Ed25519 pair: the secret into the file, the public key printed for config/torgash.php. */
class ReleaseKeyCommand extends Command
{
    protected $signature = 'torgash:release-key {file : Where to keep the SECRET key — outside the repository, never on a shop}';

    protected $description = 'Make the key pair releases are signed with';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (file_exists($file)) {
            $this->error("$file already exists — a key is never overwritten.");

            return self::FAILURE;
        }

        $keys = Release::generateKeys();
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, $keys['secret']);
        @chmod($file, 0600);

        $this->info("Secret key written to $file. Keep it off every shop and out of git.");
        $this->line('Public key — add it to config/torgash.php → updates.public_keys:');
        $this->line('  '.$keys['public']);

        return self::SUCCESS;
    }
}
