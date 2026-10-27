<?php

namespace Torgash\Blog\Http\Admin;

use App\Core\Seo\MetaFields;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Torgash\Blog\Models\Rubric;

/**
 * The blog's rubrics (PLAN §47): a flat list of coloured plates, each with an
 * icon and a feed of its own. Deleting one takes no article with it — they
 * simply stop being in it.
 */
class RubricsController extends Controller
{
    public function index()
    {
        return view('torgash.blog::admin.rubrics', [
            'rubrics' => Rubric::withCount('posts')->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function create()
    {
        return view('torgash.blog::admin.rubric-form', ['rubric' => new Rubric(['enabled' => true, 'color' => '#2563eb'])]);
    }

    public function store(Request $request)
    {
        $rubric = new Rubric;
        $this->save($request, $rubric);

        return $this->afterSave($request, $rubric, __('Rubric created.'));
    }

    public function edit(Rubric $rubric)
    {
        return view('torgash.blog::admin.rubric-form', ['rubric' => $rubric]);
    }

    public function update(Request $request, Rubric $rubric)
    {
        $this->save($request, $rubric);

        return $this->afterSave($request, $rubric, __('Rubric saved.'));
    }

    public function destroy(Rubric $rubric)
    {
        $rubric->delete();

        return redirect()->route('admin.blog.rubrics.index')->with('status', __('Rubric deleted.'));
    }

    protected function afterSave(Request $request, Rubric $rubric, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.blog.rubrics.index')
            : redirect()->route('admin.blog.rubrics.edit', $rubric))->with('status', $message);
    }

    protected function save(Request $request, Rubric $rubric): void
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'name' => [content_locale_is_base() && ! $rubric->exists ? 'required' : 'nullable', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]*$/'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort' => ['nullable', 'integer'],
        ] + MetaFields::rules());

        $name = put_translation($rubric->name, $validated['name'] ?? '', $locale);
        $base = Str::slug(($validated['slug'] ?? '') !== '' ? $validated['slug'] : (string) ($name[config('app.locale')] ?? reset($name) ?: 'rubric')) ?: 'rubric';
        $slug = $base;
        for ($n = 2; Rubric::where('slug', $slug)->whereKeyNot($rubric->id ?? 0)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        // An icon name the pack does not have draws nothing rather than
        // throwing on the storefront.
        $icon = ($validated['icon'] ?? '') !== '' && is_file(resource_path('icons/lucide/'.$validated['icon'].'.svg')) ? $validated['icon'] : null;

        $rubric->fill([
            'name' => $name,
            'slug' => $slug,
            'description' => put_translation($rubric->description, $validated['description'] ?? '', $locale),
            'icon' => $icon,
            'color' => $validated['color'] ?? null,
            'sort' => (int) ($validated['sort'] ?? 0),
            'enabled' => $request->boolean('enabled'),
        ] + MetaFields::attributes($request, $rubric))->save();
    }
}
