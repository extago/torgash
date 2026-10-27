@php
    use Torgash\Enquiries\EnquiriesServiceProvider as Enquiries;

    $callbackName = Enquiries::CALLBACK_MODAL;
@endphp

<x-modal :name="$callbackName" :title="__('Request a callback')" icon="phone-call" size="sm">
    @include('torgash.enquiries::partials.form', ['enquiryKind' => 'callback'])
</x-modal>

{{-- Anything at all can open this dialog by linking to #enquiry-callback —
     including the {callback_link} token in an admin's own HTML block, which is
     the whole reason the anchor form exists: an <a href> cannot call
     showModal(), and a javascript: href would be both ugly and the first thing
     a content security policy blocks.

     Delegated from the document, so a link htmx swaps in later still works. --}}
<script nonce="{{ csp_nonce() }}">
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('a[href="#{{ $callbackName }}"], [data-enquiry-callback]');

        if (! trigger) {
            return;
        }

        var dialog = document.getElementById('modal-{{ $callbackName }}');

        if (dialog) {
            event.preventDefault();
            dialog.showModal();
        }
    });
</script>
