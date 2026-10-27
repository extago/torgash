{{--
    Raw HTML with global {token} expansion — see App\Core\Content\Placeholders.

    Both halves are deliberate and different:
      * the HTML is rendered unescaped, which is what the `blocks.html`
        permission on the field is for — writing it is the same trust level as
        editing a template;
      * the tokens are strtr() over a registered list, i.e. interpolation and
        not evaluation, with each value escaped by the registry unless the token
        declared itself raw.

    This is why there is no separate "contacts panel" block type: it would have
    been this template plus one strtr() call.

    hide_if_empty is checked BEFORE expansion and skips rendering entirely — a
    block built around one value has to vanish on a store that has no such
    value, chrome included, rather than draw an empty frame.
--}}
@php
    $hideIf = trim((string) ($settings['hide_if_empty'] ?? ''));
    // An UNREGISTERED name resolves to null and hides nothing: a typo here must
    // not silently blank the block, the same reason expand() leaves an unknown
    // {token} standing instead of swallowing it.
    // The zone context travels into the tokens: a block in a product zone can
    // then say {price} or {club_price}, and the same token on a page with no
    // product resolves to nothing (which hide_if_empty can act on).
    $guard = $hideIf === '' ? null : app(\App\Core\Content\Placeholders::class)->resolve($hideIf, $zoneContext ?? []);
    $html = $guard !== null && trim($guard) === ''
        ? ''
        : trim(expand_placeholders((string) ($settings['html'] ?? ''), $zoneContext ?? []));
@endphp
@if ($html !== '')
    <div class="torgash-block torgash-block-html">{!! $html !!}</div>
@endif
