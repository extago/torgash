<?php

namespace Tests\Unit;

use Tests\TestCase;
use Torgash\Locations\Declension;

/**
 * The case forms a city page's title is written from.
 *
 * Rules about words, and the ones pinned here are the ones a wrong answer is
 * visible in — "в Львіве" and "Ноутбуки Киев" are both things a reader sees
 * instantly. It touches no table; the application is booted only because the
 * class lives in a module, and a module's autoloader is registered by the
 * framework.
 *
 * What is NOT asserted is as deliberate: a feminine `-ь` ("Тверь"), a fleeting
 * vowel ("Елец") and an adjectival name are documented in the class as wrong or
 * left alone, and a test claiming otherwise would be a test of a bug.
 */
class DeclensionTest extends TestCase
{
    protected Declension $declension;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declension = new Declension;
    }

    public function test_a_language_with_one_form_gets_one(): void
    {
        $this->assertSame([1], $this->declension->cases('en'));
        $this->assertSame('Kyiv', $this->declension->form('Kyiv', 'en', 6));
    }

    public function test_russian_masculine_hard(): void
    {
        $forms = $this->declension->forms('Курск', 'ru');

        $this->assertSame('Курск', $forms[1]);
        $this->assertSame('Курска', $forms[2]);
        $this->assertSame('Курску', $forms[3]);
        $this->assertSame('Курск', $forms[4]);
        $this->assertSame('Курском', $forms[5]);
        $this->assertSame('Курске', $forms[6]);
    }

    /** After a husher the instrumental is -ем, not -ом. */
    public function test_russian_husher_takes_a_soft_instrumental(): void
    {
        $this->assertSame('Угличем', $this->declension->form('Углич', 'ru', 5));
    }

    public function test_russian_feminine_in_a(): void
    {
        $forms = $this->declension->forms('Москва', 'ru');

        $this->assertSame('Москвы', $forms[2]);
        $this->assertSame('Москве', $forms[3]);
        $this->assertSame('Москву', $forms[4]);
        $this->assertSame('Москвой', $forms[5]);
        $this->assertSame('Москве', $forms[6]);

        // ...and -и rather than -ы after a velar: Калуги, never "Калугы".
        $this->assertSame('Калуги', $this->declension->form('Калуга', 'ru', 2));
    }

    /**
     * A name that does not decline is left alone — which is both the correct
     * answer and the one a shop expects to read: "в Иваново" is what people
     * write and search for.
     */
    public function test_russian_indeclinable_names_are_left_alone(): void
    {
        foreach (['Иваново', 'Сочи', 'Баку'] as $name) {
            $this->assertSame($name, $this->declension->form($name, 'ru', 6), $name);
        }
    }

    /**
     * The Ukrainian alternation, and the reason this class is not six
     * str_ends_with calls: the oblique stem is a different word.
     */
    public function test_ukrainian_i_o_alternation(): void
    {
        $this->assertSame('Львова', $this->declension->form('Львів', 'uk', 2));
        $this->assertSame('у Львові', 'у '.$this->declension->form('Львів', 'uk', 6));
        $this->assertSame('Києва', $this->declension->form('Київ', 'uk', 2));
        $this->assertSame('Києві', $this->declension->form('Київ', 'uk', 6));
        $this->assertSame('Харкова', $this->declension->form('Харків', 'uk', 2));
        $this->assertSame('Тернополя', $this->declension->form('Тернопіль', 'uk', 2));
    }

    /**
     * A town is inanimate, so its accusative ("доставка в …") is the name
     * itself — the alternated stem is for the other cases: "в Київ", never
     * "в Києв".
     */
    public function test_ukrainian_accusative_is_the_name_itself(): void
    {
        foreach (['Київ', 'Львів', 'Харків', 'Тернопіль', 'Луцьк', 'Ужгород', 'Дніпро'] as $name) {
            $this->assertSame($name, $this->declension->form($name, 'uk', 4), $name);
        }

        $this->assertSame('Полтаву', $this->declension->form('Полтава', 'uk', 4));
        $this->assertSame('Днепр', $this->declension->form('Днепр', 'ru', 4));
    }

    /** A velar stem takes -у in the locative: у Луцьку, not "у Луцькі". */
    public function test_ukrainian_velar_locative(): void
    {
        $this->assertSame('Луцьку', $this->declension->form('Луцьк', 'uk', 6));
        $this->assertSame('Ужгороді', $this->declension->form('Ужгород', 'uk', 6));
    }

    public function test_ukrainian_feminine_in_a(): void
    {
        $this->assertSame('Полтави', $this->declension->form('Полтава', 'uk', 2));
        $this->assertSame('Полтаві', $this->declension->form('Полтава', 'uk', 6));
    }

    /** Ukrainian has a seventh case and Russian does not. */
    public function test_the_vocative_exists_only_where_it_exists(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->declension->cases('uk'));
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->declension->cases('ru'));

        // Asking Russian for the seventh gets the nominative, not an empty
        // string: half a title is worse than a slightly wrong one.
        $this->assertSame('Курск', $this->declension->form('Курск', 'ru', 7));
    }

    /** A two-word or adjectival name declines both halves, which these rules do not. */
    public function test_a_compound_name_is_left_alone(): void
    {
        $this->assertSame('Нижний Новгород', $this->declension->form('Нижний Новгород', 'ru', 2));
        $this->assertSame('Рівне', $this->declension->form('Рівне', 'uk', 2));
    }

    /** `ru_RU` is Russian. A locale with a region is still a language. */
    public function test_a_regional_locale_is_still_its_language(): void
    {
        $this->assertSame('Курска', $this->declension->form('Курск', 'ru-RU', 2));
    }
}
