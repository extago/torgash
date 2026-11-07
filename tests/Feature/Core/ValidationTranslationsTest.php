<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Laravel words its validation messages in English only, so a rule the code
 * did not word itself ("min:8" on a password) reached a Russian customer as
 * "The password field must be at least 8 characters." (the live shop,
 * 2026-11-07). The engine's own lang/<locale>/validation.php closes that —
 * and goes stale the day Laravel adds a rule, which this notices.
 */
class ValidationTranslationsTest extends TestCase
{
    public function test_every_framework_rule_has_a_russian_and_a_ukrainian_message(): void
    {
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $expected = array_keys(Arr::dot(Arr::except($english, ['custom', 'attributes'])));

        foreach (['ru', 'uk'] as $locale) {
            $ours = require lang_path($locale.'/validation.php');
            $missing = array_diff($expected, array_keys(Arr::dot(Arr::except($ours, ['custom', 'attributes']))));

            $this->assertSame([], array_values($missing), "lang/{$locale}/validation.php has no message for these rules");
        }
    }

    public function test_a_short_password_is_refused_in_the_visitors_language(): void
    {
        $was = app()->getLocale();
        app()->setLocale('ru');

        try {
            $message = Validator::make(['password' => 'short'], ['password' => 'min:8'])->errors()->first('password');
        } finally {
            app()->setLocale($was);
        }

        $this->assertSame('Поле «пароль» должно быть не короче 8 символов.', $message);
    }
}
