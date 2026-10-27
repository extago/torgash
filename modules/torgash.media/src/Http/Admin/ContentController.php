<?php

namespace Torgash\Media\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\HasMedia;
use Throwable;
use Torgash\Media\Content;
use Torgash\Media\ImageProcessor;
use Torgash\Media\ImageSettings;
use Torgash\Media\MediaOwners;

/**
 * The images inside one record's text: list, add, add by link, remove.
 *
 * Four endpoints and no fifth. There is no "update" here and that is the
 * design, not an omission — alt text, size and position are attributes of the
 * `<img>` in the HTML and travel with the record's own form. This controller
 * only ever answers with the record's full, freshly-read list, exactly like the
 * gallery's does, so the panel never has to guess what the server ended up with.
 *
 * Removing is deliberately available and deliberately manual. An image dropped
 * out of the text is not necessarily rubbish — it may be coming back next week
 * — so nothing is detached automatically; the panel marks what the text no
 * longer mentions and a person decides. (The alternative, sweeping on save, is
 * how somebody loses a diagram they had temporarily cut out.)
 */
class ContentController extends Controller
{
    public function __construct(
        protected MediaOwners $owners,
        protected ImageProcessor $processor,
    ) {}

    public function index(string $owner, int|string $id): JsonResponse
    {
        return $this->items($this->authorized($owner, $id));
    }

    public function upload(Request $request, string $owner, int|string $id): JsonResponse
    {
        $model = $this->authorized($owner, $id);
        $errors = [];

        foreach ($request->file('images', []) as $file) {
            try {
                $this->processor->addUpload($model, $file, Content::COLLECTION);
            } catch (Throwable $e) {
                // Per file, so four good images and one 30 MB one is four
                // images and a sentence, not five refusals.
                $errors[] = $file->getClientOriginalName().': '.$e->getMessage();
            }
        }

        return $this->items($model, $errors);
    }

    public function fromUrl(Request $request, string $owner, int|string $id): JsonResponse
    {
        $model = $this->authorized($owner, $id);
        $errors = [];

        try {
            $this->processor->addFromUrl($model, trim((string) $request->input('url', '')), Content::COLLECTION);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return $this->items($model, $errors);
    }

    public function destroy(string $owner, int|string $id, int $media): JsonResponse
    {
        $model = $this->authorized($owner, $id);

        // 404s rather than silently no-oping when the id belongs to another
        // record — or to that record's GALLERY, which this endpoint has no
        // business deleting from.
        $found = $model->getMedia(Content::COLLECTION)->firstWhere('id', $media) ?? abort(404);
        $found->delete();

        return $this->items($model);
    }

    protected function authorized(string $owner, int|string $id): Model&HasMedia
    {
        abort_unless($this->owners->has($owner) && $this->owners->hasContent($owner), 404);
        Gate::authorize($this->owners->definition($owner)['ability']);

        return $this->owners->find($owner, $id);
    }

    protected function items(Model&HasMedia $model, array $errors = []): JsonResponse
    {
        // Uploads and deletes happen after the media relation was first read,
        // so re-read it rather than answering with the stale in-memory copy.
        $model->unsetRelation('media');

        return response()->json([
            'items' => $model->getMedia(Content::COLLECTION)
                ->map(fn ($media) => Content::describe($media))
                ->values(),
            'errors' => $errors,
            'allow_remote' => ImageSettings::allowRemote(),
        ], $errors ? 422 : 200);
    }
}
