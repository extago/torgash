<?php

namespace Tests\Feature\Core;

use App\Core\Content\Placeholders;
use Tests\TestCase;

/**
 * The three things a token may carry beyond its name — a modifier, an argument
 * and an optional fragment — added for the SEO templates and shared by every
 * place a person writes content.
 *
 * The last of them is the one with teeth: square brackets are also ordinary
 * punctuation and, worse, ordinary JavaScript. `[{ id: 1 }]` pasted into a code
 * snippet must come out the other side unchanged, or the engine is quietly
 * editing code somebody's analytics provider gave them.
 */
class PlaceholderSyntaxTest extends TestCase
{
    protected Placeholders $placeholders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->placeholders = app(Placeholders::class);

        $this->placeholders->register('city', [
            'label' => ['en' => 'City'],
            'resolve' => fn () => 'Kyiv',
        ]);

        $this->placeholders->register('colour', [
            'label' => ['en' => 'Colour'],
            'resolve' => fn () => '',
        ]);

        $this->placeholders->register('spec', [
            'label' => ['en' => 'A specification by name'],
            'argument' => ['example' => 'ram'],
            'resolve' => fn (array $context = [], ?string $name = null) => $name === 'ram' ? '16 GB' : '',
        ]);
    }

    public function test_modifiers_change_the_case(): void
    {
        $this->assertSame('kyiv', $this->placeholders->expand('{city|lower}'));
        $this->assertSame('KYIV', $this->placeholders->expand('{city|upper}'));
        $this->assertSame('Kyiv', $this->placeholders->expand('{city|lower|ucfirst}'));
        // An unknown modifier changes nothing rather than blanking the value.
        $this->assertSame('Kyiv', $this->placeholders->expand('{city|shout}'));
    }

    public function test_a_token_may_take_an_argument(): void
    {
        $this->assertSame('16 GB', $this->placeholders->expand('{spec:ram}'));
        $this->assertSame('', $this->placeholders->expand('{spec:weight}'));
    }

    /** The reason the brackets exist: no dangling punctuation. */
    public function test_an_optional_fragment_goes_with_its_own_text(): void
    {
        $this->assertSame('Laptop — 16 GB', $this->placeholders->expand('Laptop[ — {spec:ram}]'));
        $this->assertSame('Laptop', $this->placeholders->expand('Laptop[ — {colour}]'));
    }

    /**
     * Brackets that hold no token this shop knows are somebody's punctuation —
     * or code. Eating them was a real bug: `[{ id: 1 }]` looks exactly like a
     * fragment containing a token called "id".
     */
    public function test_brackets_around_no_known_token_are_left_alone(): void
    {
        $code = 'var items = [{ id: 1, name: "x" }]; if (a) { b(); }';

        $this->assertSame($code, $this->placeholders->expand($code));
        $this->assertSame('[see the notes]', $this->placeholders->expand('[see the notes]'));
        $this->assertSame('${total}', $this->placeholders->expand('${total}'));
    }

    /** A typo stays visible instead of turning into a blank. */
    public function test_an_unknown_token_is_left_as_written(): void
    {
        $this->assertSame('{citty} is here', $this->placeholders->expand('{citty} is here'));
    }

    /**
     * Escaping is the caller's decision, because the two destinations want
     * opposite things: HTML content wants it, an attribute (or a `<script>`)
     * has it done for it and would end up with `&amp;amp;`.
     */
    public function test_escaping_can_be_turned_off_for_text_that_is_escaped_later(): void
    {
        $this->placeholders->register('shop', ['resolve' => fn () => 'Bob & Co']);

        $this->assertSame('Bob &amp; Co', $this->placeholders->expand('{shop}'));
        $this->assertSame('Bob & Co', $this->placeholders->expand('{shop}', [], false));
    }

    /** A resolver may cost a query, so a token repeated five times is asked once. */
    public function test_a_repeated_token_is_resolved_once(): void
    {
        $calls = 0;

        $this->placeholders->register('counted', ['resolve' => function () use (&$calls) {
            $calls++;

            return 'x';
        }]);

        $this->assertSame('x x x', $this->placeholders->expand('{counted} {counted} {counted}'));
        $this->assertSame(1, $calls);
    }
}
