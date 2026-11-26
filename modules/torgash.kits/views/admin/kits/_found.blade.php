{{-- What the codes typed above stand for (KitsController::lookup): a line per
     code, in the order typed — name, price, stock, or "not found", so an SKU
     is never typed blind. Inside the slot's x-for: reads `row.found`. --}}
<ul class="flex flex-col gap-1 mt-1" x-show="row.found.length" x-cloak>
    <template x-for="item in row.found" :key="item.code">
        <li class="flex flex-wrap items-baseline gap-x-2 text-xs">
            <template x-if="item.found">
                <span class="contents">
                    <a :href="item.url" target="_blank" class="link link-hover font-medium min-w-0 truncate max-w-full" x-text="item.name"></a>
                    <span class="font-mono opacity-60" x-text="item.sku"></span>
                    <span x-text="item.price"></span>
                    <span :class="item.stock > 0 ? 'text-success' : 'text-error'"
                          x-text="item.stock > 0 ? @js(__('In stock')) + ': ' + item.stock : @js(__('Out of stock'))"></span>
                    <span class="badge badge-ghost badge-sm" x-show="! item.active">{{ __('Disabled') }}</span>
                </span>
            </template>
            <template x-if="! item.found">
                <span class="text-error"><span class="font-mono" x-text="item.code"></span> — {{ __('no such product') }}</span>
            </template>
        </li>
    </template>
</ul>
