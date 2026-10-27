{{-- The loops: one query shape asked again and again while a single page
     was built, with the line that asked. $speedRepeated from Reports::repeated(). --}}
@if ($speedRepeated->isNotEmpty())
    <div class="card bg-base-100 border border-warning/40 shadow-sm">
        <div class="card-body gap-3">
            <div>
                <h2 class="card-title text-base"><x-icon name="repeat" class="text-warning" /> {{ __('Asked again and again (N+1)') }}</h2>
                <p class="text-xs opacity-60">{{ __('The same query repeated while one page was built — nearly always a loop fetching one row at a time where one query for all of them would do. "Asked from" is the line to look at; a template means something on the page loads its data by itself.') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th class="text-right">{{ __('Times on one page') }}</th>
                            <th>{{ __('Page') }}</th>
                            <th>{{ __('Asked from') }}</th>
                            <th>{{ __('Query') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($speedRepeated as $q)
                            <tr class="align-top">
                                <td class="text-right tabular-nums font-semibold text-warning">×{{ $q->max_repeat }}</td>
                                <td class="font-mono text-xs break-all">
                                    <a href="{{ route('admin.speed.route', ['r' => $q->route]) }}" class="link link-hover">{{ $q->route }}</a>
                                </td>
                                <td class="font-mono text-xs break-all">{{ $q->caller ?? '—' }}</td>
                                <td class="font-mono text-xs opacity-70 break-all max-w-md">{{ \Illuminate\Support\Str::limit($q->sql, 240) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
