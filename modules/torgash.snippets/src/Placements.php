<?php

namespace Torgash\Snippets;

use App\Core\Content\Placeholders;
use App\Core\Privacy\Consent;
use Illuminate\Support\Collection;
use Torgash\Snippets\Models\Snippet;

/**
 * What goes into each of the three slots on the page being rendered.
 *
 * One query per request for all of them, not one per slot: a page asks three
 * times and a shop has a handful of snippets. Per request rather than a static,
 * for the reason every memo in this engine is — and actually forgotten between
 * requests (App\Core\Support\PerRequest), because which snippets are VISIBLE
 * depends on the page.
 *
 * Placeholders are expanded, unescaped, so `{order_number}` on a thank-you page
 * becomes the number. Unknown tokens are left exactly as typed, which is what
 * keeps ordinary JavaScript — `${total}`, `[{ id: 1 }]` — from being quietly
 * rewritten on its way to the browser.
 */
class Placements
{
    /** @var Collection<int, Snippet>|null */
    protected $all = null;

    /** Did this page print anything that may wait — for consent or for a click? */
    protected bool $waiting = false;

    public function render(string $placement): string
    {
        // Safe mode is the storefront without the code the shop added to it
        // (App\Core\Support\SafeMode) — the way back from a snippet that
        // broke every page.
        if (safe_mode()) {
            return '';
        }

        $out = [];

        foreach ($this->snippets() as $snippet) {
            // A snippet that waits for a click waits at the END of the page,
            // wherever it was meant to go: its button is part of the body, and
            // code run late is code run all the same.
            $slot = $snippet->on_click ? 'footer' : $snippet->placement;

            if ($slot !== $placement || ! $snippet->isVisible()) {
                continue;
            }

            // UNESCAPED. A snippet is code the shop wrote and is trusted to
            // write; the values mostly land inside a <script>, where HTML
            // escaping is not a safety measure but a corruption — a shop called
            // "Bob & Co" would reach JavaScript as "Bob &amp; Co".
            $code = trim(app(Placeholders::class)->expand($snippet->code, [], false));

            if ($code !== '') {
                $out[] = $this->gate($snippet, $code);
            }
        }

        // The few lines that switch waiting code on — once, at the end, and
        // only on a page that has something waiting.
        if ($placement === 'footer' && $this->waiting) {
            $out[] = '<script nonce="'.csp_nonce().'" src="'.e(module_asset('torgash.snippets', 'consent.js')).'" defer></script>';
        }

        return $out === [] ? '' : implode("\n", $out)."\n";
    }

    /**
     * The code as it may be printed (PLAN §49), its scripts carrying this
     * page's nonce: the shop's own code is trusted to run, injected markup
     * is not (PLAN §53).
     *
     * "Necessary", or no privacy module answering, prints it exactly as it
     * always was. Anything else depends on the visitor (the `gated` view),
     * so it is a personal part of the page: one copy of it serves those who
     * agreed and those who did not (PLAN §61). Its code is kept with the copy
     * unsigned — the nonce is each visitor's, and the part signs it itself.
     */
    protected function gate(Snippet $snippet, string $code): string
    {
        $csp = app(\App\Core\Http\Csp::class);

        if ($snippet->on_click) {
            $this->waiting = true;
            $id = 'snippet-'.$snippet->id;

            return '<template id="'.$id.'" data-consent-click>'.$csp->sign($code).'</template>'
                .view('torgash.snippets::click-button', [
                    'clickTarget' => $id,
                    'clickLabel' => $snippet->t('click_label') ?: __('Chat'),
                ])->render();
        }

        $category = $snippet->category ?: Consent::NECESSARY;

        if (! Consent::isAsked($category)) {
            return $csp->sign($code);
        }

        $this->waiting = true;

        return personal('torgash.snippets::gated', ['category' => $category, 'code' => $code]);
    }

    /** @return Collection<int, Snippet> */
    protected function snippets()
    {
        return $this->all ??= Snippet::query()
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('storefront_id')->orWhere('storefront_id', current_storefront()?->id))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** For a screen that has just saved one, and for tests. */
    public function flush(): void
    {
        $this->all = null;
    }
}
