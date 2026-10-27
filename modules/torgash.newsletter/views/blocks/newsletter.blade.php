{{--
    The "Subscribe" block (torgash.newsletter). Two ways to draw it:
    - nothing in "Your own HTML": the theme's look — heading, text, the form,
      the privacy line;
    - your own HTML: laid out as you like, with
        {form}          the field and the button (required — without it nothing subscribes)
        {heading} {text} {button}  the block's own words, in the visitor's language
        {privacy_link}  "privacy policy" as a link (the module's setting), or nothing
        {subscribers}   how many are subscribed
      and every shop placeholder ({shop_name}, {phone}…). Written by those who
      may write raw HTML in blocks (`blocks.html`), like the HTML block.
--}}
@php
    $newsletterHeading = trim((string) ($settings['heading'] ?? ''));
    $newsletterText = trim((string) ($settings['text'] ?? ''));
    $newsletterButton = trim((string) ($settings['button'] ?? ''));
    $newsletterPrivacyUrl = trim((string) setting('torgash.newsletter', 'privacy_url', ''));
    $newsletterPrivacy = $newsletterPrivacyUrl !== ''
        ? '<a href="'.e($newsletterPrivacyUrl).'" class="link" target="_blank" rel="noopener">'.e(__('privacy policy')).'</a>'
        : '';
    $newsletterConsent = __('Subscribed with the form ":heading".', ['heading' => $newsletterHeading ?: __('Subscribe')]);
    $newsletterForm = view('torgash.newsletter::blocks.form', [
        'newsletterSource' => 'block',
        'newsletterButton' => $newsletterButton,
        'newsletterPlaceholder' => trim((string) ($settings['placeholder'] ?? '')) ?: null,
        'newsletterConsent' => $newsletterConsent,
    ])->render();
    $newsletterOwn = trim((string) ($settings['template'] ?? ''));
@endphp
<div class="torgash-block torgash-block-newsletter" data-newsletter>
    @if ($newsletterOwn !== '')
        @php
            // The block's own words first, hidden behind markers the shop's
            // placeholders cannot touch — `{form}` is also a shop placeholder
            // ({form:code}, the shop's own forms) and would be taken by it.
            $newsletterTokens = [
                '{form}' => $newsletterForm,
                '{heading}' => e($newsletterHeading),
                '{text}' => e($newsletterText),
                '{button}' => e($newsletterButton ?: __('Subscribe')),
                '{privacy_link}' => $newsletterPrivacy,
                '{subscribers}' => e((string) \Torgash\Newsletter\Models\Subscriber::where('status', 'subscribed')->count()),
            ];
            $newsletterMarkers = [];
            $newsletterSeed = bin2hex(random_bytes(4));
            foreach (array_keys($newsletterTokens) as $newsletterIndex => $newsletterToken) {
                $newsletterMarkers[$newsletterToken] = '[[newsletter-'.$newsletterIndex.'-'.$newsletterSeed.']]';
            }
            $newsletterHtml = expand_placeholders(strtr($newsletterOwn, $newsletterMarkers), $zoneContext ?? []);
            $newsletterHtml = strtr($newsletterHtml, array_combine(array_values($newsletterMarkers), array_values($newsletterTokens)));
        @endphp
        {!! $newsletterHtml !!}
    @else
        <div class="flex flex-col md:flex-row md:items-center gap-4 md:gap-8">
            <div class="flex-1 min-w-0">
                <div class="font-semibold text-lg">{{ $newsletterHeading ?: __('News and offers') }}</div>
                @if ($newsletterText !== '')
                    <p class="text-sm opacity-70 mt-1">{{ $newsletterText }}</p>
                @endif
            </div>
            <div class="md:w-96 flex flex-col gap-1.5">
                {!! $newsletterForm !!}
                @if ($newsletterPrivacy !== '')
                    <p class="text-xs opacity-60">{!! __('By subscribing you agree to the :link.', ['link' => $newsletterPrivacy]) !!}</p>
                @endif
            </div>
        </div>
    @endif
</div>
