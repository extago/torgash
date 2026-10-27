<?php

namespace Torgash\Sku\Http\Admin;

use App\Core\Settings\SettingsManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Sku\Generator;
use Torgash\Sku\Renumber;
use Torgash\Sku\Templates;

/**
 * Catalogue → SKU templates: the shop's template and when it applies (the
 * module's two settings, on this screen rather than a separate settings page,
 * so the examples beside them make sense), a template per category, and the
 * renumbering of what is already there.
 */
class SkuController extends Controller
{
    public function index(CategoryTree $tree, Templates $templates, Generator $generator)
    {
        $own = $templates->byCategory();

        return view('torgash.sku::admin.index', [
            'template' => (string) setting('torgash.sku', 'template', 'SKU-{n:6}'),
            'onCreate' => (string) setting('torgash.sku', 'on_create', 'blank'),
            'categories' => $tree->ordered(),
            'tree' => $tree,
            'own' => $own,
            'fallbackCount' => Renumber::fallbackOnly(Product::query())->count(),
            'parkedCount' => Renumber::parked(Product::query())->count(),
            'example' => fn (string $template) => $this->example($generator, $template),
        ]);
    }

    public function save(Request $request, SettingsManager $settings, Templates $templates)
    {
        $data = $request->validate([
            'template' => ['required', 'string', 'max:190'],
            'on_create' => ['required', 'in:blank,always,off'],
            'categories' => ['array'],
            'categories.*' => ['nullable', 'string', 'max:190'],
        ]);

        foreach (['template' => $data['template'], ...array_filter((array) ($data['categories'] ?? []))] as $field => $template) {
            if ($unknown = Generator::unknownTokens((string) $template)) {
                throw ValidationException::withMessages([
                    $field === 'template' ? 'template' : 'categories.'.$field => __('Not a token this template knows: :tokens', ['tokens' => implode(' ', $unknown)]),
                ]);
            }
        }

        $settings->set('torgash.sku', 'template', trim($data['template']));
        $settings->set('torgash.sku', 'on_create', $data['on_create']);

        $known = array_flip(array_map('intval', app(CategoryTree::class)->all()->keys()->all()));
        foreach ((array) ($data['categories'] ?? []) as $categoryId => $template) {
            if (isset($known[(int) $categoryId])) {
                $templates->put((int) $categoryId, (string) $template);
            }
        }

        return redirect()->route('admin.sku.index')->with('status', __('Saved.'));
    }

    public function renumber(Request $request)
    {
        $data = $request->validate([
            'scope' => ['required', 'in:fallback,all,restart'],
            'category' => ['nullable', 'integer', 'exists:m_catalog_categories,id'],
        ]);

        // Starting over is the whole shop's numbering: a category alone would
        // reset the running numbers the rest of the shop goes on using.
        $category = $data['scope'] !== 'restart' && isset($data['category']) ? (int) $data['category'] : null;
        $task = Renumber::startIfIdle($data['scope'], $category);

        return redirect()->route('admin.sku.index')->with('status', $task
            ? __('Renumbering has started; it runs in the background (System → Background tasks).')
            : __('Renumbering is already running.'));
    }

    /** What the template makes, without spending a running number. */
    protected function example(Generator $generator, string $template): string
    {
        $sample = new Product(['sku' => '']);
        $sample->id = 1234;

        return preg_replace_callback(Generator::TOKEN, fn (array $m) => match ($m[1]) {
            'n' => str_pad('1', (int) (($m[2] ?? '') ?: 1), '0', STR_PAD_LEFT),
            'ean13' => Generator::ean13('200000000001'),
            'category' => substr('SMARTPHONES', 0, (int) (($m[2] ?? '') ?: 11)),
            'brand' => substr('APPLE', 0, (int) (($m[2] ?? '') ?: 5)),
            default => $generator->render($m[0], $sample),
        }, $template) ?? $template;
    }
}
