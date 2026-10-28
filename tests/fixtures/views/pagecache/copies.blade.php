@php app('pctest.draws')->copies++; @endphp
<u>{{ implode(',', (array) session('pctest.copies', [])) }}</u>
