<?php

namespace Torgash\Seo\Models;

use App\Core\Seo\PageSubject;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use HasTranslations;

    protected $table = 'm_seo_templates';

    protected $guarded = [];

    public const MODE_FILL_EMPTY = 'fill_empty';

    public const MODE_OVERRIDE = 'override';

    public const MODE_SKIP = 'skip';

    public const MODES = [self::MODE_FILL_EMPTY, self::MODE_OVERRIDE, self::MODE_SKIP];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'SEO template';
    }

    /**
     * What a template can be about.
     *
     * The words are the ones {@see PageSubject::pageMetaKind()}
     * answers with, so a module adding a kind of page needs nothing here: it
     * appears in this list the moment the shop writes a template for it. The
     * four below are what the engine ships with a page for.
     */
    public const SUBJECTS = ['product', 'category', 'brand', 'page'];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'description' => 'array',
            'h1' => 'array',
            'image_alt' => 'array',
            'image_title' => 'array',
            'enabled' => 'bool',
        ];
    }

    /** `global`, or `category:12` — see the migration for why one column says both. */
    public function categoryId(): ?int
    {
        return str_starts_with($this->scope, 'category:')
            ? (int) substr($this->scope, strlen('category:'))
            : null;
    }

    public function modeLabel(): string
    {
        return match ($this->mode) {
            self::MODE_OVERRIDE => __('Always, over what is typed in'),
            self::MODE_SKIP => __('Do nothing here'),
            default => __('Only where nothing is typed in'),
        };
    }
}
