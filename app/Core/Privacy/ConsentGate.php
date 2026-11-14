<?php

namespace App\Core\Privacy;

use App\Core\Http\Csp;

/**
 * Third-party code that waits for the visitor's word (PLAN §49, §73).
 *
 * A piece of code of a category the visitor is asked about goes out inside a
 * `<template data-consent="statistics">`: the browser parses it and runs
 * nothing, and `assets/js/consent-gate.js` re-creates its scripts the moment
 * the banner announces `torgash:consent` — on the same page, without a reload,
 * so the visit that agreed is counted too. It used to be the snippets' own;
 * the counters of torgash.metrika and torgash.google-analytics need the very
 * same thing, and three copies of "wait for consent" would be three ways of
 * getting it wrong.
 *
 * Which of the two a visitor gets is THEIR answer, so the choice is a
 * personal part of the page (`personal()`): one kept copy serves those who
 * agreed and those who did not (PLAN §61). Per request: whether this page
 * printed anything waiting decides whether it needs the script.
 */
class ConsentGate
{
    protected bool $waiting = false;

    /**
     * The code as it may be printed, its scripts carrying this page's nonce.
     * Of a category nobody asks about — "necessary", or a shop with no privacy
     * module — exactly as it is.
     */
    public function wrap(string $category, string $code): string
    {
        if (! Consent::isAsked($category)) {
            return app(Csp::class)->sign($code);
        }

        $this->waiting = true;

        // Kept with the copy unsigned: the nonce is each visit's, and the part
        // signs it itself.
        return personal('consent.gated', ['category' => $category, 'code' => $code]);
    }

    /** The few lines that switch waiting code on — once, at the end of a page that has any. */
    public function script(): string
    {
        return $this->waiting
            ? '<script nonce="'.e(csp_nonce()).'" src="'.e(asset_url('/assets/js/consent-gate.js')).'" defer></script>'
            : '';
    }
}
