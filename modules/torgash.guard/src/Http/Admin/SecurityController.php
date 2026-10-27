<?php

namespace Torgash\Guard\Http\Admin;

use Torgash\Guard\Http\SecurityScreen;

/**
 * A manager's own second factor, in the admin.
 *
 * Registered OUTSIDE this module's `can:guard.manage` route group, and that is
 * the point of it being a separate registration rather than another line in
 * `routes-admin.php`: every member of staff has to be able to secure their own
 * account, and only a few of them can read what the guard records. Permission
 * to see the audit trail is not permission to have a phone.
 *
 * It is also not on the admin menu. The way to it is the account dropdown in
 * the top-right corner, through core's `core.admin.user_nav` filter — the same
 * shape as `core.account.nav` on the storefront, and for the same reason: this
 * is a thing about the person looking, not a section of the shop.
 */
class SecurityController extends SecurityScreen
{
    protected function view(): string
    {
        return 'torgash.guard::admin.security';
    }

    protected function route(): string
    {
        return 'admin.guard.security';
    }
}
