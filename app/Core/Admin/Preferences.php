<?php

namespace App\Core\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What one member of staff keeps about how THEY look at the admin — the
 * sidebar folded into a strip of icons (PLAN §62), the dashboard's own
 * arrangement (Dashboard writes that one the same way) — in
 * `users.admin_preferences`, a JSON map keyed by what it is about.
 *
 * Written past Eloquent on purpose: a preference is not an edit of the
 * account — it must not move `updated_at` or land in the audit trail as
 * "changed the user".
 */
class Preferences
{
    public static function get(?User $user, string $key, mixed $default = null): mixed
    {
        return ((array) ($user?->admin_preferences ?? []))[$key] ?? $default;
    }

    /** `null` forgets the key: the default is then the answer again. */
    public static function put(User $user, string $key, mixed $value): void
    {
        $prefs = (array) ($user->admin_preferences ?? []);

        if ($value === null) {
            unset($prefs[$key]);
        } else {
            $prefs[$key] = $value;
        }

        $json = $prefs ? json_encode($prefs) : null;
        DB::table('users')->where('id', $user->id)->update(['admin_preferences' => $json]);
        $user->setRawAttributes(['admin_preferences' => $json] + $user->getAttributes(), true);
    }
}
