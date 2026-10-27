{{-- "Your town", once per page (core.footer): an empty native dialog the
     header's button fills over HTMX and then opens. From the bottom on a phone,
     in the middle on a desktop. No <form method="dialog"> — the body holds a
     form of its own, and a form inside a form is this project's known trap;
     the backdrop is a button. --}}
<dialog id="torgash-location-dialog" class="modal modal-bottom sm:modal-middle" aria-labelledby="torgash-location-title">
    <div class="modal-box p-0 overflow-hidden sm:max-w-md max-h-[92vh] flex flex-col" id="torgash-location-body"></div>
    <button type="button" class="modal-backdrop" tabindex="-1" aria-hidden="true" data-location-close></button>
</dialog>
{{-- What the window shows the moment it is asked for, until its body
     arrives: the window opens at once instead of after the answer. --}}
<template id="torgash-location-skeleton">
    <div class="p-5 flex flex-col gap-4" aria-busy="true">
        <div class="skeleton h-6 w-1/2"></div>
        <div class="skeleton h-10 w-full"></div>
        <div class="flex flex-wrap gap-2">
            <div class="skeleton h-8 w-24"></div><div class="skeleton h-8 w-20"></div><div class="skeleton h-8 w-28"></div><div class="skeleton h-8 w-16"></div>
        </div>
        <div class="skeleton h-4 w-3/4"></div>
        <div class="skeleton h-4 w-2/3"></div>
    </div>
</template>
<script nonce="{{ csp_nonce() }}">
    (() => {
        const dialog = () => document.getElementById('torgash-location-dialog');

        // Asked for while closed: open now, with the skeleton, and let the
        // answer replace it — not a click that seems to do nothing for a beat.
        document.addEventListener('htmx:beforeRequest', (e) => {
            const target = e.detail.target;
            if (target && target.id === 'torgash-location-body' && ! dialog().open) {
                target.replaceChildren(document.getElementById('torgash-location-skeleton').content.cloneNode(true));
                dialog().showModal();
            }
        });

        document.addEventListener('htmx:afterSwap', (e) => {
            if (e.target && e.target.id === 'torgash-location-body' && ! dialog().open) {
                dialog().showModal();
            }
        });

        document.addEventListener('click', (e) => {
            if (e.target.closest('[data-location-close]')) {
                e.preventDefault();
                dialog().close();
            }
        });
    })();
</script>
