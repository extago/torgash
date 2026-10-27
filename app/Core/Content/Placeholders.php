<?php

namespace App\Core\Content;

/**
 * Global {token} substitutions for admin-authored content — shortcodes.
 *
 * Modules register tokens; any raw-HTML content the admin writes can then use
 * them. That is why there is no "contacts panel" block type: it would have
 * differed from the plain HTML block only by running this substitution, so the
 * substitution belongs to the HTML block itself and the tokens belong to
 * whoever owns the data.
 *
 * Safe for the same reason the settings JSON is safe: this is strtr() over a
 * REGISTERED set of tokens — string interpolation, not evaluation. The token
 * list and every resolver are code in files; the content is data. An admin can
 * write {phone}, but cannot write something that runs.
 *
 * Escaping is on by default: a resolver returns a plain value and it gets
 * escaped before landing in HTML. A token that deliberately emits markup
 * ({messengers}, the icon tokens) declares 'raw' => true, which makes that
 * choice visible at the registration site instead of being implicit.
 *
 * Resolvers are called lazily and only for tokens actually present in the text,
 * so registering an expensive token costs nothing on pages that never use it.
 *
 * Three things a token may carry beyond its name, all added for the SEO
 * templates (PLAN §30.5) and all useful anywhere a person writes content:
 *
 *   {category|lower}          a MODIFIER — lower, upper, ucfirst, lcfirst,
 *                             chained with more bars. Older engines ship one token
 *                             per case; one token and a modifier is the same
 *                             feature without four times the picker.
 *   {feature:ram_gb}          an ARGUMENT, for a token that names a family
 *                             rather than a value. The resolver is handed it as
 *                             its second parameter.
 *   [ — {feature:color}]      an OPTIONAL FRAGMENT: square brackets holding at
 *                             least one token disappear WITH their surrounding
 *                             text when any token inside them is empty. That is
 *                             what stops a title reading "Laptop — ".
 *
 * Brackets that contain no token are left exactly as typed, so ordinary prose
 * is unaffected.
 */
class Placeholders
{
    /** @var array<string, array{token: string, label: array<string,string>, group: string, raw: bool, resolve: callable, example: ?string, argument: ?array<string,mixed>, block: bool}> */
    protected array $tokens = [];

    /**
     * Name, optional `:argument`, optional `|modifiers` — the shape expand()
     * recognises. One expression, because the picker, the expander and the SEO
     * module's own preview all have to agree on what a token looks like.
     */
    public const PATTERN = '/\{([a-z][a-z0-9_-]*)(?::([^}|]*))?((?:\|[a-z_]+)*)\}/i';

    /**
     * @param  string  $token  bare name, no braces — 'phone', not '{phone}'
     * @param  array{label?: array<string,string>, group?: string, raw?: bool, resolve: callable, example?: string, argument?: array<string,mixed>}  $definition
     */
    public function register(string $token, array $definition): void
    {
        // Flat names keep existing snippets working, so collisions are possible
        // as more modules register. Loud in debug rather than silently letting
        // the last module win and changing what someone's saved content means.
        if (isset($this->tokens[$token]) && config('app.debug')) {
            logger()->warning("Placeholder [{$token}] is already registered by group [{$this->tokens[$token]['group']}] and is being overwritten.");
        }

        $this->tokens[$token] = [
            'token' => $token,
            'label' => $definition['label'] ?? [],
            'group' => $definition['group'] ?? 'general',
            'raw' => (bool) ($definition['raw'] ?? false),
            'resolve' => $definition['resolve'],
            'example' => $definition['example'] ?? null,
            // A token that takes `:something` — the picker writes it with a
            // placeholder after the colon instead of offering it bare.
            'argument' => $definition['argument'] ?? null,
            // A token whose value is a BLOCK of markup (a form, a card): alone
            // in a paragraph it replaces the paragraph, because a <div> inside
            // a <p> is split apart by the browser into empty paragraphs around it.
            'block' => (bool) ($definition['block'] ?? false),
        ];
    }

    /** @return array<string, array<string,mixed>> */
    public function all(): array
    {
        return $this->tokens;
    }

    /** Grouped for the admin's placeholder picker, group => list. */
    public function grouped(): array
    {
        $grouped = [];
        foreach ($this->tokens as $token) {
            $grouped[$token['group']][] = $token;
        }
        ksort($grouped);

        return $grouped;
    }

    public function label(string $token, ?string $locale = null): string
    {
        $definition = $this->tokens[$token] ?? null;
        if (! $definition) {
            return $token;
        }

        $locale ??= app()->getLocale();

        return label_text($definition['label'] ?? null, $locale, $token);
    }

    /**
     * Expands every registered token present in $text. Intended for content
     * that is rendered UNESCAPED (the HTML block, the contacts snippet) — the
     * escaping of each value happens here instead.
     *
     * `$escape` is for the other case: text that goes into an ATTRIBUTE, where
     * the template engine escapes it on the way out and doing it here as well
     * turns an ampersand in a product name into `&amp;amp;`. An SEO template
     * filling in a meta description passes false.
     *
     * Unknown {tokens} are left exactly as written rather than blanked: a typo
     * should be visible to whoever wrote it, not silently swallowed.
     */
    public function expand(string $text, array $context = [], bool $escape = true): string
    {
        if ($text === '' || ! str_contains($text, '{')) {
            return $text;
        }

        // One resolver call per distinct token, not per occurrence: strtr used
        // to give that for free, and a resolver can be a database query (the
        // club price is). Local to this call, so a resolver that expands text
        // of its own cannot be served another call's values.
        $memo = [];
        $text = $this->unwrapBlocks($text);
        // A closure, not an arrow function: the memo must be shared by reference.
        $value = function (array $match) use ($context, &$memo, $escape) {
            return $this->value($match, $context, $memo, $escape);
        };

        return self::fill(self::fillFragments($text, $value), $value);
    }

    /**
     * The same grammar over a resolver of the CALLER's — tokens nobody
     * registered globally, like a banner template's own fields (PLAN §51).
     * `$value` gets the match (whole token, name, argument, modifiers) and
     * returns the finished text, already escaped as it must be, or null for
     * "not a token of mine" (left as typed). One grammar, one author: a
     * second copy of these rules would start to disagree about brackets.
     *
     * @param  \Closure(array<int, string>): ?string  $value
     */
    public static function expandWith(string $text, \Closure $value): string
    {
        if ($text === '' || ! str_contains($text, '{')) {
            return $text;
        }

        return self::fill(self::fillFragments($text, $value), $value);
    }

    /**
     * `<p>{form:feedback}</p>` → `{form:feedback}` for a token registered as
     * `block` — what the editor writes for a token on a line of its own.
     */
    protected function unwrapBlocks(string $text): string
    {
        if (! str_contains($text, '<p')) {
            return $text;
        }

        return (string) preg_replace_callback('~<p>\s*(\{([a-z][a-z0-9_-]*)(?::[^}|]*)?\})\s*</p>~i', function (array $m) {
            return ($this->tokens[$m[2]]['block'] ?? false) ? $m[1] : $m[0];
        }, $text);
    }

    /**
     * `[...]` around at least one token: kept with the tokens filled in, or
     * dropped whole when any of them has nothing to say.
     *
     * Not nested, deliberately — one level answers the question this exists for
     * ("print the dash only when there is a colour"), and a nested grammar in
     * admin-typed text is a thing nobody can debug from the other side of a
     * support ticket.
     */
    protected static function fillFragments(string $text, \Closure $resolve): string
    {
        if (! str_contains($text, '[')) {
            return $text;
        }

        return (string) preg_replace_callback('/\[([^\[\]]*\{[^\[\]]*)\]/', function (array $match) use ($resolve) {
            $empty = false;
            $known = false;

            $inner = (string) preg_replace_callback(self::PATTERN, function (array $token) use ($resolve, &$empty, &$known) {
                $value = $resolve($token);

                if ($value === null) {
                    return $token[0]; // not a token of ours — leave it as typed
                }

                $known = true;

                if ($value === '') {
                    $empty = true;
                }

                return $value;
            }, $match[1]);

            // Brackets are only a fragment when they hold a token this shop
            // actually knows. Otherwise they are somebody's punctuation — or
            // JavaScript, `[{ label: 1 }]` — and eating them would be a silent
            // edit of code an admin pasted in.
            if (! $known) {
                return $match[0];
            }

            return $empty ? '' : $inner;
        }, $text);
    }

    protected static function fill(string $text, \Closure $resolve): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $token) use ($resolve) {
            // Unknown {tokens} are left exactly as written rather than blanked:
            // a typo should be visible to whoever wrote it.
            return $resolve($token) ?? $token[0];
        }, $text);
    }

    /**
     * One matched token's finished text — resolved, modified and escaped — or
     * null when nothing registered that name.
     *
     * @param  array<int, string>  $match  the whole token, its name, its
     *                                     argument, its modifiers
     */
    protected function value(array $match, array $context, array &$memo, bool $escape): ?string
    {
        $definition = $this->tokens[$match[1]] ?? null;

        if ($definition === null) {
            return null;
        }

        if (array_key_exists($match[0], $memo)) {
            return $memo[$match[0]];
        }

        // The context is what the caller was rendering — a product, for a
        // block in a product-page zone. Passed positionally, so every resolver
        // written before this (`fn () => …`) keeps working: PHP ignores
        // arguments a closure did not ask for.
        $argument = ($match[2] ?? '') !== '' ? $match[2] : null;
        $value = (string) ($definition['resolve'])($context, $argument);
        $value = $this->modify($value, $match[3] ?? '');

        return $memo[$match[0]] = ($definition['raw'] || ! $escape) ? $value : e($value);
    }

    /** `|lower|ucfirst` applied left to right; an unknown modifier changes nothing. */
    protected function modify(string $value, string $modifiers): string
    {
        foreach (array_filter(explode('|', $modifiers)) as $modifier) {
            $value = match (strtolower($modifier)) {
                'lower' => mb_strtolower($value),
                'upper' => mb_strtoupper($value),
                'ucfirst' => mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1),
                'lcfirst' => mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1),
                default => $value,
            };
        }

        return $value;
    }

    /**
     * One token's raw, unescaped value, or null when nothing registered it.
     *
     * Not for rendering — expand() is, and it escapes. This exists so content
     * can be conditioned on whether a token has anything in it at all (the HTML
     * block's "hide when empty"), which is a question about the data, not about
     * the markup it would produce.
     */
    public function resolve(string $token, array $context = []): ?string
    {
        [$name, $argument] = array_pad(explode(':', trim($token, '{} '), 2), 2, null);
        $definition = $this->tokens[$name] ?? null;

        return $definition === null ? null : (string) ($definition['resolve'])($context, $argument);
    }

    /** For tests — the registry is process-local, rebuilt on every boot. */
    public function flush(): void
    {
        $this->tokens = [];
    }
}
