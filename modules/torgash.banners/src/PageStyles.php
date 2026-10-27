<?php

namespace Torgash\Banners;

use Torgash\Banners\Models\Template;

/**
 * What a page has printed already (PLAN §51): each template's CSS once,
 * before the first banner drawn with it — no extra file to fetch, and
 * nothing on a page that shows none of them — and the slider's script once.
 * Per request (`PerRequest`): a reused application must print them again
 * for the next page.
 */
class PageStyles
{
    /** @var array<string, true> */
    protected array $printed = [];

    public function take(Template $template): string
    {
        $css = TemplateText::css($template->css);

        if ($css === '' || ! $this->once('css:'.$template->key)) {
            return '';
        }

        return '<style data-banner-template="'.e($template->key).'">'.$css.'</style>';
    }

    /** True the first time a key is asked for on this page. */
    public function once(string $key): bool
    {
        if (isset($this->printed[$key])) {
            return false;
        }

        return $this->printed[$key] = true;
    }
}
