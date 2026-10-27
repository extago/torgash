<?php

namespace Torgash\Video\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Torgash\Media\ImageProcessor;
use Torgash\Video\Ffmpeg;
use Torgash\Video\Jobs\PrepareVideoFile;
use Torgash\Video\Models\Video;
use Torgash\Video\Uploads;
use Torgash\Video\VideoOwners;
use Torgash\Video\Videos;

/**
 * The video list on a record's form. JSON in, JSON out, the way the gallery
 * beside it works: the list sits INSIDE the owner's form, so it has no form
 * of its own (a nested form leaks its inputs into the outer submit), and
 * titles and order ride along with the owner's Save.
 */
class VideosController extends Controller
{
    public function __construct(protected VideoOwners $owners, protected Videos $videos) {}

    public function index(string $owner, int $id): JsonResponse
    {
        $this->authorizeOwner($owner, $id);

        return $this->answer($owner, $id);
    }

    public function store(Request $request, string $owner, int $id): JsonResponse
    {
        $this->authorizeOwner($owner, $id);

        $typed = (string) $request->input('video', '');
        $errors = [];

        try {
            $video = $this->videos->add($owner, $id, mb_substr($typed, 0, 5000));

            if (! $video->poster()) {
                $errors[] = __(':platform did not give a poster for this video. Upload one, or the visitor sees a plain frame with a play button.', ['platform' => $video->platform()]);
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        return $this->answer($owner, $id, $errors);
    }

    public function poster(Request $request, string $owner, int $id, int $video): JsonResponse
    {
        $this->authorizeOwner($owner, $id);
        $model = $this->video($owner, $id, $video);
        $errors = [];

        $file = $request->file('poster');

        if (! $file || ! $file->isValid()) {
            $errors[] = __('The file did not arrive. It may be bigger than the server accepts.');
        } else {
            try {
                // A single-file collection: the old poster goes once the new one is stored.
                app(ImageProcessor::class)->addUpload($model, $file, Video::POSTER);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $this->answer($owner, $id, $errors);
    }

    /** A file of the shop's own begins: what it is, how big, and how big a chunk may be. */
    public function uploadStart(Request $request, string $owner, int $id, Uploads $uploads): JsonResponse
    {
        $this->authorizeOwner($owner, $id);

        try {
            return response()->json($uploads->start($owner, $id, (string) $request->input('name', ''), (int) $request->input('size', 0), auth()->id()));
        } catch (RuntimeException $e) {
            return response()->json(['errors' => [$e->getMessage()]], 422);
        }
    }

    /** One chunk as the raw body; `X-Upload-Offset` says where it belongs. */
    public function uploadChunk(Request $request, string $owner, int $id, string $token, Uploads $uploads): JsonResponse
    {
        $this->authorizeOwner($owner, $id);

        try {
            $received = $uploads->append($token, $owner, $id, auth()->id(), (int) $request->header('X-Upload-Offset', '0'), $request->getContent(true));

            return response()->json(['received' => $received]);
        } catch (RuntimeException $e) {
            return response()->json(['errors' => [$e->getMessage()]], 422);
        }
    }

    public function uploadFinish(string $owner, int $id, string $token, Uploads $uploads): JsonResponse
    {
        $this->authorizeOwner($owner, $id);

        try {
            $video = $uploads->finish($token, $owner, $id, auth()->id());
        } catch (RuntimeException $e) {
            return $this->answer($owner, $id, [$e->getMessage()]);
        }

        // With ffmpeg the poster, the length and a file every browser plays
        // come by themselves, in the background; without it the admin is
        // asked for a poster and the file plays as it came.
        if (app(Ffmpeg::class)->available()) {
            PrepareVideoFile::for($video);

            return $this->answer($owner, $id);
        }

        return $this->answer($owner, $id, [__('Upload a poster for this video — until then the visitor sees a plain frame with a play button.')]);
    }

    /** "Prepare for the web" again: a file from before ffmpeg was found, or one it failed on. */
    public function prepare(string $owner, int $id, int $video): JsonResponse
    {
        $this->authorizeOwner($owner, $id);
        $model = $this->video($owner, $id, $video);

        if (! $model->isFile()) {
            abort(404);
        }

        if (! app(Ffmpeg::class)->available()) {
            return $this->answer($owner, $id, [__('ffmpeg was not found on the server. Name where it is in the Video module\'s settings.')]);
        }

        if (! $model->preparing()) {
            PrepareVideoFile::for($model);
        }

        return $this->answer($owner, $id);
    }

    public function uploadCancel(string $owner, int $id, string $token, Uploads $uploads): JsonResponse
    {
        $this->authorizeOwner($owner, $id);
        $uploads->discard($token);

        return response()->json(['ok' => true]);
    }

    public function destroy(string $owner, int $id, int $video): JsonResponse
    {
        $this->authorizeOwner($owner, $id);
        $this->video($owner, $id, $video)->delete();

        return $this->answer($owner, $id);
    }

    protected function authorizeOwner(string $owner, int $id): void
    {
        abort_unless($this->owners->has($owner), 404);
        Gate::authorize($this->owners->definition($owner)['ability']);
        $this->owners->find($owner, $id);
    }

    protected function video(string $owner, int $id, int $video): Video
    {
        return Video::query()->where('owner_type', $owner)->where('owner_id', $id)->findOrFail($video);
    }

    /** @param list<string> $errors */
    protected function answer(string $owner, int $id, array $errors = []): JsonResponse
    {
        return response()->json([
            'items' => self::items($this->videos->of($owner, $id)),
            'errors' => $errors,
        ]);
    }

    /**
     * What the list draws: the title in the language being edited, and the
     * other languages' as a grey placeholder — the way every translatable
     * field on these forms behaves.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(iterable $videos): array
    {
        $locale = admin_content_locale();
        $items = [];

        foreach ($videos as $video) {
            $items[] = [
                'id' => $video->id,
                'platform' => $video->platform(),
                'url' => $video->url,
                // Where the admin's click goes: the platform's page, or the file itself.
                'href' => $video->isFile() ? $video->playerUrl() : $video->url,
                'file' => $video->isFile(),
                'title' => (string) (($video->title ?? [])[$locale] ?? ''),
                'title_hint' => (string) pick_translation($video->title, $locale),
                'poster' => $video->posterUrl(400),
                'duration' => $video->duration ? gmdate($video->duration >= 3600 ? 'G:i:s' : 'i:s', $video->duration) : null,
                'missing' => $video->source() === null,
                // ffmpeg's work on a file of the shop's own: queued / working / failed, or done.
                'state' => $video->isFile() ? ($video->ref['state'] ?? null) : null,
                'error' => $video->isFile() ? ($video->ref['error'] ?? null) : null,
                'prepared' => $video->isFile() && isset($video->ref['width']),
                'size' => $video->isFile() && isset($video->ref['width']) ? $video->ref['width'].'×'.$video->ref['height'] : null,
            ];
        }

        return $items;
    }
}
