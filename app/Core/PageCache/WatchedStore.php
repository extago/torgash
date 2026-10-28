<?php

namespace App\Core\PageCache;

use Illuminate\Session\Store;

/** The framework's session store, watched while a copy of a page is drawn (WatchesSession). */
class WatchedStore extends Store
{
    use WatchesSession;
}
