@if ($askEnabled || $notifyEnabled)
    {{-- In the buy panel's row of small actions (cart.buy_box.links), beside
         "wishlist" and "compare": quiet controls rather than two more buttons —
         the page already has the one button it wants people to press. --}}
    <div class="contents">
        @if ($askEnabled)
            <x-modal :name="'enquiry-ask-'.$product->id"
                     :title="__('Ask about this product')"
                     icon="circle-help"
                     size="sm"
                     :trigger="__('Ask a question')"
                     trigger-icon="message-circle-question"
                     trigger-class="btn btn-ghost btn-sm btn-touch gap-1.5 px-2 font-normal">
                <p class="text-sm opacity-70 mb-3">{{ $product->t('name') }}</p>

                @include('torgash.enquiries::partials.form', [
                    'enquiryKind' => 'product_question',
                    'enquirySubjectId' => $product->id,
                ])
            </x-modal>
        @endif

        @if ($notifyEnabled)
            <x-modal :name="'enquiry-stock-'.$product->id"
                     :title="__('Tell me when it is back')"
                     icon="bell-ring"
                     size="sm"
                     :trigger="__('Tell me when it is back')"
                     trigger-icon="bell-ring"
                     trigger-class="btn btn-ghost btn-sm btn-touch gap-1.5 px-2 font-normal">
                @include('torgash.enquiries::partials.form', [
                    'enquiryKind' => 'back_in_stock',
                    'enquirySubjectId' => $product->id,
                    'enquiryNote' => __('Leave a phone number or an email and we will let you know as soon as it arrives.'),
                ])
            </x-modal>
        @endif
    </div>
@endif
