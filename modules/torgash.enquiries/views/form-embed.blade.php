{{-- A form the shop built, placed INSIDE a text as {form:code} (PLAN §48) —
     a page, a blog article, an HTML block. `torgash-rich-embed` keeps the
     text's link style off its controls; `data-text-skip` keeps it out of the
     page's .md rendition, where a form means nothing. --}}
<div data-text-skip class="torgash-rich-embed my-6 card bg-base-100 border border-base-300 max-w-xl">
    <div class="card-body gap-3">
        <h2 class="card-title text-lg mt-0">{{ $embedForm->t('name') }}</h2>
        @include('torgash.enquiries::partials.form', [
            'enquiryKind' => $embedForm->kind(),
            'enquiryNote' => $embedForm->t('intro'),
        ])
    </div>
</div>
