<?php

namespace Torgash\Attachments\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Torgash\Attachments\Documents;
use Torgash\Attachments\Models\Attachment;
use Torgash\Catalog\Models\Product;

/**
 * The document list on a product's form. JSON in, JSON out, the way the
 * gallery and the videos beside it work: the list sits INSIDE the product's
 * form, so it has no form of its own (a nested form leaks its inputs into the
 * outer submit), and titles, who sees each and the order ride along with the
 * product's Save as documents[<id>][…].
 */
class DocumentsController extends Controller
{
    public function __construct(protected Documents $documents) {}

    public function index(int $id): JsonResponse
    {
        $this->product($id);

        return $this->answer($id);
    }

    public function upload(Request $request, int $id): JsonResponse
    {
        $this->product($id);
        $errors = [];

        $files = $request->file('files', []);
        $files = is_array($files) ? $files : [$files];

        if ($files === []) {
            $errors[] = __('The file did not arrive. It may be bigger than the server accepts.');
        }

        foreach ($files as $file) {
            try {
                $this->documents->upload('product', $id, $file);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $this->answer($id, $errors);
    }

    public function link(Request $request, int $id): JsonResponse
    {
        $this->product($id);
        $errors = [];

        try {
            $this->documents->link('product', $id, (string) $request->input('url', ''));
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        return $this->answer($id, $errors);
    }

    public function destroy(int $id, int $attachment): JsonResponse
    {
        $this->product($id);

        Attachment::query()->ownedBy('product', $id)->whereKey($attachment)->first()?->delete();

        return $this->answer($id);
    }

    /**
     * The rows as the editor draws them. The title is the form's CONTENT
     * language only — what Save writes back — with the other words as the
     * placeholder.
     *
     * @param  Collection<int, Attachment>  $attachments
     * @return list<array<string, mixed>>
     */
    public static function items(Collection $attachments): array
    {
        $locale = admin_content_locale();

        return $attachments->map(fn (Attachment $a) => [
            'id' => $a->id,
            'title' => (string) (($a->title ?? [])[$locale] ?? ''),
            'hint' => $a->label(),
            'access' => $a->access,
            'link' => $a->isLink(),
            'icon' => $a->icon(),
            'details' => $a->details(),
            'name' => $a->original_name ?: $a->url,
            'href' => $a->href(),
            'downloads' => $a->downloads,
        ])->values()->all();
    }

    /** @param  list<string>  $errors */
    protected function answer(int $id, array $errors = []): JsonResponse
    {
        return response()->json([
            'items' => self::items($this->documents->of('product', $id)),
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    protected function product(int $id): Product
    {
        Gate::authorize('catalog.manage');

        return Product::withTrashed()->findOrFail($id);
    }
}
