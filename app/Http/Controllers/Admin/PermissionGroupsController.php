<?php

namespace App\Http\Controllers\Admin;

use App\Core\Access\AccessGuard;
use App\Core\Access\PermissionCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Named sets of permissions — a spatie role, called a permission group on the
 * screen because that is what a shop owner calls it.
 *
 * The name is a plain string and not a translatable JSONB field like a customer
 * group's: spatie owns the column, looks a role up by that exact string
 * (hasRole('admin')) and has a unique index on it. Groups are staff-facing and
 * few, so the cost of translating them is not worth breaking that.
 *
 * The super group is special everywhere: it holds every permission implicitly
 * (Gate::before), including ones installed after it was made, so there is
 * nothing to tick and nothing to rename.
 */
class PermissionGroupsController extends Controller
{
    public function __construct(protected AccessGuard $guard, protected PermissionCatalog $catalog) {}

    public function index()
    {
        return view('admin.permission_groups.index', [
            'groups' => Role::withCount(['users', 'permissions'])->orderBy('name')->get(),
            'superRole' => AccessGuard::SUPER_ROLE,
        ]);
    }

    public function create()
    {
        return $this->form(new Role);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request, new Role);

        $group = Role::create(['name' => $validated['name']]);
        $group->syncPermissions($this->permissionsFrom($validated['permissions'] ?? []));

        return $this->afterSave($request, $group, __('Permission group created.'));
    }

    public function edit(Role $permissionGroup)
    {
        $this->authorizeTarget($permissionGroup);

        return $this->form($permissionGroup);
    }

    public function update(Request $request, Role $permissionGroup)
    {
        $this->authorizeTarget($permissionGroup);

        // The super group's name is what Gate::before matches on, and its
        // permission list is implicit — there is nothing here to change.
        if ($permissionGroup->name === AccessGuard::SUPER_ROLE) {
            return redirect()->route('admin.permission-groups.index');
        }

        $validated = $this->validated($request, $permissionGroup);

        $permissionGroup->update(['name' => $validated['name']]);
        $permissionGroup->syncPermissions($this->permissionsFrom($validated['permissions'] ?? []));

        return $this->afterSave($request, $permissionGroup, __('Permission group updated.'));
    }

    public function destroy(Role $permissionGroup)
    {
        $this->authorizeTarget($permissionGroup);

        if ($permissionGroup->name === AccessGuard::SUPER_ROLE) {
            throw ValidationException::withMessages(['name' => __('The built-in administrator group cannot be deleted.')]);
        }

        // Deleting it would silently strip its members of every permission and,
        // if it was their only group, of their staff status too.
        if ($permissionGroup->users()->count() > 0) {
            throw ValidationException::withMessages([
                'name' => __('This group still has :count member(s). Move them to another group first.', [
                    'count' => $permissionGroup->users()->count(),
                ]),
            ]);
        }

        $permissionGroup->delete();

        return redirect()->route('admin.permission-groups.index')
            ->with('status', __('Permission group deleted.'));
    }

    /** Saving stays on the form; "Save and close" in the gear goes back to the list. */
    protected function afterSave(Request $request, Role $group, string $status)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.permission-groups.index')
            : redirect()->route('admin.permission-groups.edit', $group))
            ->with('status', $status);
    }

    protected function form(Role $group)
    {
        $actor = auth()->user();

        return view('admin.permission_groups.form', [
            'group' => $group,
            'catalog' => $this->catalog->groups(),
            'assignable' => array_flip($this->guard->assignablePermissions($actor, $this->catalog)),
            'held' => $group->exists ? $group->permissions->pluck('name')->flip() : collect(),
            'isSuper' => $group->name === AccessGuard::SUPER_ROLE,
            'members' => $group->exists ? $group->users()->orderBy('name')->get(['users.id', 'name', 'email']) : collect(),
        ]);
    }

    protected function validated(Request $request, Role $group): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:125',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($group),
                Rule::notIn($group->name === AccessGuard::SUPER_ROLE ? [] : [AccessGuard::SUPER_ROLE]),
            ],
            'permissions' => 'array',
            'permissions.*' => ['string', Rule::in($this->guard->assignablePermissions(auth()->user(), $this->catalog))],
        ]);
    }

    /** @param  list<string>  $names */
    protected function permissionsFrom(array $names): array
    {
        return \Spatie\Permission\Models\Permission::whereIn('name', $names)->get()->all();
    }

    protected function authorizeTarget(Role $group): void
    {
        $group->load('permissions');

        abort_unless($this->guard->mayEditRole(auth()->user(), $group), 403);
    }
}
