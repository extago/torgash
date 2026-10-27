<?php

namespace Torgash\Model3d\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Torgash\Catalog\Models\Product;
use Torgash\Media\ImageProcessor;
use Torgash\Model3d\Models\Model3d;
use Torgash\Model3d\Models3d;

/**
 * The 3D models on a product's form. JSON in, JSON out, like the videos
 * beside it: no form of its own inside the product's form, titles and order
 * ride along with the product's Save as models3d[<id>][…].
 */
class ModelsController extends Controller
{
    public function __construct(protected Models3d $models) {}

    public function index(int $id): JsonResponse
    {
        $this->product($id);

        return $this->answer($id);
    }

    public function upload(Request $request, int $id): JsonResponse
    {
        $this->product($id);
        $errors = [];
        $added = null;

        $file = $request->file('model');

        if (! $file) {
            $errors[] = __('The file did not arrive. It may be bigger than the server accepts.');
        } else {
            try {
                $added = $this->models->upload('product', $id, $file)->id;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $this->answer($id, $errors, $added);
    }

    /**
     * A poster: a picture uploaded by hand, or the view the admin turned the
     * model to, sent by the editor as a picture of the viewer's canvas.
     * Either goes through ImageProcessor like any picture of the shop's.
     */
    public function poster(Request $request, int $id, int $model): JsonResponse
    {
        $this->product($id);
        $row = Model3d::query()->ownedBy('product', $id)->whereKey($model)->firstOrFail();
        $errors = [];

        $file = $request->file('poster');

        if (! $file || ! $file->isValid()) {
            $errors[] = __('The file did not arrive. It may be bigger than the server accepts.');
        } else {
            try {
                app(ImageProcessor::class)->addUpload($row, $file, Model3d::POSTER);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $this->answer($id, $errors);
    }

    public function destroy(int $id, int $model): JsonResponse
    {
        $this->product($id);

        Model3d::query()->ownedBy('product', $id)->whereKey($model)->first()?->delete();

        return $this->answer($id);
    }

    /**
     * @param  Collection<int, Model3d>  $models
     * @return list<array<string, mixed>>
     */
    public static function items(Collection $models): array
    {
        $locale = admin_content_locale();

        return $models->map(fn (Model3d $m) => [
            'id' => $m->id,
            'title' => (string) (($m->title ?? [])[$locale] ?? ''),
            'hint' => (string) ($m->t('title') ?: pathinfo((string) $m->original_name, PATHINFO_FILENAME)),
            'name' => (string) $m->original_name,
            'size' => $m->size >= 1048576 ? round($m->size / 1048576, 1).' '.__('MB') : (int) round($m->size / 1024).' '.__('KB'),
            'src' => $m->url(),
            'poster' => $m->posterUrl(400),
        ])->values()->all();
    }

    /** @param  list<string>  $errors */
    protected function answer(int $id, array $errors = [], ?int $added = null): JsonResponse
    {
        return response()->json([
            'items' => self::items($this->models->of('product', $id)),
            'errors' => $errors,
            'added' => $added,
        ], $errors === [] ? 200 : 422);
    }

    protected function product(int $id): Product
    {
        Gate::authorize('catalog.manage');

        return Product::withTrashed()->findOrFail($id);
    }
}
