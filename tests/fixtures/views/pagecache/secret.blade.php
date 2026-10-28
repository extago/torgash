@php app('pctest.draws')->secret++; @endphp
<b>{{ session('pctest.secret', 'nobody') }}</b>
