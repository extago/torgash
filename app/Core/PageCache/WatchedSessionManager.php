<?php

namespace App\Core\PageCache;

use Illuminate\Session\SessionManager;

/**
 * Builds the session as a store the page cache can watch (PLAN §61) — the
 * same store with the same handler, whichever driver is configured; only
 * the class differs, and it says nothing until a copy of a page is drawn.
 */
class WatchedSessionManager extends SessionManager
{
    protected function buildSession($handler)
    {
        return $this->config->get('session.encrypt')
            ? $this->buildEncryptedSession($handler)
            : new WatchedStore(
                $this->config->get('session.cookie'),
                $handler,
                $id = null,
                $this->config->get('session.serialization', 'php')
            );
    }

    protected function buildEncryptedSession($handler)
    {
        return new WatchedEncryptedStore(
            $this->config->get('session.cookie'),
            $handler,
            $this->container['encrypter'],
            $id = null,
            $this->config->get('session.serialization', 'php'),
        );
    }
}
