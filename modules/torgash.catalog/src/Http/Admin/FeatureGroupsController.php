<?php

namespace Torgash\Catalog\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureGroup;

/**
 * The groups characteristics are filed under (PLAN §63.10): named in every
 * content language, ordered by a drag, deleted without taking their
 * characteristics along. A group is also born on a characteristic's form by
 * typing a new word (FeaturesController::resolveGroupId); this screen is
 * where it is renamed, translated, ordered and removed.
 */
class FeatureGroupsController extends Controller
{
    public function index()
    {
        return view('torgash.catalog::admin.feature-groups.index', [
            'groups' => FeatureGroup::withCount('features')->orderBy('sort')->orderBy('id')->get(),
            'ungrouped' => Feature::whereNull('group_id')->count(),
        ]);
    }

    public function create()
    {
        return view('torgash.catalog::admin.feature-groups.form', ['group' => new FeatureGroup, 'features' => collect()]);
    }

    public function store(Request $request)
    {
        $group = FeatureGroup::create([
            'name' => put_translation(null, $this->typedName($request)),
            'sort' => ((int) FeatureGroup::max('sort')) + 10,
        ]);

        return $this->afterSave($request, $group, __('Group created.'));
    }

    public function edit(FeatureGroup $group)
    {
        return view('torgash.catalog::admin.feature-groups.form', [
            'group' => $group,
            'features' => $group->features()->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, FeatureGroup $group)
    {
        // ONE language per save: the picked content language's word, every
        // other kept (put_translation; a blank removes that language alone).
        $group->update(['name' => put_translation($group->name, $this->typedName($request))]);

        return $this->afterSave($request, $group, __('Group saved.'));
    }

    /** The characteristics stay, filed under no group. */
    public function destroy(FeatureGroup $group)
    {
        $count = $group->features()->update(['group_id' => null]);
        $group->delete();

        return redirect()->route('admin.catalog.feature-groups.index')
            ->with('status', trans_choice('Group deleted; :count characteristic is without a group now.|Group deleted; :count characteristics are without a group now.', $count, ['count' => $count]));
    }

    /** The order as dragged: ids first to last. */
    public function sort(Request $request)
    {
        $ids = array_values(array_map('intval', (array) $request->input('ids', [])));

        foreach ($ids as $position => $id) {
            FeatureGroup::whereKey($id)->update(['sort' => ($position + 1) * 10]);
        }

        return response()->noContent();
    }

    protected function typedName(Request $request): ?string
    {
        $validated = $request->validate([
            'name' => [content_locale_is_base() ? 'required' : 'nullable', 'string', 'max:120'],
        ]);

        return $validated['name'] ?? null;
    }

    protected function afterSave(Request $request, FeatureGroup $group, string $status)
    {
        return $request->input('after_save') === 'close'
            ? redirect()->route('admin.catalog.feature-groups.index')->with('status', $status)
            : redirect()->route('admin.catalog.feature-groups.edit', $group)->with('status', $status);
    }
}
