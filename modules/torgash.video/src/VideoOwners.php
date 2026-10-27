<?php

namespace Torgash\Video;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Which records may have videos, by the same kind of key torgash.media uses
 * (`product`), and who may change them. The product registers itself from
 * this module's provider; an article or a page answers `video.owners` from
 * its own module, so this one never names it.
 *
 * Such a module also says where the videos plug into it — `$seams`, the
 * names of ITS hooks:
 *
 *   form     a filter its edit form prints (`['record' => …]`), answered with
 *            the video list — inside the form, so titles and order ride along;
 *   saved    an action it fires after saving (`['record' => …, 'request' => …]`);
 *   context  the key its text's placeholder context holds the record under
 *            (`post`, `page`): `{video:ID}` in that text plays one of the
 *            record's own videos.
 *
 * The provider wires them once the application has booted (`all()`), when
 * every module has had its say.
 */
class VideoOwners
{
    /** @var array<string, array{model: class-string<Model>, ability: string, seams: array<string, string>}> */
    protected array $owners = [];

    protected bool $asked = false;

    /** @param  array{form?: string, saved?: string, context?: string}  $seams */
    public function register(string $key, string $model, string $ability, array $seams = []): void
    {
        $this->owners[$key] = compact('model', 'ability', 'seams');
    }

    /** @return array<string, array{model: class-string<Model>, ability: string, seams: array<string, string>}> */
    public function all(): array
    {
        $this->ask();

        return $this->owners;
    }

    /**
     * The owner a placeholder context is about — the record under a key some
     * owner declared as its `context` — or null.
     *
     * @return array{0: string, 1: Model}|null
     */
    public function fromContext(array $context): ?array
    {
        foreach ($this->all() as $key => $owner) {
            $record = isset($owner['seams']['context']) ? ($context[$owner['seams']['context']] ?? null) : null;

            if ($record instanceof $owner['model'] && $record->exists) {
                return [$key, $record];
            }
        }

        return null;
    }

    public function has(string $key): bool
    {
        $this->ask();

        return isset($this->owners[$key]);
    }

    /** @return array{model: class-string<Model>, ability: string} */
    public function definition(string $key): array
    {
        $this->ask();

        return $this->owners[$key] ?? throw new RuntimeException("Unknown video owner [{$key}].");
    }

    /** Resolves the owning record, aborting the request the way route binding would. */
    public function find(string $key, int|string $id): Model
    {
        $model = $this->definition($key)['model'];

        return $model::query()->findOrFail($id);
    }

    protected function ask(): void
    {
        if (! $this->asked) {
            $this->asked = true;
            hook_action('video.owners', ['owners' => $this]);
        }
    }
}
