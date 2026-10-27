<?php

namespace Torgash\Video;

use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;
use Torgash\Media\ImageProcessor;
use Torgash\Video\Models\Video;
use Torgash\Video\Sources\Sources;

/**
 * Adding, ordering and removing an owner's videos — what the admin screen
 * and, later, an import both go through.
 */
class Videos
{
    public function __construct(protected Sources $sources) {}

    /** @return Collection<int, Video> */
    public function of(string $ownerType, int $ownerId): Collection
    {
        return Video::query()->ownedBy($ownerType, $ownerId)->with('media')->get();
    }

    /**
     * A video from what the admin pasted, with whatever the platform says
     * about it. The platform's silence never stops it: no title is a title
     * the admin types, no poster is a poster the admin uploads.
     *
     * @throws RuntimeException with a sentence for the admin
     */
    public function add(string $ownerType, int $ownerId, string $typed): Video
    {
        ['source' => $source, 'ref' => $ref, 'url' => $url] = $this->sources->resolve($typed);

        $existing = Video::query()->ownedBy($ownerType, $ownerId)->where('source', $source->key())->get();

        if ($existing->contains(fn (Video $video) => $video->ref == $ref)) {
            throw new RuntimeException(__('This video has already been added.'));
        }

        $details = $source->details($ref, $url);

        $video = Video::query()->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'source' => $source->key(),
            'ref' => $ref,
            'url' => mb_substr($url, 0, 1000),
            'title' => ($details['title'] ?? '') !== '' ? put_translation(null, mb_substr((string) $details['title'], 0, 255)) : null,
            'duration' => $details['duration'] ?? null,
            'position' => (int) Video::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->max('position') + 1,
        ]);

        foreach ((array) ($details['poster'] ?? []) as $posterUrl) {
            if ($this->fetchPoster($video, (string) $posterUrl)) {
                break;
            }
        }

        return $video;
    }

    /**
     * A platform's picture, downloaded ONCE and kept: until the visitor
     * presses play, nothing on the page asks the platform for anything.
     */
    public function fetchPoster(Video $video, string $url): bool
    {
        if (! preg_match('#^https://#i', $url)) {
            return false;
        }

        $temp = tempnam(sys_get_temp_dir(), 'vposter');

        try {
            // The poster's address came from a platform's answer, not from the
            // engine: it goes only to the public internet, like a pasted link.
            $response = app(\App\Core\Http\OutboundUrl::class)->get($url, 10);

            if (! $response->successful()) {
                return false;
            }

            file_put_contents($temp, $response->body());

            app(ImageProcessor::class)->addFile($video, $temp, 'poster-'.$video->id, Video::POSTER);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * What rode along with the owner's form: each video's title in the
     * language being edited, and the order the list was dragged into. Only
     * this owner's videos — an id from somebody else's form changes nothing.
     *
     * @param  array<int|string, array{title?: string, position?: int|string}>  $input
     */
    public function applyForm(string $ownerType, int $ownerId, array $input): void
    {
        foreach (Video::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->get() as $video) {
            $row = $input[$video->id] ?? null;

            if (! is_array($row)) {
                continue;
            }

            if (array_key_exists('title', $row)) {
                $video->title = put_translation($video->title, mb_substr((string) $row['title'], 0, 255)) ?: null;
            }

            if (isset($row['position']) && is_numeric($row['position'])) {
                $video->position = (int) $row['position'];
            }

            $video->save();
        }
    }

    /**
     * The exim column: every video as one link, the platform's page or the
     * shop's own file, in the record's order.
     */
    public function links(string $ownerType, int $ownerId): string
    {
        return $this->of($ownerType, $ownerId)
            ->map(fn (Video $video) => $video->isFile() ? $video->playerUrl() : (string) $video->url)
            ->filter()
            ->implode(', ');
    }

    /**
     * An import's cell, as the truth about the record's PLATFORM videos:
     * links not there yet are added (title and poster asked of the platform,
     * as when the admin pastes one), platform videos the cell does not name
     * are removed, and the order is the cell's. The shop's own files are
     * never touched — a link cannot bring a file, and a link to one (what the
     * export wrote) is simply recognised and kept. A link nobody recognises
     * is skipped and returned, never a reason to fail the row.
     *
     * @return list<string> the links that were not understood
     */
    public function syncLinks(string $ownerType, int $ownerId, string $cell): array
    {
        $links = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/u', $cell) ?: []))));
        $existing = $this->of($ownerType, $ownerId);
        $kept = [];
        $unknown = [];

        foreach ($links as $link) {
            $own = $existing->first(fn (Video $video) => $video->isFile() && $video->playerUrl() === $link);

            if ($own) {
                $kept[] = $own->id;

                continue;
            }

            try {
                ['source' => $source, 'ref' => $ref] = $this->sources->resolve($link);
            } catch (RuntimeException) {
                $unknown[] = $link;

                continue;
            }

            $video = $existing->first(fn (Video $video) => $video->source === $source->key() && $video->ref == $ref)
                ?? $this->add($ownerType, $ownerId, $link);
            $kept[] = $video->id;
        }

        foreach ($existing as $video) {
            if (! $video->isFile() && ! in_array($video->id, $kept, true)) {
                $video->delete();
            }
        }

        // The cell's order; files keep their places after the named ones.
        $position = 0;
        $order = [...$kept, ...$existing->filter(fn (Video $video) => $video->isFile() && ! in_array($video->id, $kept, true))->pluck('id')->all()];

        foreach ($order as $id) {
            Video::query()->whereKey($id)->update(['position' => ++$position]);
        }

        return $unknown;
    }

    /** Every video of a record that is gone for good, posters with them. */
    public function forget(string $ownerType, int $ownerId): void
    {
        Video::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->get()->each->delete();
    }
}
