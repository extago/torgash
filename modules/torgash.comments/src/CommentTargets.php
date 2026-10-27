<?php

namespace Torgash\Comments;

/**
 * What can be commented on.
 *
 * A module registers its own entity — a page, a product — and this module
 * never learns their names. Registration is answered LAZILY through the
 * `comments.targets` hook, because provider boot order is not ours to depend
 * on: the same rule the API resource registry follows.
 *
 * A target declares three things and no more:
 *   - `label`: what the admin's moderation list calls it;
 *   - `resolve`: id → the model, or null if it is gone;
 *   - `url`/`title`: how to link to it and what to call it in the list.
 *
 * Everything else — moderation, guarding, rendering — is this module's, so a
 * module adding comments to its entity writes six lines and no screens.
 */
class CommentTargets
{
    /** @var array<string, array<string, mixed>> */
    protected static array $targets = [];

    protected static bool $announced = false;

    /** @param array<string, mixed> $definition */
    public static function register(string $type, array $definition): void
    {
        static::$targets[$type] = $definition + ['label' => ['en' => $type]];
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        static::announce();

        return static::$targets;
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, static::all());
    }

    /** @return array<string, mixed>|null */
    public static function find(string $type): ?array
    {
        return static::all()[$type] ?? null;
    }

    /** The commented-on thing itself, or null when it no longer exists. */
    public static function resolve(string $type, int $id): mixed
    {
        $target = static::find($type);

        return $target && isset($target['resolve']) ? ($target['resolve'])($id) : null;
    }

    /** Its address, for the moderation list — null when there is nothing to link to. */
    public static function url(string $type, int $id): ?string
    {
        $model = static::resolve($type, $id);
        $target = static::find($type);

        return $model && isset($target['url']) ? ($target['url'])($model) : null;
    }

    /**
     * Title and address together, from ONE lookup of the record — what the
     * moderation list asks per distinct target instead of calling title() and
     * url(), which resolved it twice.
     *
     * @return array{title: string, url: ?string}
     */
    public static function describe(string $type, int $id): array
    {
        $model = static::resolve($type, $id);
        $target = static::find($type);

        return [
            'title' => $model && isset($target['title']) ? (string) ($target['title'])($model) : $type.' #'.$id,
            'url' => $model && isset($target['url']) ? ($target['url'])($model) : null,
        ];
    }

    /** Its name, for the moderation list. Falls back to the bare address. */
    public static function title(string $type, int $id): string
    {
        $model = static::resolve($type, $id);
        $target = static::find($type);

        if ($model && isset($target['title'])) {
            return (string) ($target['title'])($model);
        }

        return $type.' #'.$id;
    }

    /** For tests, which register throwaway targets and must not leak them. */
    public static function flush(): void
    {
        static::$targets = [];
        static::$announced = false;
    }

    protected static function announce(): void
    {
        if (static::$announced) {
            return;
        }

        // Set BEFORE the hook fires: a listener that asks a question of this
        // registry while registering would otherwise announce again, for ever.
        static::$announced = true;

        hook_action('comments.targets');
    }
}
