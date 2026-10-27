{{-- Under the basket (setting offer_in_cart): the same form, quietly. --}}
<div class="bg-base-100 border border-base-300 rounded-box p-5 flex flex-col md:flex-row md:items-center gap-3 md:gap-6" data-newsletter>
    <div class="flex items-center gap-3 flex-1 min-w-0">
        <x-icon name="mail-plus" size="lg" class="text-primary shrink-0" />
        <div>
            <div class="font-semibold">{{ __('News and offers by email') }}</div>
            <div class="text-sm opacity-70">{{ __('Discounts and new arrivals — now and then, never too often.') }}</div>
        </div>
    </div>
    <div class="md:w-96">
        @include('torgash.newsletter::blocks.form', ['newsletterSource' => 'cart', 'newsletterConsent' => __('Subscribed on the basket page.')])
    </div>
</div>
