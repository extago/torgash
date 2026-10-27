<?php

namespace Torgash\Media\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Torgash\Media\Gallery;
use Torgash\Media\ImageProcessor;
use Torgash\Media\ImageSettings;
use Torgash\Media\MediaOwners;

/**
 * JSON endpoints behind the gallery editor. Every mutation answers with the
 * full, freshly-read item list so the client never has to guess what the
 * server ended up with.
 */
class GalleryController extends Controller
{
    public function __construct(
        protected MediaOwners $owners,
        protected ImageProcessor $processor,
    ) {}

    public function index(string $owner, int|string $id): JsonResponse
    {
        return $this->items($this->authorized($owner, $id), $owner);
    }

    public function upload(Request $request, string $owner, int|string $id): JsonResponse
    {
        $model = $this->authorized($owner, $id);
        $errors = [];

        foreach ($this->limit($owner, $request->file('images', [])) as $file) {
            try {
                $this->keepOnly($model, $owner, $this->processor->addUpload($model, $file, $this->collection($owner)));
            } catch (Throwable $e) {
                $errors[] = $file->getClientOriginalName().': '.$e->getMessage();
            }
        }

        return $this->items($model, $owner, $errors);
    }

    public function fromUrl(Request $request, string $owner, int|string $id): JsonResponse
    {
        $model = $this->authorized($owner, $id);
        $errors = [];

        foreach ($this->limit($owner, $this->urlLines($request->input('urls', ''))) as $url) {
            try {
                $this->keepOnly($model, $owner, $this->processor->addFromUrl($model, $url, $this->collection($owner)));
            } catch (Throwable $e) {
                $errors[] = $url.': '.$e->getMessage();
            }
        }

        return $this->items($model, $owner, $errors);
    }

    public function rotate(Request $request, string $owner, int|string $id, int $media): JsonResponse
    {
        $model = $this->authorized($owner, $id);
        $degrees = (int) $request->input('degrees', 90);
        $errors = [];

        try {
            $this->processor->rotate($this->ownedMedia($model, $owner, $media), $degrees);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return $this->items($model, $owner, $errors);
    }

    public function destroy(string $owner, int|string $id, int $media): JsonResponse
    {
        $model = $this->authorized($owner, $id);
        $this->ownedMedia($model, $owner, $media)->delete();

        return $this->items($model, $owner);
    }

    public function destroyAll(string $owner, int|string $id): JsonResponse
    {
        $model = $this->authorized($owner, $id);
        $model->clearMediaCollection($this->collection($owner));

        return $this->items($model, $owner);
    }

    protected function authorized(string $owner, int|string $id): Model&HasMedia
    {
        abort_unless($this->owners->has($owner), 404);
        Gate::authorize($this->owners->definition($owner)['ability']);

        return $this->owners->find($owner, $id);
    }

    protected function collection(string $owner): string
    {
        return $this->owners->definition($owner)['collection'];
    }

    /** 404s rather than silently no-oping when an id belongs to another record. */
    protected function ownedMedia(Model&HasMedia $model, string $owner, int $media)
    {
        return $model->getMedia($this->collection($owner))->firstWhere('id', $media)
            ?? abort(404);
    }

    /** A single-picture owner takes the first file of a batch and no more. */
    protected function limit(string $owner, array $incoming): array
    {
        return $this->owners->isSingle($owner) ? array_slice(array_values($incoming), 0, 1) : $incoming;
    }

    /**
     * For a single-picture owner the new file REPLACES the old one — removed
     * only after the new one is stored, so a refused upload (too large, not
     * an image) leaves the cover the record had.
     */
    protected function keepOnly(Model&HasMedia $model, string $owner, Media $added): void
    {
        if (! $this->owners->isSingle($owner)) {
            return;
        }

        $model->unsetRelation('media');
        $model->getMedia($this->collection($owner))
            ->reject(fn (Media $media) => $media->is($added))
            ->each(fn (Media $media) => $media->delete());
    }

    /** @return list<string> */
    protected function urlLines(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw) ?: [])));
    }

    protected function items(Model&HasMedia $model, string $owner, array $errors = []): JsonResponse
    {
        // Uploads, deletes and conversion rebuilds all happen after the media
        // relation was first read, so re-read it rather than answering with
        // the stale in-memory copy.
        $model->unsetRelation('media');

        $items = $model->getMedia($this->collection($owner))
            ->map(fn ($media) => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                'thumb' => Gallery::thumbUrl($media, 240),
                'name' => $media->file_name,
                'alt' => Gallery::alt($media),
                'title' => Gallery::title($media),
                'size' => $media->size,
            ] + Gallery::dimensions($media))
            ->values();

        return response()->json([
            'items' => $items,
            'errors' => $errors,
            'allow_remote' => ImageSettings::allowRemote(),
        ], $errors ? 422 : 200);
    }
}
