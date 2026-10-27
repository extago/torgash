<?php

namespace Torgash\ShopReviews\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\ShopReviews\Models\Platform;

/** The shop's mark on other sites, typed by hand and shown on the reviews page. */
class PlatformsController extends Controller
{
    public function index()
    {
        return view('torgash.shopreviews::admin.platforms', [
            'platforms' => Platform::orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function create()
    {
        return view('torgash.shopreviews::admin.platform-form', [
            'platform' => new Platform(['enabled' => true, 'sort' => (int) Platform::max('sort') + 10]),
        ]);
    }

    public function edit(Platform $platform)
    {
        return view('torgash.shopreviews::admin.platform-form', ['platform' => $platform]);
    }

    public function store(Request $request)
    {
        $platform = Platform::create($this->validated($request));

        return $this->afterSave($request, $platform, __('Site added.'));
    }

    public function update(Request $request, Platform $platform)
    {
        $platform->update($this->validated($request));

        return $this->afterSave($request, $platform, __('Site updated.'));
    }

    public function destroy(Platform $platform)
    {
        $platform->delete();

        return redirect()->route('admin.shopreviews.platforms.index')->with('status', __('Site deleted.'));
    }

    protected function afterSave(Request $request, Platform $platform, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.shopreviews.platforms.index')
            : redirect()->route('admin.shopreviews.platforms.edit', $platform))->with('status', $message);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'url' => 'nullable|url:https,http|max:500',
            'rating' => 'nullable|numeric|min:0|max:5',
            'reviews_count' => 'nullable|integer|min:0',
            'sort' => 'nullable|integer|min:0',
        ]);

        return [
            'name' => trim($data['name']),
            'url' => $data['url'] ?? null,
            'rating' => isset($data['rating']) ? round((float) $data['rating'], 1) : null,
            'reviews_count' => $data['reviews_count'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
            'enabled' => $request->boolean('enabled'),
        ];
    }
}
