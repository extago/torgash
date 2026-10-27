<?php

namespace Torgash\Banners\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Torgash\Banners\Fields;
use Torgash\Banners\TemplateText;

/**
 * A template (PLAN §51): HTML with {tokens}, CSS, and what its tokens are.
 *
 * Kind `banner` draws one banner in up to three SHAPES — `wide` (across the
 * column: a main slide), `card` (an upright tile: a card carousel, a cell of
 * the catalogue's grid), `strip` (a low row: the catalogue's list) — each its
 * own HTML over the same fields and CSS. Kind `frame` draws a slider around
 * its slides and says which shape its slots are.
 */
class Template extends Model
{
    public const KINDS = ['banner', 'frame'];

    public const SHAPES = ['wide', 'card', 'strip'];

    protected $table = 'm_banners_templates';

    protected $guarded = [];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    public static function auditLabel(): string
    {
        return 'Banner template';
    }

    protected function casts(): array
    {
        return [
            'html' => 'array',
            'fields' => 'array',
        ];
    }

    public function scopeKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function banners(): HasMany
    {
        return $this->hasMany(Banner::class, 'template_id');
    }

    public function sliders(): HasMany
    {
        return $this->hasMany(Slider::class, 'frame_id');
    }

    public function isFrame(): bool
    {
        return $this->kind === 'frame';
    }

    /** The shapes a banner template can draw — those with HTML. */
    public function shapes(): array
    {
        if ($this->isFrame()) {
            return [];
        }

        return array_values(array_filter(self::SHAPES, fn (string $shape) => trim((string) (($this->html ?? [])[$shape] ?? '')) !== ''));
    }

    public function draws(string $shape): bool
    {
        return in_array($shape, $this->shapes(), true);
    }

    /** A frame turns its slides (`{slides}`) rather than listing them all (`{items}`). */
    public function turns(): bool
    {
        return $this->isFrame() && str_contains((string) (($this->html ?? [])['frame'] ?? ''), '{slides');
    }

    /** @return list<array<string, mixed>> the fields, each made whole */
    public function fieldList(): array
    {
        return array_values(array_filter(array_map([Fields::class, 'shape'], (array) $this->fields)));
    }

    /** The label in the reader's language — a preset's is English, an admin's is theirs. */
    public function label(): string
    {
        return label_text($this->name);
    }

    /** Tokens the HTML uses that are neither a field nor the engine's. */
    public function strayTokens(): array
    {
        $known = [...array_column($this->fieldList(), 'key'), ...Fields::ENGINE[$this->kind] ?? []];
        $used = [];

        foreach ((array) $this->html as $html) {
            $used = [...$used, ...TemplateText::tokens((string) $html)];
        }

        return array_values(array_diff(array_unique($used), $known));
    }
}
