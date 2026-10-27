<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The shop's customers — core's, because an account is core's.
 *
 * What a customer DID lives in modules, so the screen asks rather than knows:
 * `core.admin.customers.facts` gives a page of the list its numbers in one
 * go (checkout answers with orders and money paid), and
 * `core.admin.customer.panels` gives the card whole sections. This class names
 * no module and works with every one of them uninstalled.
 *
 * Deleting asks too: `core.admin.customer.delete_notes` is what each module
 * says will happen to ITS rows (orders stay under the name they were placed
 * with, a wishlist goes), printed in the confirmation before anything is
 * touched. What a module has to DO about it is `core.user.deleting`, fired by
 * the model, so an administrator deleted on the other screen is the same act.
 */
class CustomersController extends Controller
{
    public const SORTS = ['newest', 'oldest', 'name'];

    public function index(Request $request)
    {
        $groups = CustomerGroup::orderBy('id')->get();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            // '' everybody, 'none' without a group, else a group id.
            'group' => $this->groupFilter((string) $request->query('group', ''), $groups),
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'newest',
        ];

        $searched = fn () => User::query()->customers()
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $needle = '%'.$filters['q'].'%';

                // The account's own phone (digits only, the way it is kept)
                // and any phone typed on its addresses.
                $digits = preg_replace('/\D+/', '', $filters['q']);
                $q->where(fn ($w) => $w
                    ->where('name', 'ilike', $needle)
                    ->orWhere('email', 'ilike', $needle)
                    ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', '%'.$digits.'%'))
                    ->orWhere('external_id', $filters['q'])
                    ->orWhereHas('addresses', fn ($a) => $a->where('phone', 'ilike', $needle)));
            });

        // Per group, over what the search leaves.
        $byGroup = $searched()->toBase()->selectRaw('customer_group_id, count(*) as n')
            ->groupBy('customer_group_id')->pluck('n', 'customer_group_id');
        $counts = ['' => (int) $byGroup->sum(), 'none' => (int) ($byGroup[''] ?? $byGroup[null] ?? 0)];
        foreach ($groups as $group) {
            $counts[(string) $group->id] = (int) ($byGroup[$group->id] ?? 0);
        }

        $query = $searched()->with('customerGroup')
            ->when($filters['group'] === 'none', fn ($q) => $q->whereNull('customer_group_id'))
            ->when(! in_array($filters['group'], ['', 'none'], true), fn ($q) => $q->where('customer_group_id', (int) $filters['group']));

        match ($filters['sort']) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $customers = $query->paginate(30)->withQueryString();

        return view('admin.customers.index', [
            'customers' => $customers,
            'groups' => $groups,
            'filters' => $filters,
            'counts' => $counts,
            // One answer for the page, asked once.
            'facts' => (array) hook_filter('core.admin.customers.facts', [], ['ids' => $customers->pluck('id')->all()]),
        ]);
    }

    /**
     * An account made by the shop — a wholesale client, somebody who orders by
     * phone and should see their group's prices when they do sign in. Created
     * with no role, which is what makes it a customer and not staff.
     */
    public function create()
    {
        return view('admin.customers.create', [
            'customer' => new User,
            'groups' => CustomerGroup::orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(new User));

        $customer = new User([
            'name' => $validated['name'],
            'email' => $validated['email'] ?: null,
            'customer_group_id' => $validated['customer_group_id'] ?? null,
            // No password typed: an account nobody can sign in to until one is
            // set here — never a guessable default.
            'password' => ($validated['password'] ?? '') !== '' ? $validated['password'] : Str::random(40),
        ]);
        $customer->save();
        $this->writePhone($customer, $validated['phone'] ?? null);

        // They choose their own password from a letter (PLAN §41).
        if ($request->boolean('send_invite')) {
            \App\Core\Access\PasswordLinks::customerInvite($customer);
        }

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.customers.index')
            : redirect()->route('admin.customers.edit', $customer))->with('status', __('Customer created.'));
    }

    public function edit(User $customer)
    {
        abort_unless($this->isCustomer($customer), 404);

        $customer->load('addresses', 'customerGroup');

        $panels = collect((array) hook_filter('core.admin.customer.panels', [], ['customer' => $customer]))
            ->filter(fn ($panel) => filled($panel['html'] ?? null))
            ->sortBy(fn ($panel) => $panel['position'] ?? 50)
            ->values();

        return view('admin.customers.edit', [
            'customer' => $customer,
            'groups' => CustomerGroup::orderBy('id')->get(),
            'panels' => $panels,
            'facts' => (array) (hook_filter('core.admin.customers.facts', [], ['ids' => [$customer->id]])[$customer->id] ?? []),
            'deleteNotes' => $this->deleteNotes($customer),
        ]);
    }

    public function update(Request $request, User $customer)
    {
        abort_unless($this->isCustomer($customer), 404);

        $validated = $request->validate($this->rules($customer));

        $customer->fill([
            'name' => $validated['name'],
            'email' => $validated['email'] ?: null,
            'customer_group_id' => $validated['customer_group_id'] ?? null,
        ]);
        // A changed email is not proven any more.
        if ($customer->isDirty('email')) {
            $customer->email_verified_at = null;
        }
        // Blank keeps the one they have.
        if (($validated['password'] ?? '') !== '') {
            $customer->password = $validated['password'];
        }
        $customer->save();
        $this->writePhone($customer, $validated['phone'] ?? null);

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.customers.index')
            : redirect()->route('admin.customers.edit', $customer))->with('status', __('Customer saved.'));
    }

    public function destroy(User $customer)
    {
        abort_unless($this->isCustomer($customer), 404);

        $name = $customer->name;
        $this->delete($customer);

        return redirect()->route('admin.customers.index')->with('status', __('Customer :name deleted.', ['name' => $name]));
    }

    /** The list's in-place group switch: answers with the cell. */
    public function setGroup(Request $request, User $customer)
    {
        abort_unless($this->isCustomer($customer), 404);

        $validated = $request->validate(['customer_group_id' => 'nullable|exists:customer_groups,id']);
        $customer->update(['customer_group_id' => $validated['customer_group_id'] ?? null]);

        return view('admin.customers._group', [
            'customer' => $customer->load('customerGroup'),
            'groups' => CustomerGroup::orderBy('id')->get(),
        ]);
    }

    /** Several customers into one group, or out of every group. Model by model, for the audit. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['group', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'customer_group_id' => 'nullable|exists:customer_groups,id',
        ]);

        $customers = User::customers()->whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $customers->each(fn (User $customer) => $this->delete($customer));

            return back()->with('status', trans_choice(':count customer deleted.|:count customers deleted.', $customers->count(), ['count' => $customers->count()]));
        }

        $customers->each(fn (User $customer) => $customer->update(['customer_group_id' => $validated['customer_group_id'] ?? null]));

        return back()->with('status', trans_choice(':count customer moved.|:count customers moved.', $customers->count(), ['count' => $customers->count()]));
    }

    /**
     * One at a time and through the model, so the audit records each and
     * `core.user.deleting` reaches every module. One transaction: a module
     * that fails to let go of its rows leaves the account where it was.
     */
    protected function delete(User $customer): void
    {
        DB::transaction(fn () => $customer->delete());
    }

    /**
     * What happens to what this person left behind, as each owner says it.
     * Core speaks for its own rows only: the addresses go with the account.
     *
     * @return list<array{key: string, label: string, deleted: bool}>
     */
    protected function deleteNotes(User $customer): array
    {
        $notes = [];
        $addresses = $customer->addresses->count();
        if ($addresses > 0) {
            $notes[] = ['key' => 'addresses', 'deleted' => true,
                'label' => trans_choice(':count saved address is deleted.|:count saved addresses are deleted.', $addresses, ['count' => $addresses])];
        }

        return array_values(array_filter(
            (array) hook_filter('core.admin.customer.delete_notes', $notes, ['customer' => $customer]),
            fn ($note) => filled($note['label'] ?? null),
        ));
    }

    /**
     * What the shop requires of an account (AccountContact) binds the admin
     * too — an account nobody can sign in to is not made here either. A phone
     * the shop types for a customer who orders by phone counts as proven: the
     * shop is vouching for it, the way it may set their password.
     *
     * @return array<string, mixed>
     */
    protected function rules(User $customer): array
    {
        $contact = app(\App\Core\Access\AccountContact::class);
        $either = $contact->eitherWillDo();

        return [
            'name' => 'required|string|max:255',
            'email' => array_values(array_filter([
                $contact->requires('email') ? 'required' : 'nullable',
                $either ? 'required_without:phone' : null,
                'email', 'max:255', Rule::unique('users', 'email')->ignore($customer->id),
            ])),
            'phone' => array_values(array_filter([
                $contact->requires('phone') ? 'required' : 'nullable',
                $either ? 'required_without:email' : null,
                'string', 'max:40',
                function (string $attribute, mixed $value, \Closure $fail) use ($customer) {
                    $phone = \App\Core\Support\Phones::parse((string) $value);
                    if (! $phone) {
                        $fail(__('This does not look like a phone number. Type it with the country code, like :example.', ['example' => \App\Core\Support\Phones::callingCode(\App\Core\Support\Phones::defaultCountry()).' …']));
                    } elseif (User::where('phone', $phone['e164'])->where('id', '!=', (int) $customer->id)->exists()) {
                        $fail(__('This address is already bound to another account. Type another one.'));
                    }
                },
            ])),
            'customer_group_id' => 'nullable|exists:customer_groups,id',
            'password' => ['nullable', 'string', Password::min(8)],
        ];
    }

    /** The phone the shop typed, kept the way every phone is — E.164 and its country. */
    protected function writePhone(User $customer, ?string $typed): void
    {
        $phone = filled($typed) ? \App\Core\Support\Phones::parse($typed) : null;

        if (($phone['e164'] ?? null) === $customer->phone) {
            return;
        }

        $customer->forceFill([
            'phone' => $phone['e164'] ?? null,
            'phone_country' => $phone['country'] ?? null,
            'phone_verified_at' => $phone ? now() : null,
        ])->save();
    }

    /**
     * Staff are not customers, and this screen must not become a way round the
     * administrators' screen — `User::customers()`, never "not the admin role".
     */
    protected function isCustomer(User $user): bool
    {
        return User::customers()->whereKey($user->id)->exists();
    }

    protected function groupFilter(string $value, $groups): string
    {
        if ($value === 'none') {
            return 'none';
        }

        return $groups->contains('id', (int) $value) ? (string) (int) $value : '';
    }
}
