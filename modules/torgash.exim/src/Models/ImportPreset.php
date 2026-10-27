<?php

namespace Torgash\Exim\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A saved way of reading one kind of file (see the migration). Found again by
 * the file's header: the same supplier's next price list is read the way the
 * last one was, without the admin setting anything up.
 */
class ImportPreset extends Model
{
    protected $table = 'm_exim_import_presets';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Import preset';
    }

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'mapping' => 'array',
            'options' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The preset this header was saved with: the same columns exactly, else
     * the one sharing most of them, as long as that is at least four in five
     * — a supplier who added a column is still the same supplier.
     *
     * @param  list<string>  $header
     */
    public static function forHeader(string $entity, array $header): ?self
    {
        $wanted = array_values(array_unique(array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header)));

        if ($wanted === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach (static::query()->where('entity', $entity)->orderByDesc('last_used_at')->orderByDesc('id')->get() as $preset) {
            $saved = array_values(array_unique(array_map(fn ($h) => mb_strtolower(trim((string) $h)), (array) $preset->columns)));
            $shared = count(array_intersect($wanted, $saved));
            $score = $shared / max(count($wanted), count($saved), 1);

            if ($score > $bestScore) {
                [$best, $bestScore] = [$preset, $score];
            }
        }

        return $bestScore >= 0.8 ? $best : null;
    }
}
