{{-- Stands in the payment methods list's row of actions, level with its tabs: full size, like the buttons beside it. --}}
<a href="{{ route('admin.payments.log') }}" class="btn btn-ghost gap-1.5">
    <x-icon name="receipt-text" size="sm" /> {{ __('Payment log') }}
</a>
