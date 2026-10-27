<?php

namespace App\Core\Http;

/**
 * The content security policy: which scripts a page may run (PLAN §53).
 *
 * A "strict" policy, the kind that holds against injected markup: a script
 * runs when it carries this request's NONCE, or was loaded by one that did
 * (`strict-dynamic` — so the shop's own counters and tag managers, printed
 * by Code snippets with the nonce, may load what they load). No list of
 * hosts to keep up to date. `unsafe-eval` stays for Alpine and htmx, which
 * evaluate their attributes; `unsafe-inline` and `https:` are only the
 * fallback for browsers too old to know nonces and are ignored by the rest.
 *
 * One nonce per request (scoped, forgotten between requests): a nonce that
 * repeats is a password printed on every page.
 */
class Csp
{
    /** Core setting: `enforce` (default), `report` or `off`. */
    public const SETTING = 'csp_mode';

    protected ?string $nonce = null;

    /** The response header to send the policy in, or null when it is off. */
    public function header(): ?string
    {
        return match ((string) setting(\App\Core\Settings\CoreSettings::ID, self::SETTING, 'enforce')) {
            'off' => null,
            'report' => 'Content-Security-Policy-Report-Only',
            default => 'Content-Security-Policy',
        };
    }

    public function nonce(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(18));
    }

    public function policy(): string
    {
        return implode('; ', [
            "script-src 'nonce-".$this->nonce()."' 'strict-dynamic' 'unsafe-eval' 'unsafe-inline' https:",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            'report-uri '.route('csp.report', [], false),
        ]);
    }

    /**
     * Adds this request's nonce to every <script> TAG in a piece of HTML that
     * has none — and never inside a script's body: an old counter writes
     * `document.write("<script src=…")`, and a nonce="" put into that string
     * would end it.
     */
    public function sign(string $html): string
    {
        $out = '';
        $at = 0;

        while (preg_match('~<script\b~i', $html, $m, PREG_OFFSET_CAPTURE, $at)) {
            $start = $m[0][1];
            $tagEnd = strpos($html, '>', $start);
            if ($tagEnd === false) {
                break;
            }

            $tag = substr($html, $start, $tagEnd - $start + 1);
            $out .= substr($html, $at, $start - $at)
                .(preg_match('~\bnonce=~i', $tag) ? $tag : '<script nonce="'.$this->nonce().'"'.substr($tag, 7));

            // The body is the script's own text up to its closing tag.
            $close = stripos($html, '</script', $tagEnd + 1);
            $at = $close === false ? strlen($html) : $close;
            $out .= substr($html, $tagEnd + 1, $at - $tagEnd - 1);
        }

        return $out.substr($html, $at);
    }
}
