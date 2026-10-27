{{-- What replaces the form once it has been sent. Deliberately the same shape
     as the form it replaces, so nothing jumps: a person who just typed their
     phone number should see an answer where the button was. --}}
<div class="flex flex-col items-center gap-2 py-4 text-center">
    <x-icon name="check" size="xl" class="text-success" />
    <p class="font-medium">{{ $message }}</p>
</div>
