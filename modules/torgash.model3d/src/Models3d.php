<?php

namespace Torgash\Model3d;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Torgash\Model3d\Models\Model3d;

/**
 * The one place a 3D model is written, listed and thrown away.
 *
 * Only binary glTF (.glb): one file that carries its own textures, which is
 * what a viewer fetching one address needs (a .gltf names other files beside
 * it). It is taken by what the BYTES are — a .glb begins with the magic
 * `glTF` and the container version 2 — never by its name, and stored under a
 * name of ours with the one extension we chose. Nothing in a .glb runs: it is
 * geometry and pictures, read by the viewer's own code.
 */
class Models3d
{
    /** @return Collection<int, Model3d> */
    public function of(string $ownerType, int $ownerId): Collection
    {
        return Model3d::query()->ownedBy($ownerType, $ownerId)->with('media')->get();
    }

    public function maxBytes(): int
    {
        return max(1, (int) setting('torgash.model3d', 'max_mb', 30)) * 1048576;
    }

    public function upload(string $ownerType, int $ownerId, UploadedFile $file): Model3d
    {
        if (! $file->isValid()) {
            throw new RuntimeException(__('The file did not arrive. It may be bigger than the server accepts.'));
        }

        $name = Str::limit(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 180, '');

        if ($file->getSize() > $this->maxBytes()) {
            throw new RuntimeException(__(':name: the shop takes models up to :max MB.', ['name' => $name, 'max' => (int) ($this->maxBytes() / 1048576)]));
        }

        if (! self::isGlb((string) $file->getRealPath())) {
            throw new RuntimeException(__(':name is not a .glb model. Export the model as binary glTF (.glb) — one file with its textures inside.', ['name' => $name]));
        }

        $path = $file->storeAs(Model3d::DIRECTORY.'/'.$ownerType.'/'.$ownerId, Str::random(32).'.glb', ['disk' => Model3d::DISK]);

        if (! $path) {
            throw new RuntimeException(__('The file could not be saved.'));
        }

        return Model3d::create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'path' => $path,
            'original_name' => $name,
            'size' => (int) $file->getSize(),
            'position' => (int) Model3d::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->max('position') + 1,
        ]);
    }

    /**
     * Binary glTF's own header: `glTF`, version 2, and the length it declares
     * being the length there is — a renamed file of anything else fails the
     * first, a truncated upload the last.
     */
    public static function isGlb(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if (! $handle) {
            return false;
        }

        $header = (string) fread($handle, 12);
        fclose($handle);

        if (strlen($header) < 12 || substr($header, 0, 4) !== 'glTF') {
            return false;
        }

        $fields = unpack('Vversion/Vlength', substr($header, 4, 8));

        return $fields['version'] === 2 && $fields['length'] === filesize($path);
    }

    /**
     * Titles and order as the owner's form posted them — a title in the
     * form's content language only, every other language kept.
     *
     * @param  array<int|string, mixed>  $input  models3d[<id>][title|position]
     */
    public function applyForm(string $ownerType, int $ownerId, array $input): void
    {
        foreach (Model3d::query()->ownedBy($ownerType, $ownerId)->get() as $model) {
            $row = $input[$model->id] ?? null;

            if (! is_array($row)) {
                continue;
            }

            if (array_key_exists('title', $row)) {
                $model->title = put_translation($model->title, mb_substr((string) $row['title'], 0, 255)) ?: null;
            }

            if (isset($row['position']) && is_numeric($row['position'])) {
                $model->position = (int) $row['position'];
            }

            $model->save();
        }
    }

    public function forget(string $ownerType, int $ownerId): void
    {
        Model3d::query()->ownedBy($ownerType, $ownerId)->get()->each->delete();
    }
}
