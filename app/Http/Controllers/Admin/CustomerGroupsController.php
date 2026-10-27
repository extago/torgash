<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerGroupsController extends Controller
{
    public function index()
    {
        return view('admin.customer_groups.index', [
            'groups' => CustomerGroup::withCount('users')->orderByDesc('is_default')->orderBy('id')->get(),
        ]);
    }

    public function create()
    {
        return $this->form(new CustomerGroup(['discount_percent' => 0]));
    }

    public function store(Request $request)
    {
        $group = new CustomerGroup;
        $this->save($group, $request);

        return $this->afterSave($request, $group, __('Group created.'));
    }

    public function edit(CustomerGroup $customerGroup)
    {
        return $this->form($customerGroup->loadCount('users'));
    }

    public function update(Request $request, CustomerGroup $customerGroup)
    {
        $this->save($customerGroup, $request);

        return $this->afterSave($request, $customerGroup, __('Group saved.'));
    }

    public function destroy(CustomerGroup $customerGroup)
    {
        $customerGroup->delete();

        return redirect()->route('admin.customer-groups.index')->with('status', __('Group deleted. Its customers see list prices now.'));
    }

    /**
     * "New registrations go here", switched from the list. One group at a
     * time, so choosing one un-chooses the other; choosing the chosen one
     * again un-chooses it — "nobody by default" is a real answer.
     */
    public function makeDefault(CustomerGroup $customerGroup)
    {
        $becomes = ! $customerGroup->is_default;

        CustomerGroup::where('is_default', true)->get()->each->update(['is_default' => false]);
        $customerGroup->update(['is_default' => $becomes]);

        return back()->with('status', $becomes
            ? __('New registrations will join :name.', ['name' => $customerGroup->t('name', admin_content_locale())])
            : __('New registrations will join no group.'));
    }

    protected function form(CustomerGroup $group)
    {
        return view('admin.customer_groups.form', ['group' => $group]);
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, CustomerGroup $group, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.customer-groups.index')
            : redirect()->route('admin.customer-groups.edit', $group))->with('status', $message);
    }

    protected function save(CustomerGroup $group, Request $request): void
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string'],
            'discount_percent' => 'required|integer|min:0|max:100',
        ]);

        $name = $group->name ?? [];
        if (trim($validated['name'] ?? '') === '') {
            unset($name[$locale]);
        } else {
            $name[$locale] = $validated['name'];
        }

        $group->fill([
            'name' => $name,
            'discount_percent' => $validated['discount_percent'],
        ]);
        $group->save();

        if ($request->boolean('is_default')) {
            CustomerGroup::where('id', '!=', $group->id)->where('is_default', true)->get()->each->update(['is_default' => false]);
            $group->update(['is_default' => true]);
        } elseif ($group->is_default) {
            // Was the default, box unchecked — just drop it, don't force-pick another.
            $group->update(['is_default' => false]);
        }
    }
}
