{{-- The subscription form itself — the block's {form}, the basket's offer.
     htmx swaps the form for the answer; without script it posts and comes
     back to the page. Named newsletter*: an @include hands a partial every
     variable its caller has. --}}
<div data-newsletter-form>
    <form method="POST" action="{{ route('newsletter.subscribe') }}"
          hx-post="{{ route('newsletter.subscribe') }}" hx-target="closest [data-newsletter-form]" hx-swap="innerHTML"
          class="flex flex-col gap-2">
        @csrf
        <input type="hidden" name="source" value="{{ $newsletterSource ?? 'block' }}">
        <input type="hidden" name="consent_text" value="{{ $newsletterConsent ?? '' }}">
        <x-form-guard form="newsletter" />
        <div class="join w-full">
            <input type="email" name="email" required autocomplete="email"
                   value="{{ auth()->user()?->email }}"
                   placeholder="{{ $newsletterPlaceholder ?? __('Your email') }}" aria-label="{{ __('Your email') }}"
                   class="input input-bordered join-item flex-1 min-w-0">
            <button class="btn btn-primary join-item">{{ ($newsletterButton ?? '') !== '' ? $newsletterButton : __('Subscribe') }}</button>
        </div>
    </form>
</div>
