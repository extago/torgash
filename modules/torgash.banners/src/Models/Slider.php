<?php

namespace Torgash\Banners\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An ordered set of banners in a frame (PLAN §51). The frame — a template —
 * decides where the strip, the dots, the arrows and a second slider beside
 * it stand; the settings here decide how the strip moves. Placed by a
 * `banners` block; shows the banners running and visible for this visitor
 * whose template draws the frame's shape, and nothing when none does.
 */
class Slider extends Model
{
    /** How a slider turns, and what a new one starts with. */
    public const DEFAULTS = [
        'effect' => 'slide',
        'autoplay' => 6,
        'loop' => true,
        'per_view' => 1,
        'per_view_tablet' => null,
        'per_view_phone' => 1,
        'drag' => 'snap',
        'step' => 'one',
        'speed' => 'normal',
    ];

    /**
     * How a finger's throw ends: on the next slide (`snap`), gliding past
     * several and settling on one (`glide` — Embla's skipSnaps), or wherever
     * it stops (`free` — dragFree).
     */
    public const DRAGS = ['snap', 'glide', 'free'];

    /** What an arrow moves by: one slide, or everything in view. */
    public const STEPS = ['one', 'view'];

    /** How long a turn takes — Embla's `duration`, in its own units. */
    public const SPEEDS = ['fast' => 15, 'normal' => 25, 'slow' => 40];

    protected $table = 'm_banners_sliders';

    protected $guarded = [];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    public static function auditLabel(): string
    {
        return 'Slider';
    }

    protected function casts(): array
    {
        return ['settings' => 'array', 'values' => 'array'];
    }

    public function frame(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'frame_id');
    }

    public function aside(): BelongsTo
    {
        return $this->belongsTo(Slider::class, 'aside_id');
    }

    public function banners(): BelongsToMany
    {
        return $this->belongsToMany(Banner::class, 'm_banners_slider_banner', 'slider_id', 'banner_id')
            ->withPivot('position')
            ->orderByPivot('position')
            ->orderBy('m_banners_banners.id');
    }

    /** @return array{effect: string, autoplay: int, loop: bool, per_view: int|float, per_view_tablet: int|float, per_view_phone: int|float, drag: string, step: string, speed: string} */
    public function options(): array
    {
        return self::shapeOptions((array) $this->settings);
    }

    /**
     * Settings made whole and safe — also for the template editor's preview,
     * which has no slider. "In view" may be fractional (4.3): the next card
     * peeks from the edge, telling there is more.
     */
    public static function shapeOptions(array $settings): array
    {
        $settings += self::DEFAULTS;

        return [
            'effect' => in_array($settings['effect'], ['slide', 'fade'], true) ? $settings['effect'] : 'slide',
            'autoplay' => max(0, min(60, (int) $settings['autoplay'])),
            'loop' => (bool) $settings['loop'],
            'per_view' => self::count($settings['per_view'], 8),
            // A slider saved before the tablet had its own number keeps
            // what a tablet showed then: the computer's.
            'per_view_tablet' => self::count($settings['per_view_tablet'] ?? $settings['per_view'], 6),
            'per_view_phone' => self::count($settings['per_view_phone'], 4),
            'drag' => in_array($settings['drag'], self::DRAGS, true) ? $settings['drag'] : 'snap',
            'step' => in_array($settings['step'], self::STEPS, true) ? $settings['step'] : 'one',
            'speed' => isset(self::SPEEDS[$settings['speed']]) ? $settings['speed'] : 'normal',
        ];
    }

    /** A count of slides in view, to a tenth: whole stays an int, so `=== 1` still holds in the script. */
    protected static function count(mixed $value, int $max): int|float
    {
        $number = round(max(1, min($max, (float) str_replace(',', '.', (string) $value))), 1);

        return $number == floor($number) ? (int) $number : $number;
    }
}
