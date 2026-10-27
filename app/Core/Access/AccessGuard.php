<?php

namespace App\Core\Access;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * The two rules that keep "who may manage staff" from being a way around
 * every other permission.
 *
 * 1. NOBODY GRANTS WHAT THEY DO NOT HAVE. `staff.manage` is otherwise the only
 *    permission worth having: hand it out and its holder writes themselves a
 *    group with everything in it. So a non-super actor may only tick
 *    permissions they hold, and only assign groups whose permissions they hold.
 * 2. THE SUPER ROLE IS NOT HANDED OUT SIDEWAYS. `admin` implicitly holds every
 *    permission (Gate::before in CoreServiceProvider), including ones a module
 *    installed after the group was made, so it is never a subset of anything
 *    and can only be granted by someone who already has it.
 *
 * A super admin is exempt from both — they already have every permission, so
 * neither rule could stop them from anything they cannot do directly.
 */
class AccessGuard
{
    /** The role Gate::before treats as holding every permission. */
    public const SUPER_ROLE = 'admin';

    public function isSuper(User $actor): bool
    {
        return $actor->hasRole(self::SUPER_ROLE);
    }

    /** Permission names $actor may put into a group. */
    public function assignablePermissions(User $actor, PermissionCatalog $catalog): array
    {
        if ($this->isSuper($actor)) {
            return $catalog->names();
        }

        return $actor->getAllPermissions()->pluck('name')->all();
    }

    /**
     * Groups $actor may hand to somebody — their own permissions or less.
     *
     * @param  Collection<int, Role>  $roles
     * @return Collection<int, Role>
     */
    public function assignableRoles(User $actor, Collection $roles): Collection
    {
        if ($this->isSuper($actor)) {
            return $roles;
        }

        $held = $actor->getAllPermissions()->pluck('name')->flip();

        return $roles->filter(function (Role $role) use ($held) {
            if ($role->name === self::SUPER_ROLE) {
                return false;
            }

            return $role->permissions->every(fn ($permission) => $held->has($permission->name));
        });
    }

    /**
     * Whether $actor may edit this staff account at all. Without this, rule 1
     * leaks through the back door: a manager who cannot grant the `admin` group
     * could still open the shop owner's account and change its password.
     */
    public function mayManageUser(User $actor, User $target): bool
    {
        if ($this->isSuper($actor)) {
            return true;
        }

        $assignable = $this->assignableRoles($actor, $target->roles)->pluck('name')->flip();

        return $target->roles->every(fn (Role $role) => $assignable->has($role->name));
    }

    /** Whether $actor may change this group's name or permissions. */
    public function mayEditRole(User $actor, Role $role): bool
    {
        if ($this->isSuper($actor)) {
            return true;
        }

        if ($role->name === self::SUPER_ROLE) {
            return false;
        }

        $held = $actor->getAllPermissions()->pluck('name')->flip();

        return $role->permissions->every(fn ($permission) => $held->has($permission->name));
    }
}
