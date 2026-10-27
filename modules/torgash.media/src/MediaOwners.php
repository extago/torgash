<?php

namespace Torgash\Media;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;

/**
 * Registry of entities that own images. A module registers its own models here
 * (see the catalog module's provider) instead of this module knowing anything
 * about products, categories, brands or pages.
 *
 * An owner declares two things, and they are independent: a GALLERY (ordered,
 * captioned photographs OF the thing — the default) and whether its text may
 * carry images INSIDE it (`content: true`, see `Content`). A product has both;
 * a page has only the second; an enquiry's attachments are neither and go
 * through their own collection with their own private disk. A gallery may
 * also be declared `single` — one picture, a cover (see isSingle()).
 *
 * One key per entity rather than one per collection, because the key is what
 * the URL and the ability are addressed by: `/admin/media/product/17/...` is
 * "the images of product 17", and who may touch them is one answer, not two.
 *
 * Two ways in. A module that REQUIRES this one calls register() from its
 * provider (the catalogue does). A module that merely works better with it —
 * pages, whose text may carry pictures when this module is installed and
 * simply has no picture button when it is not — answers `media.owners`
 * instead, asked lazily the first time the registry is read, with this
 * registry in the context; so it never names this module's classes, and
 * provider boot order does not matter (the `api.resources` trick).
 */
class MediaOwners
{
    /** @var array<string, array{model: class-string<Model&HasMedia>, ability: string, collection: string, content: bool, single: bool}> */
    protected array $owners = [];

    protected bool $asked = false;

    /** Collect the owners that register through the hook — once, on first read. */
    protected function ask(): void
    {
        if (! $this->asked) {
            $this->asked = true;
            hook_action('media.owners', ['owners' => $this]);
        }
    }

    public function register(
        string $key,
        string $model,
        string $ability,
        string $collection = 'gallery',
        bool $content = false,
        bool $single = false,
    ): void {
        $this->owners[$key] = compact('model', 'ability', 'collection', 'content', 'single');
    }

    public function has(string $key): bool
    {
        $this->ask();

        return isset($this->owners[$key]);
    }

    /** @return array{model: class-string<Model&HasMedia>, ability: string, collection: string, content: bool, single: bool} */
    public function definition(string $key): array
    {
        $this->ask();

        return $this->owners[$key] ?? throw new RuntimeException("Unknown media owner [{$key}].");
    }

    /**
     * Is the gallery ONE picture — a cover — rather than a set of photos?
     * Then an upload REPLACES what is there (only once the new file is safely
     * stored, so a refused upload keeps the old one), and the editor offers
     * no order, no "main" and no second file: a blog article showed only its
     * first picture, and a loader that took twenty let a person believe the
     * other nineteen went somewhere.
     */
    public function isSingle(string $key): bool
    {
        $this->ask();

        return (bool) ($this->owners[$key]['single'] ?? false);
    }

    /** Does this owner keep images inside its text as well as beside it? */
    public function hasContent(string $key): bool
    {
        $this->ask();

        return (bool) ($this->owners[$key]['content'] ?? false);
    }

    /**
     * The key for a record whose text may carry images — which is how the rich
     * editor finds out where to upload, from the record alone.
     *
     * Asking the registry rather than making every form repeat its own owner
     * key beside the model it already has: two statements of the same fact are
     * one chance to write `page` next to a product. Exact class first, then
     * instanceof, so a model somebody extended still resolves.
     */
    public function contentKeyFor(Model $record): ?string
    {
        $this->ask();

        foreach ($this->owners as $key => $owner) {
            if ($owner['content'] && $record::class === $owner['model']) {
                return $key;
            }
        }

        foreach ($this->owners as $key => $owner) {
            if ($owner['content'] && $record instanceof $owner['model']) {
                return $key;
            }
        }

        return null;
    }

    /** Resolves the owning record, aborting the request the same way route binding would. */
    public function find(string $key, int|string $id): Model&HasMedia
    {
        $model = $this->definition($key)['model'];

        return $model::query()->findOrFail($id);
    }
}
