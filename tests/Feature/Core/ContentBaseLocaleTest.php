<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * The language a shop's content is written in first is the SHOP's, not the
 * translator's fallback. With the two being one setting, a Russian shop's
 * admin opened its forms on English and labelled the product name "(EN)"
 * (found on the first live shop, 2026-10-21) — and the fallback could not be
 * made Russian, because the framework's own messages exist only in English.
 */
class ContentBaseLocaleTest extends TestCase
{
    public function test_a_russian_shop_writes_and_requires_russian_while_the_translator_falls_back_to_english(): void
    {
        config(['torgash.content_locale' => 'ru']);

        $this->assertSame('ru', content_base_locale());
        $this->assertSame('ru', admin_content_locale(), 'the admin\'s forms open on the shop\'s language');
        $this->assertTrue(content_locale_is_base());
        $this->assertSame('Ноутбук', pick_translation(['en' => 'Laptop', 'ru' => 'Ноутбук'], 'uk'), 'a missing language falls back to the shop\'s');

        $this->assertSame('en', config('app.fallback_locale'));
        $this->assertNotSame('validation.required', __('validation.required', ['attribute' => 'x']), 'the framework\'s messages still have their English');
    }

    public function test_with_nothing_said_it_stays_the_translators_fallback(): void
    {
        config(['torgash.content_locale' => '']);

        $this->assertSame(config('app.fallback_locale'), content_base_locale());
    }
}
