{{-- What the form says back, in place of the form. --}}
<div role="status" @class(['alert text-sm', 'alert-success' => $newsletterOk, 'alert-warning' => ! $newsletterOk])>
    <x-icon :name="$newsletterOk ? 'mail-check' : 'triangle-alert'" size="sm" />
    <span>{{ $newsletterText }}</span>
</div>
