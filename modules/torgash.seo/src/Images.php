<?php

namespace Torgash\Seo;

use App\Core\Content\Placeholders;
use App\Core\Seo\PageSubject;
use Torgash\Seo\Models\Template;

/**
 * What a photograph is called when nobody has named it.
 *
 * An `alt` is the one piece of text on a shop that is written for a reader who
 * cannot see the page, and it is the first thing a busy catalogue leaves empty
 * — so this fills it from the same template engine the titles use, scoped the
 * same way, and never over a caption somebody actually wrote (unless the
 * template says `override`).
 *
 * The seam is a FILTER the media module asks (`media.image.alt` /
 * `media.image.title`): media knows nothing about SEO, this module knows
 * nothing about media's storage. Answered only for an owner that is a
 * PageSubject — a photograph belonging to something with no page of its own has
 * no template to be named by.
 *
 * The templates are asked for at every call, never held — see Metadata: this
 * object lives as long as the worker, their memo only as long as the request.
 */
class Images
{
    public function register(): void
    {
        hook_listen('media.image.alt', fn ($value, array $context) => $this->text('image_alt', $value, $context));
        hook_listen('media.image.title', fn ($value, array $context) => $this->text('image_title', $value, $context));

        // Which photograph this is, for a catalogue that shows six of the same
        // thing. Registered HERE rather than in the media module because it
        // means nothing outside an image template — everywhere else it resolves
        // to '', like every other token off its own page.
        //
        // The first image has no number, exactly as the add-on this replaces
        // did: "Laptop photo" reads better than "Laptop photo 1", and the
        // optional-fragment brackets make the rest opt in — `[ {image_number}]`.
        app(Placeholders::class)->register('image_number', [
            'label' => 'Which photograph this is (blank for the first)',
            'group' => 'page',
            'resolve' => fn (array $context = []) => ($index = (int) ($context['index'] ?? 0)) > 0 ? (string) ($index + 1) : '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function text(string $field, mixed $value, array $context): mixed
    {
        $model = $context['model'] ?? null;

        if (! $model instanceof PageSubject) {
            return $value;
        }

        $templates = app(Templates::class);
        $template = $templates->for($model);

        if ($template === null || $template->mode === Template::MODE_SKIP) {
            return $value;
        }

        // A caption somebody typed is the answer unless the template was told
        // to overrule it — the same rule as every other field here.
        if ($template->mode === Template::MODE_FILL_EMPTY && trim((string) $value) !== '') {
            return $value;
        }

        $text = (string) ($template->{$field}[app()->getLocale()] ?? '');

        if ($text === '') {
            return $value;
        }

        $expanded = trim(app(Placeholders::class)->expand(
            $text,
            $templates->context($model) + ['index' => (int) ($context['index'] ?? 0)],
            false,
        ));

        return $expanded !== '' ? $expanded : $value;
    }
}
