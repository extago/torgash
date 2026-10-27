<?php

namespace Torgash\Guard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Torgash\Guard\Errors\Origin;

/**
 * One kind of failure, however many times it has happened.
 *
 * @property string $fingerprint
 * @property string $level
 * @property int $count
 * @property array|null $context
 */
class ErrorGroup extends Model
{
    protected $table = 'm_guard_errors';

    protected $guarded = [];

    /** @var array{file: ?string, line: ?int}|null  a memo, never a column */
    protected ?array $originMemo = null;

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Error';
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** What the row is called on a list: the class where there is one. */
    public function title(): string
    {
        return $this->class ? class_basename($this->class) : ucfirst($this->level);
    }

    /**
     * Where in the shop's own code — worked out again for a row recorded
     * before the recorder knew (its file is then a framework line), from the
     * template the message names or the first of our frames in its trace.
     *
     * @return array{file: ?string, line: ?int}
     */
    public function origin(): array
    {
        return $this->originMemo ??= Origin::fromStored($this->file, $this->line, (string) $this->message, (array) ($this->context['trace'] ?? []));
    }

    /**
     * Where it happened, relative to the project — an absolute Windows path
     * is forty characters of noise before the part anybody reads.
     */
    public function shortFile(): ?string
    {
        $file = $this->origin()['file'];

        if (! $file) {
            return null;
        }

        $root = str_replace('\\', '/', base_path()).'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    /**
     * The framework line it was actually thrown on, when that is somewhere
     * else than the origin — relative to the project, like shortFile().
     */
    public function thrownAt(): ?string
    {
        $thrown = $this->context['thrown_at'] ?? null;

        // "file:line" — the file itself may hold a colon (D:\…), so the line
        // is cut off the END.
        if (! is_string($thrown) || Origin::isOurs((string) preg_replace('/:\d+$/', '', $thrown))) {
            return null;
        }

        $root = str_replace('\\', '/', base_path()).'/';
        $thrown = str_replace('\\', '/', $thrown);

        return str_starts_with($thrown, $root) ? substr($thrown, strlen($root)) : $thrown;
    }

    public function originLine(): ?int
    {
        return $this->origin()['line'];
    }

    /**
     * Has the code it happened in been touched since it last happened?
     *
     * `changed` — the file was saved after the last occurrence: whoever
     * touched it may well have fixed it, so it is worth a look first. `gone` —
     * the file no longer exists. Null — nothing moved, or the place is not
     * ours to judge. A hint, never a verdict: it hides nothing, a person does.
     */
    public function codeState(): ?string
    {
        $file = $this->origin()['file'];

        if (! $file || ! Origin::isOurs($file) || ! $this->last_seen_at) {
            return null;
        }

        if (! is_file($file)) {
            return 'gone';
        }

        return filemtime($file) > $this->last_seen_at->getTimestamp() ? 'changed' : null;
    }

    /** daisyUI badge modifier, so severity reads at a glance. */
    public function badge(): string
    {
        return match ($this->level) {
            'emergency', 'alert', 'critical' => 'badge-error',
            'error' => 'badge-error badge-outline',
            'warning' => 'badge-warning',
            default => 'badge-ghost',
        };
    }
}
