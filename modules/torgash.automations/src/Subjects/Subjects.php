<?php

namespace Torgash\Automations\Subjects;

/**
 * The registry of what a rule can be about: key => Subject class.
 *
 * Answered lazily through `automations.subjects`, because provider boot order
 * is not ours to depend on; resolved from the container per call, never kept in
 * a static, so a persistent worker never serves last request's instances.
 */
class Subjects
{
    /** @return array<string, Subject> */
    public static function all(): array
    {
        $classes = hook_filter('automations.subjects', ['order' => OrderSubject::class]);

        $subjects = [];
        foreach ($classes as $key => $class) {
            if (is_string($class) && class_exists($class)) {
                $subjects[$key] = app($class);
            }
        }

        return $subjects;
    }

    public static function get(string $key): ?Subject
    {
        return self::all()[$key] ?? null;
    }

    /** Which subject announces this event — 'order' for anything unknown. */
    public static function keyFor(string $event): string
    {
        foreach (self::all() as $key => $subject) {
            if (isset($subject->triggers()[$event])) {
                return $key;
            }
        }

        return 'order';
    }

    public static function for(string $event): ?Subject
    {
        return self::get(self::keyFor($event));
    }
}
