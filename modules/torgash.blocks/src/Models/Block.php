<?php

namespace Torgash\Blocks\Models;

use App\Core\Display\DisplayRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Torgash\Blocks\BlockTypes;

class Block extends Model
{
    protected $table = 'm_blocks';

    protected $guarded = [];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    /** What the audit trail calls one of these (translated through the lang files). */
    /**
     * A block put into a zone — a new one, or moved — may be the first of a
     * zone a persistent worker did not subscribe to when it booted
     * (BlocksServiceProvider::subscribeToZones): the workers start again.
     * Everything else about a block is read at request time (ZoneBlocks).
     */
    protected static function booted(): void
    {
        static::saved(function (Block $block) {
            if ($block->wasRecentlyCreated || $block->wasChanged('zone')) {
                \App\Core\Support\WebWorkers::restart();
            }
        });
    }

    public static function auditLabel(): string
    {
        return 'Content block';
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'rules' => 'array',
            'section' => 'array',
            'enabled' => 'bool',
        ];
    }

    /**
     * May this block be shown here, now, to this visitor?
     *
     * `enabled` is a different question and is asked before this one (the zone
     * subscription only ever loads enabled blocks). This is the conditional
     * half: no rules means yes, and the engine belongs to core because the
     * kinds of question come from every module — see App\Core\Display.
     *
     * @param  array<string, mixed>  $context  whatever the zone was rendering
     */
    public function isVisible(array $context = []): bool
    {
        return app(DisplayRules::class)->passes(
            (array) ($this->rules ?? []),
            $context,
            (string) ($this->rules_match ?: DisplayRules::MATCH_ALL),
        );
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function typeLabel(): string
    {
        return BlockTypes::label($this->type);
    }

    /**
     * A block whose type is no longer registered — its module was disabled or
     * removed. The row is kept on purpose (re-enabling the module brings the
     * block back exactly as it was), so every consumer has to expect this:
     * rendering skips it, the admin list shows it as unavailable.
     */
    public function typeIsMissing(): bool
    {
        return ! BlockTypes::has($this->type);
    }

    /**
     * Admin-facing name: the explicit title, else the type, else the id.
     *
     * A title a theme (theme.json) or a module's seeding migration wrote is a
     * declared English word, so it is read through the lang files like any
     * other; it stays stored in English because ThemeDefaults tells its own
     * blocks apart by it. A title the owner typed matches no English key and
     * comes back as typed.
     */
    public function displayTitle(): string
    {
        if ($this->title) {
            return label_text($this->title);
        }

        return $this->typeIsMissing() ? $this->type : $this->typeLabel();
    }
}
