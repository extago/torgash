{{--
    The one check that has to be asked for, unfolded under its own statement.

    It IS an invariant — "the web server does not execute PHP where uploads are
    stored" — and its result is a finding like any other; what it needs and the
    others do not is its button and the configuration to change
    when it fails, which is here.
--}}
<p class="text-sm opacity-70 max-w-4xl">
    {{ __('A file written past the image pipeline — by an import, a parser, somebody else\'s archive — is only a shell if the server agrees to run it. The one way to know is to try: this writes a harmless script where uploads go, asks the server for it, and deletes it again.') }}
</p>
<div class="flex flex-wrap items-center gap-3">
    <button type="submit" form="invariants-run" name="key" value="{{ \Torgash\Guard\Http\Admin\InvariantsController::SELF_TEST }}" class="btn btn-sm gap-1.5">
        <x-icon name="scan-search" size="sm" />
        {{ __('Run the self-test') }}
    </button>
    <span class="text-xs opacity-40 font-mono">{{ __('Server: :server', ['server' => $server]) }}</span>
</div>

{{-- Open when the test has failed: then the lines are what the reader needs. --}}
<details class="text-sm" @if ($selfTestFailed ?? false) open @endif>
    <summary class="cursor-pointer opacity-60 hover:opacity-100 w-fit">
        {{ __('What to change, if it is executed') }}
    </summary>

    <div class="mt-3 flex flex-col gap-3">
        <p class="opacity-70 max-w-4xl">
            {{ __('The engine cannot write your web server\'s configuration — under OSPanel the Caddyfile is generated and an edit would not survive a restart — so here are the lines. Adapt the socket to yours, then press the button again: the self-test is the proof, not the config file.') }}
        </p>

        @foreach ($snippets as $name => $snippet)
            <div>
                <div class="text-xs font-medium opacity-60 mb-1">{{ $name }}</div>
                <pre class="bg-base-200 rounded-box p-3 text-xs overflow-x-auto"><code>{{ $snippet }}</code></pre>
            </div>
        @endforeach
    </div>
</details>
