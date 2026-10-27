{{-- The button a "load on click" snippet waits behind (PLAN §49): the
     service's script — a chat, usually — is fetched only once it is pressed,
     and pressing it is the visitor asking for it. Above the phone's tab bar. --}}
<button type="button" data-consent-click-button data-target="{{ $clickTarget }}"
        class="fixed z-40 right-4 bottom-20 lg:bottom-6 btn btn-primary rounded-full shadow-lg gap-2">
    <x-icon name="message-circle" /> {{ $clickLabel }}
</button>
