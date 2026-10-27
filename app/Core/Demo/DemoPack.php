<?php

namespace App\Core\Demo;

/**
 * What a demo part reads (DemoData): the pack's JSON files and pictures.
 * A missing file is an empty answer, never an error — a pack without
 * pictures still installs, and a part asks only for what it knows.
 */
class DemoPack
{
    /** @param  (callable(string): void)|null  $say */
    public function __construct(protected string $root, protected $say = null) {}

    /** `database/demo/<name>.json` as an array; [] when there is none. */
    public function json(string $name): array
    {
        $file = $this->root.'/'.$name.'.json';

        if (! is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            throw new \RuntimeException("Demo pack: {$name}.json is not valid JSON.");
        }

        return $data;
    }

    /** A picture's path when the owner has put it in `images/`, else null. */
    public function image(?string $file): ?string
    {
        if ($file === null || $file === '' || str_contains($file, '..')) {
            return null;
        }

        $path = $this->root.'/images/'.$file;

        return is_file($path) ? $path : null;
    }

    public function say(string $line): void
    {
        if ($this->say) {
            ($this->say)($line);
        }
    }
}
