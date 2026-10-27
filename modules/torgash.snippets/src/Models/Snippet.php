<?php

namespace Torgash\Snippets\Models;

use App\Core\Display\DisplayRules;
use App\Core\Privacy\Consent;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class Snippet extends Model
{
    use HasTranslations;

    protected $table = 'm_snippets_snippets';

    protected $guarded = [];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Code snippet';
    }

    /** The three slots core's theme prints — see App\Core\Seo and the storefront layout. */
    public const PLACEMENTS = ['head', 'body_start', 'footer'];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'enabled' => 'bool',
            'on_click' => 'bool',
            'click_label' => 'array',
        ];
    }

    /**
     * Is this one shown on the page being rendered?
     *
     * Through core's engine, which fills in where the page is and who is
     * looking by itself, so a caller passes nothing. An unknown kind fails
     * CLOSED — a rule left over from a module that has been switched off hides
     * the snippet rather than being ignored, which for a tracking pixel is the
     * safe direction.
     */
    public function isVisible(array $context = []): bool
    {
        return app(DisplayRules::class)->passes($this->rules ?? [], $context, $this->rules_match ?? 'all');
    }

    /**
     * Addresses that say what a piece of code is, whatever the shop called
     * it — the hint beside the category, never a decision (PLAN §49).
     */
    public const KNOWN_DOMAINS = [
        'marketing' => ['connect.facebook.net', 'facebook.com/tr', 'analytics.tiktok.com', 'googleadservices.com', 'doubleclick.net', 'snap.licdn.com', 'vk.com/rtrg', 'top-fwz1.mail.ru'],
        'statistics' => ['google-analytics.com', 'googletagmanager.com', 'mc.yandex.ru', 'hotjar.com', 'clarity.ms', 'plausible.io', 'matomo'],
        'functional' => ['jivosite', 'jivo.ru', 'tawk.to', 'crisp.chat', 'intercom', 'livechat', 'binotel', 'ringostat', 'maps.googleapis.com', 'api-maps.yandex.ru'],
    ];

    /** The category the code LOOKS like, from the addresses in it — or null. */
    public static function guessCategory(string $code): ?string
    {
        $code = mb_strtolower($code);

        foreach (self::KNOWN_DOMAINS as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($code, $needle)) {
                    return $category;
                }
            }
        }

        return null;
    }

    public function categoryLabel(): string
    {
        return __(Consent::CATEGORIES[$this->category ?: Consent::NECESSARY] ?? 'Necessary');
    }

    public function placementLabel(): string
    {
        return match ($this->placement) {
            'body_start' => __('Start of the page body'),
            'footer' => __('End of the page'),
            default => __('Page head'),
        };
    }
}
