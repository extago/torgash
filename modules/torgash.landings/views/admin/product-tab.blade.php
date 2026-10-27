{{-- The product form's "Landings" tab (inside the product's form: saved with
     it through catalog.product.saved). `landings_form` says the answers are here. --}}
<div class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4">
        <input type="hidden" name="landings_form" value="1">
        <div>
            <h3 class="font-semibold">{{ __('Landings') }}</h3>
            <p class="text-sm opacity-70 mt-1">{{ __('Which landings this product shows, and why. "As the landing says" follows its own conditions; the other two answer for this product alone.') }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ __('Landing') }}</th>
                        <th>{{ __('Here') }}</th>
                        <th>{{ __('For this product') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $landingRow)
                        <tr>
                            <td>
                                <a href="{{ route('admin.landings.edit', $landingRow['landing']) }}" target="_blank" class="link link-hover">{{ $landingRow['landing']->name }}</a>
                                @unless ($landingRow['landing']->isRunning())
                                    <span class="badge badge-ghost badge-sm ml-1">{{ __('Switched off or out of its dates') }}</span>
                                @endunless
                            </td>
                            <td class="text-sm">
                                @if ($landingRow['shown'])
                                    <span class="text-success">{{ __('Shown now') }}</span>
                                @else
                                    <span class="opacity-60">{{ __('Not on the page') }}</span>
                                @endif
                                <span class="block text-xs opacity-60">{{ $landingRow['why'] }}</span>
                            </td>
                            <td>
                                <select name="landings[{{ $landingRow['landing']->id }}]" class="select select-bordered select-sm">
                                    <option value="auto" @selected($landingRow['answer'] === 'auto')>{{ __('As the landing says') }}</option>
                                    <option value="picked" @selected($landingRow['answer'] === 'picked')>{{ __('Show it here') }}</option>
                                    <option value="excluded" @selected($landingRow['answer'] === 'excluded')>{{ __('Never here') }}</option>
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
