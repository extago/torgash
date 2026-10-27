<?php

namespace Torgash\Enquiries;

/**
 * What kinds of enquiry a shop takes, and what each one asks for.
 *
 * A registry rather than an enum, for the reason every registry here exists:
 * another module has reasons of its own to be asked about — a spare-parts
 * module wants "find this part for me", a B2B one wants "request a quote" —
 * and it should get an inbox, a status, a manager and an automations trigger
 * by declaring a kind, not by growing a table of its own.
 *
 * Kinds come from two places. Code declares the ones a module needs (a
 * callback, a return); the SHOP builds its own on Enquiries → Forms (PLAN §48),
 * and those arrive through `Forms` as `form.<id>` — read per request, never
 * held here, since this registry is static and a static outlives the request.
 *
 * A field is ['key', 'type', 'label', 'required'?, 'options'?, 'help'?]:
 *   name | tel | email | text | textarea | number | date | select | radio |
 *   checkboxes | yes | file — the last seven are what the shop's own forms
 *   add (see Models\Form::TYPES).
 */
class Kinds
{
    /** @var array<string, array<string, mixed>> */
    protected static array $kinds = [];

    /** @param array<string, mixed> $definition */
    public static function register(string $key, array $definition): void
    {
        self::$kinds[$key] = $definition + [
            'label' => $key,
            'icon' => 'message-square',
            'fields' => [],
            // 'product' means the form must be given one and the enquiry is
            // filed against it; null means the kind is about nothing in
            // particular. Anything else is a subject some other module owns.
            'subject' => null,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::$kinds + self::built();
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::$kinds[$key] ?? self::built()[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return self::find($key) !== null;
    }

    /**
     * The shop's own forms, as kinds — asked of the per-request memo every
     * time, so nothing about them is kept in this static class.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function built(): array
    {
        return app()->bound(Forms::class) ? app(Forms::class)->kinds() : [];
    }

    public static function label(string $key): string
    {
        $kind = self::find($key);

        return $kind ? self::localized($kind['label'], $key) : $key;
    }

    /**
     * A declared label (English, or an older {en, ru} map) in the reader's language.
     *
     * The same fall-through as everywhere else in the engine: the current
     * locale, then English, then the bare key — a kind registered by a module
     * that never translated itself is still readable rather than blank.
     *
     * @param array<string, string>|string $label
     */
    public static function localized(array|string $label, string $fallback = ''): string
    {
        return label_text($label, null, $fallback);
    }

    /** @return list<array<string, mixed>> */
    public static function fields(string $key): array
    {
        return self::find($key)['fields'] ?? [];
    }

    /** Forget everything — tests only. */
    public static function flush(): void
    {
        self::$kinds = [];
    }

    /**
     * The four a shop has whether or not anybody planned for them.
     *
     * Each asks for the least it can and still be actionable: a callback needs
     * a phone and nothing else (a name is offered, not demanded — "Иванов" in a
     * required field is a lie the form asked for), a question needs the
     * question, and "tell me when it is back" needs exactly one way to reach
     * the person, which is why both fields are offered and the submission rule
     * requires one of them rather than either specifically.
     */
    public static function registerBuiltIn(): void
    {
        self::register('callback', [
            'label' => 'Callback',
            'icon' => 'phone-call',
            'fields' => [
                ['key' => 'name', 'type' => 'text', 'label' => 'Your name'],
                ['key' => 'phone', 'type' => 'tel', 'required' => true, 'label' => 'Phone'],
                [
                    'key' => 'time',
                    'type' => 'select',
                    'label' => 'When to call',
                    'options' => [
                        'any' => 'Any time',
                        'morning' => 'Morning',
                        'afternoon' => 'Afternoon',
                        'evening' => 'Evening',
                    ],
                ],
                ['key' => 'comment', 'type' => 'textarea', 'label' => 'Anything we should know'],
            ],
        ]);

        self::register('product_question', [
            'label' => 'Question about a product',
            'icon' => 'circle-help',
            'subject' => 'product',
            'fields' => [
                ['key' => 'name', 'type' => 'text', 'label' => 'Your name'],
                ['key' => 'phone', 'type' => 'tel', 'label' => 'Phone'],
                ['key' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['key' => 'question', 'type' => 'textarea', 'required' => true, 'label' => 'Your question'],
            ],
        ]);

        self::register('back_in_stock', [
            'label' => 'Tell me when it is back',
            'icon' => 'bell-ring',
            'subject' => 'product',
            'fields' => [
                ['key' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['key' => 'phone', 'type' => 'tel', 'label' => 'Phone'],
            ],
        ]);

        self::register('message', [
            'label' => 'Message',
            'icon' => 'mail',
            'fields' => [
                ['key' => 'name', 'type' => 'text', 'label' => 'Your name'],
                ['key' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['key' => 'phone', 'type' => 'tel', 'label' => 'Phone'],
                ['key' => 'message', 'type' => 'textarea', 'required' => true, 'label' => 'Message'],
            ],
        ]);
    }
}
