<?php

namespace Torgash\Catalog\Http\Admin;

use App\Core\Seo\MetaFields;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;

/**
 * Only the "brand page" fields (slug/description/logo) — the value's name
 * itself is still managed via the repeater on FeaturesController's form.
 */
class FeatureValuesController extends Controller
{
    public function edit(Feature $feature, FeatureValue $value)
    {
        abort_unless($value->feature_id === $feature->id, 404);

        return view('torgash.catalog::admin.features.value', ['feature' => $feature, 'value' => $value]);
    }

    public function update(Request $request, Feature $feature, FeatureValue $value)
    {
        abort_unless($value->feature_id === $feature->id, 404);

        $locale = admin_content_locale();

        $validated = $request->validate([
            'slug' => ['nullable', 'string', 'max:190', Rule::unique('m_catalog_feature_values', 'slug')->ignore($value->id)],
            'description' => 'nullable|string',
            'logo' => 'nullable|image|max:2048',
        ] + MetaFields::rules());

        $description = $value->description ?? [];
        if (trim($validated['description'] ?? '') === '') {
            unset($description[$locale]);
        } else {
            $description[$locale] = $validated['description'];
        }

        $slug = trim($validated['slug'] ?? '') ?: ($value->slug ?: Str::slug($value->display('en') ?? $value->display()));

        $value->update(
            ['slug' => $slug, 'description' => $description] + MetaFields::attributes($request, $value),
        );

        if ($request->hasFile('logo')) {
            $value->addMedia($request->file('logo'))->toMediaCollection('logo');
        }

        return redirect()->route('admin.catalog.features.edit', $feature)->with('status', 'Brand page updated.');
    }
}
