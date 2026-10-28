{{-- The form's sum, or nothing for a visitor who answered it already: a
     personal part of the page (GuardServiceProvider). --}}
{!! app(\Torgash\Guard\Captcha\Gate::class)->widget($form) !!}
