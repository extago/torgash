@extends('admin.layout')

@section('title', __('File storage'))

{{--
    Where the shop's files live (App\Http\Controllers\Admin\StorageController):
    read-only — the choice and the keys are in .env, and moving is a command.
--}}
@section('content')
    @php
        $mb = fn (int $bytes) => number_format($bytes / 1048576, 1, ',', ' ').' MB';
        $onS3 = $storage['kind'] === 's3';
    @endphp

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_26rem] items-start">
        <div class="flex flex-col gap-4 min-w-0">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="w-11 h-11 rounded-box bg-primary/10 text-primary grid place-items-center">
                                <x-icon :name="$onS3 ? 'cloud' : 'hard-drive'" size="lg" />
                            </span>
                            <div>
                                <div class="font-semibold">{{ $onS3 ? __('S3-compatible bucket') : __('A folder on this server') }}</div>
                                <div class="text-sm opacity-60">
                                    {{ $onS3
                                        ? __('Photos and uploads are kept in object storage and read from its address.')
                                        : __('Photos and uploads are kept on the server\'s own disk and served from /storage.') }}
                                </div>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('admin.storage.check') }}">
                            @csrf
                            <button class="btn btn-sm gap-1.5"><x-icon name="plug-zap" size="sm" /> {{ __('Check the connection') }}</button>
                        </form>
                    </div>

                    @if ($storage['private_exposed'])
                        <div class="alert alert-warning text-sm">
                            <x-icon name="triangle-alert" />
                            <span>{{ __('Private files are in the same bucket as the public ones. If that bucket is public as a whole (R2, B2), an enquiry\'s attachment can be opened by anybody who guesses its address — give them a private bucket of their own (AWS_PRIVATE_BUCKET).') }}</span>
                        </div>
                    @endif

                    @if ($probes)
                        <div class="flex flex-col gap-2">
                            @foreach ($probes as $probeDisk => $probe)
                                <div @class(['alert text-sm py-2', 'alert-success' => $probe['ok'], 'alert-error' => ! $probe['ok']])>
                                    <x-icon :name="$probe['ok'] ? 'circle-check' : 'circle-x'" />
                                    <span>
                                        <b>{{ $probeDisk === 'public' ? __('Public files') : __('Private files') }}:</b>
                                        {{ $probe['ok'] ? __('a file was written, read back and deleted in :ms ms.', ['ms' => $probe['ms']]) : $probe['error'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <tbody>
                                @if ($onS3)
                                    <tr><th class="w-48 font-normal opacity-70">{{ __('Bucket') }}</th><td class="font-mono text-sm">{{ $storage['bucket'] ?: '—' }}</td></tr>
                                    <tr><th class="font-normal opacity-70">{{ __('Bucket for private files') }}</th><td class="font-mono text-sm">{{ $storage['private_bucket'] ?: '—' }}</td></tr>
                                    <tr><th class="font-normal opacity-70">{{ __('Endpoint') }}</th><td class="font-mono text-sm break-all">{{ $storage['endpoint'] ?: __('AWS by region') }}</td></tr>
                                    <tr><th class="font-normal opacity-70">{{ __('Region') }}</th><td class="font-mono text-sm">{{ $storage['region'] ?: '—' }}</td></tr>
                                    <tr><th class="font-normal opacity-70">{{ __('Public address') }}</th><td class="font-mono text-sm break-all">{{ $storage['public_url'] ?: __('not set — pictures will have no address') }}</td></tr>
                                    <tr><th class="font-normal opacity-70">{{ __('Access key') }}</th><td class="font-mono text-sm">{{ $storage['key'] ?: '—' }}</td></tr>
                                    <tr><th class="font-normal opacity-70">{{ __('Per-file access rights') }}</th><td class="text-sm">{{ $storage['acl'] ? __('yes (AWS)') : __('no — the bucket is public as a whole (Cloudflare R2)') }}</td></tr>
                                @endif
                                <tr><th class="w-48 font-normal opacity-70">{{ __('Public files') }}</th><td class="font-mono text-sm break-all">{{ $storage['public_root'] }}</td></tr>
                                <tr><th class="font-normal opacity-70">{{ __('Private files') }}</th><td class="font-mono text-sm break-all">{{ $storage['private_root'] }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('What is stored') }}</h3>
                    <div class="grid sm:grid-cols-2 gap-3">
                        @foreach (['public' => [__('Public files'), __('product photos, pictures in texts, review photos, labels')], 'private' => [__('Private files'), __('enquiry attachments, files customers attach to options')]] as $usageDisk => [$usageLabel, $usageHint])
                            <div class="rounded-box bg-base-200/60 px-4 py-3">
                                <div class="text-xs opacity-60">{{ $usageLabel }}</div>
                                <div class="text-xl font-bold tabular-nums">{{ number_format($usage[$usageDisk]['files'] ?? 0, 0, ',', ' ') }}</div>
                                <div class="text-xs opacity-60">{{ $mb($usage[$usageDisk]['bytes'] ?? 0) }} · {{ $usageHint }}</div>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs opacity-60">{{ __('Counted from the records that know about the files, without thumbnails. Theme pictures and category icons are on the public side too.') }}</p>
                </div>
            </section>
        </div>

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3 text-sm">
                <h3 class="font-semibold">{{ __('How to move to a bucket') }}</h3>
                <p class="opacity-70">{{ __('The storage is chosen in the .env file, not here: access keys do not belong in the database, and switching before the files are copied would take every picture off the site.') }}</p>
                <ol class="list-decimal pl-5 flex flex-col gap-2 opacity-90">
                    <li>{{ __('Create a bucket (AWS S3, Cloudflare R2, Backblaze B2, MinIO…) and an access key for it.') }}</li>
                    <li>
                        {{ __('Add to .env:') }}
                        <pre class="mt-1 p-2 rounded-box bg-base-200 text-xs overflow-x-auto">AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_BUCKET=…
AWS_PRIVATE_BUCKET=…
AWS_DEFAULT_REGION=auto
AWS_ENDPOINT=https://…
TORGASH_STORAGE_URL=https://cdn…
TORGASH_STORAGE_ACL=false</pre>
                        <ul class="text-xs opacity-70 mt-1 flex flex-col gap-0.5">
                            <li><code>TORGASH_STORAGE_URL</code> — {{ __('the public address of the bucket or of a CDN in front of it') }}</li>
                            <li><code>TORGASH_STORAGE_ACL</code> — {{ __('false for Cloudflare R2, true for AWS') }}</li>
                            <li><code>AWS_PRIVATE_BUCKET</code> — {{ __('a second, private bucket for attachments; required where a bucket is public as a whole (R2, B2)') }}</li>
                        </ul>
                    </li>
                    <li>
                        {{ __('Copy the files:') }}
                        <pre class="mt-1 p-2 rounded-box bg-base-200 text-xs">php artisan torgash:files-copy --to=s3</pre>
                    </li>
                    <li>{!! __('Switch: :line — and check the site and this screen.', ['line' => '<code class="text-xs">TORGASH_STORAGE=s3</code>']) !!}</li>
                    <li>{{ __('The local copies stay where they were until you delete them; the way back is --to=local.') }}</li>
                </ol>
            </div>
        </section>
    </div>
@endsection
