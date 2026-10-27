<?php

namespace Torgash\Guard\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody did.
 *
 * @property string $action
 * @property array|null $changes
 */
class AuditEntry extends Model
{
    protected $table = 'm_guard_audit';

    protected $guarded = [];

    /** Written once, never edited — see the migration. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The actor as they are NOW, which is a different question from who they
     * were called at the time — `user_name` answers that one and keeps
     * answering it after the account is gone.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFor(Builder $query, string $type, string|int $id): Builder
    {
        return $query->where('entity_type', $type)->where('entity_id', (string) $id);
    }

    /** What kind of thing this was, in a word a person reads. */
    public function entityLabel(): string
    {
        return self::typeLabel($this->entity_type);
    }

    /**
     * A model class as words: `FilterPage` is "Filter page", translated like
     * any other English string — so a module names its own models in its own
     * lang file and this screen never holds a list of them. A name that means
     * something else in that one shared table is declared by the model as
     * `public static function auditLabel(): string`. A class nobody translated
     * still reads as words rather than as a PHP name.
     */
    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            null => __('Something'),
            'setting' => __('Setting'),
            'module' => __('Module'),
            \Spatie\MediaLibrary\MediaCollections\Models\Media::class => __('File'),
            // A model may name itself — `Block` alone is a verb in the one
            // translation table every module shares.
            default => class_exists($type) && method_exists($type, 'auditLabel')
                ? __($type::auditLabel())
                : __(ucfirst(strtolower(\Illuminate\Support\Str::snake(class_basename($type), ' ')))),
        };
    }

    public function actionLabel(): string
    {
        return self::labelFor((string) $this->action);
    }

    /** The same words for the filter's own select, which has no row to ask. */
    public static function labelFor(string $action): string
    {
        return match ($action) {
            'created' => __('Created'),
            'updated' => __('Changed'),
            'deleted' => __('Deleted'),
            'restored' => __('Restored'),
            'installed' => __('Installed'),
            'uninstalled' => __('Uninstalled'),
            'enabled' => __('Switched on'),
            'disabled' => __('Switched off'),
            'signed_in' => __("Signed in on a storefront's address"),
            'truncated' => __('Not recorded'),
            default => ucfirst($action),
        };
    }

    /** daisyUI badge modifier — a deletion should not read like a save. */
    public function badge(): string
    {
        return match ($this->action) {
            'created', 'installed', 'enabled', 'restored' => 'badge-success',
            'deleted', 'uninstalled', 'disabled' => 'badge-error',
            'truncated' => 'badge-warning',
            default => 'badge-info',
        };
    }

    /** @return array<int, string> The field names touched, for the list's one line. */
    public function fields(): array
    {
        return array_keys($this->changes ?? []);
    }

    /**
     * One side of one field, as a person reads it. NULL and false are the two
     * that print as nothing at all unless they are spelled out — and "the flag
     * was cleared" is exactly the kind of change somebody comes here to find.
     */
    public static function printed(array $sides, string $side): string
    {
        if (! array_key_exists($side, $sides)) {
            return '';
        }

        return match (true) {
            $sides[$side] === null => '—',
            is_bool($sides[$side]) => $sides[$side] ? __('yes') : __('no'),
            default => (string) $sides[$side],
        };
    }

    /**
     * The row's name as recorded — minus what older entries stored for a
     * translatable column nobody had filled in (its jsonb text, `[]`).
     */
    public function displayTitle(): ?string
    {
        $title = trim((string) $this->entity_title);

        return in_array($title, ['', '[]', '{}'], true) ? null : $title;
    }

    public function who(): string
    {
        return $this->user_name ?: ($this->user?->name ?: __('Nobody'));
    }
}
