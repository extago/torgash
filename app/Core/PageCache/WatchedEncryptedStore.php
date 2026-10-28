<?php

namespace App\Core\PageCache;

use Illuminate\Session\EncryptedStore;

/** The encrypted session store (SESSION_ENCRYPT), watched the same way (WatchesSession). */
class WatchedEncryptedStore extends EncryptedStore
{
    use WatchesSession;
}
