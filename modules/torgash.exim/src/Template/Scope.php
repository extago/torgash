<?php

namespace Torgash\Exim\Template;

/**
 * One rendering's state (PLAN §68): the frames a name is looked up in
 * (innermost first — a loop's variable, a Mustache section's element, the
 * context at the bottom), the variables `{% assign %}` made, and the budget
 * — how many steps and how much output one unit of work (one row of a feed)
 * may take before the template is told, in words, that it did too much.
 */
final class Scope
{
    /** @var list<mixed> outermost first */
    public array $frames;

    /** @var array<string, mixed> made by assign / capture; they outlive a row */
    public array $vars = [];

    public int $steps = 0;

    public int $written = 0;

    public function __construct(array $frames, public readonly string $escape)
    {
        $this->frames = $frames;
    }

    /** A new unit of work: the counters start again, the variables stay. */
    public function fresh(): void
    {
        $this->steps = 0;
        $this->written = 0;
    }

    /**
     * A path's value: `.` is the innermost element; the first name is looked
     * for in the frames from the innermost out (never the bottom one), then
     * in the variables, then in the context at the bottom.
     */
    public function lookup(string $path): mixed
    {
        if ($path === '.') {
            return end($this->frames);
        }

        $segments = explode('.', $path);
        $head = $segments[0];
        $last = count($this->frames) - 1;

        for ($i = $last; $i >= 1; $i--) {
            if (is_array($this->frames[$i]) && array_key_exists($head, $this->frames[$i])) {
                return self::walk($this->frames[$i], $segments);
            }
        }

        if (array_key_exists($head, $this->vars)) {
            return self::walk([$head => $this->vars[$head]], $segments);
        }

        if (is_array($this->frames[0] ?? null) && array_key_exists($head, $this->frames[0])) {
            return self::walk($this->frames[0], $segments);
        }

        return null;
    }

    protected static function walk(array $value, array $segments): mixed
    {
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function push(mixed $frame): void
    {
        $this->frames[] = $frame;
    }

    public function pop(): void
    {
        array_pop($this->frames);
    }
}
