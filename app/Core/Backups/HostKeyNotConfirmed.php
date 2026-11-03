<?php

namespace App\Core\Backups;

use RuntimeException;

/** An SFTP server met for the first time: its key is shown for the owner to confirm. */
class HostKeyNotConfirmed extends RuntimeException
{
    public function __construct(public readonly string $fingerprint)
    {
        parent::__construct("The server's key has not been confirmed yet. It shows $fingerprint — compare it with the one your hosting gives and confirm it in the storage's settings.");
    }
}
