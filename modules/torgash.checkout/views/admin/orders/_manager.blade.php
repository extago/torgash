{{-- Who in the shop is dealing with this order. Only staff accounts appear;
     the controller refuses anything else even if the form is tampered with. --}}
<form id="order-manager" class="flex items-center gap-2"
      hx-patch="{{ route('admin.orders.manager', $order) }}" hx-target="#order-manager" hx-swap="outerHTML">
    @csrf
    <select name="manager_id" class="select select-bordered w-full" data-submit-on-change>
        <option value="">{{ __('Not assigned') }}</option>
        @foreach ($managers as $manager)
            <option value="{{ $manager->id }}" @selected($order->manager_id === $manager->id)>{{ $manager->name }}</option>
        @endforeach
    </select>
</form>
