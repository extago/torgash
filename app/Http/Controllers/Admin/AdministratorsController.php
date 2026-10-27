<?php

namespace App\Http\Controllers\Admin;

use App\Core\Access\AccessGuard;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * The people who can sign in to the admin panel. Staff and customers share the
 * `users` table; what separates them is holding at least one permission group,
 * which is why a group is required here and cannot be taken away to zero.
 *
 * The rails in this controller all answer the same question — "can this screen
 * be used to get more power than the person using it has, or to leave the shop
 * with nobody able to sign in?" See AccessGuard for the first half.
 */
class AdministratorsController extends Controller
{
    public function __construct(protected AccessGuard $guard) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->get('q', ''));
        $groups = Role::orderBy('name')->get();
        $group = $groups->firstWhere('name', (string) $request->get('group', ''))?->name ?? '';

        $like = '%'.addcslashes($search, '%_\\').'%';

        $searched = fn () => User::query()
            ->staff()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)));

        $administrators = $searched()
            ->with('roles')
            ->when($group !== '', fn ($q) => $q->role($group))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Counted over the search, one query per group — a shop has a handful.
        $counts = ['' => $searched()->count()];
        foreach ($groups as $role) {
            $counts[$role->name] = $searched()->role($role->name)->count();
        }

        return view('admin.administrators.index', [
            'administrators' => $administrators,
            'search' => $search,
            'group' => $group,
            'groups' => $groups,
            'counts' => $counts,
            // What modules know about a page of staff, in one answer — the
            // customers list's own seam (`core.admin.customers.facts`), for
            // the other half of the users table. The guard says who has a
            // second factor; core does not know what one is.
            'facts' => (array) hook_filter('core.admin.staff.facts', [], ['ids' => $administrators->pluck('id')->all()]),
            'superRole' => AccessGuard::SUPER_ROLE,
        ]);
    }

    public function create()
    {
        return $this->form(new User);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request, new User);

        $administrator = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            // Invited with no password typed: a random one nobody knows, until
            // they choose theirs from the letter.
            'password' => ($validated['password'] ?? '') !== '' ? $validated['password'] : Str::random(40),
        ]);
        $administrator->save();
        $administrator->syncRoles($this->rolesFrom($validated['roles']));

        if ($request->boolean('send_invite')) {
            \App\Core\Access\PasswordLinks::staffInvite($administrator);
        }

        return $this->afterSave($request, $administrator, __('Administrator created.'));
    }

    public function edit(User $administrator)
    {
        $this->authorizeTarget($administrator);

        return $this->form($administrator);
    }

    public function update(Request $request, User $administrator)
    {
        $this->authorizeTarget($administrator);

        $validated = $this->validated($request, $administrator);

        $administrator->fill(['name' => $validated['name'], 'email' => $validated['email']]);
        if (($validated['password'] ?? '') !== '') {
            $administrator->password = $validated['password'];
        }
        $administrator->save();

        // Your own groups are not yours to edit: the form disables them, and
        // this ignores them even if the request says otherwise.
        if (! $this->isSelf($administrator)) {
            $this->assertNotLastSuper($administrator, $validated['roles']);
            $administrator->syncRoles($this->rolesFrom($validated['roles']));
        }

        return $this->afterSave($request, $administrator, __('Administrator updated.'));
    }

    public function destroy(User $administrator)
    {
        $this->authorizeTarget($administrator);

        if ($this->isSelf($administrator)) {
            throw ValidationException::withMessages(['name' => __('You cannot delete your own account.')]);
        }

        $this->assertNotLastSuper($administrator, []);

        $administrator->syncRoles([]);
        $administrator->delete();

        return redirect()->route('admin.administrators.index')
            ->with('status', __('Administrator deleted.'));
    }

    /** Saving stays on the form; "Save and close" in the gear goes back to the list. */
    protected function afterSave(Request $request, User $administrator, string $status)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.administrators.index')
            : redirect()->route('admin.administrators.edit', $administrator))
            ->with('status', $status);
    }

    protected function form(User $administrator)
    {
        $actor = auth()->user();
        $roles = Role::with('permissions')->orderBy('name')->get();

        return view('admin.administrators.form', [
            'administrator' => $administrator,
            'roles' => $roles,
            'assignable' => $this->guard->assignableRoles($actor, $roles)->pluck('name')->flip(),
            'isSelf' => $this->isSelf($administrator),
            'superRole' => AccessGuard::SUPER_ROLE,
            'facts' => $administrator->exists
                ? (array) (hook_filter('core.admin.staff.facts', [], ['ids' => [$administrator->id]])[$administrator->id] ?? [])
                : [],
        ]);
    }

    protected function validated(Request $request, User $administrator): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($administrator)],
            // A new manager needs a password — unless a letter lets them choose
            // it themselves (PasswordLinks::staffInvite).
            'password' => [Rule::requiredIf(! $administrator->exists && ! $request->boolean('send_invite')), 'nullable', 'string', 'min:8', 'confirmed'],
            // Editing yourself: the form disables the boxes, so the browser
            // posts nothing — "exclude" drops the field instead of failing it.
            'roles' => $this->isSelf($administrator) ? ['exclude'] : ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($this->assignableRoleNames())],
        ]);
    }

    /** @return list<string> */
    protected function assignableRoleNames(): array
    {
        return $this->guard
            ->assignableRoles(auth()->user(), Role::with('permissions')->get())
            ->pluck('name')
            ->all();
    }

    /** @param  list<string>  $names */
    protected function rolesFrom(array $names): array
    {
        return Role::whereIn('name', $names)->get()->all();
    }

    /**
     * A customer is not an administrator, and a staff account whose groups this
     * actor could not hand out is not theirs to edit either.
     */
    protected function authorizeTarget(User $administrator): void
    {
        $administrator->load('roles.permissions');

        abort_if($administrator->roles->isEmpty(), 404);
        abort_unless($this->guard->mayManageUser(auth()->user(), $administrator), 403);
    }

    protected function isSelf(User $administrator): bool
    {
        return $administrator->exists && $administrator->is(auth()->user());
    }

    /**
     * The shop must keep at least one account in the super group. Nothing else
     * can restore it from the UI: a lesser group cannot grant `admin`, so the
     * only way back would be the database.
     *
     * @param  list<string>  $newRoles  the groups the account is about to hold
     */
    protected function assertNotLastSuper(User $administrator, array $newRoles): void
    {
        $super = AccessGuard::SUPER_ROLE;

        if (! $administrator->hasRole($super) || in_array($super, $newRoles, true)) {
            return;
        }

        if (User::role($super)->count() <= 1) {
            throw ValidationException::withMessages([
                'roles' => __('This is the last account in the :group group. Give the group to somebody else first.', ['group' => $super]),
            ]);
        }
    }
}
