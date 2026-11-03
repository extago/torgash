@extends('admin.layout')

@section('title', __('System'))

{{-- Backup storages (PLAN §67.1): where the plans keep their backups. --}}
@section('content')
    <div class="flex flex-col gap-4">
        @include('admin.system._tabs')

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm opacity-70 max-w-2xl">{{ __('Where backups are kept: a folder on this server, the shop\'s own disk or a bucket. A copy on this server alone does not survive the server — add a storage somewhere else.') }}</p>
                    <a href="{{ route('admin.system.backup-destinations.create') }}" class="btn btn-primary btn-sm">+ {{ __('New storage') }}</a>
                </div>

                <table class="table admin-cards">
                    <thead>
                        <tr>
                            <th>{{ __('Storage') }}</th>
                            <th>{{ __('Where') }}</th>
                            <th>{{ __('Plans') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($destinations as $destination)
                            @php
                                $where = match ($destination->driver) {
                                    'disk' => ($destination->setting('disk') === 'private' ? __('Private files') : __('This server')).' / '.$destination->setting('root'),
                                    'folder' => $destination->setting('path'),
                                    's3' => trim(($destination->setting('endpoint') ? parse_url($destination->setting('endpoint'), PHP_URL_HOST).' · ' : '').$destination->setting('bucket').'/'.$destination->setting('root'), '/'),
                                    'sftp', 'ftp' => rtrim($destination->driver.'://'.$destination->setting('username').'@'.$destination->setting('host').'/'.$destination->setting('root'), '/'),
                                    'webdav' => rtrim($destination->setting('url').'/'.$destination->setting('root'), '/'),
                                    default => isset($extensions[$destination->driver]) ? $extensions[$destination->driver]->where($destination) : '',
                                };
                                if ($destination->path_template !== '') {
                                    $where .= '/'.$destination->path_template;
                                }
                                if (in_array($destination->setting('proxy_type'), ['http', 'socks5'], true)) {
                                    $where .= ' · '.__('through :proxy', ['proxy' => $destination->setting('proxy_host')]);
                                }
                            @endphp
                            <tr>
                                <td data-cell="title">
                                    <a href="{{ route('admin.system.backup-destinations.edit', $destination) }}" class="link link-hover font-medium">{{ $destination->name }}</a>
                                    @if ($destination->system)
                                        <span class="badge badge-sm badge-ghost ml-1" title="{{ __('Updates keep their backup here.') }}">{{ __('built in') }}</span>
                                    @endif
                                    <div class="text-xs opacity-60">{{ __($drivers[$destination->driver] ?? $destination->driver) }}</div>
                                    @if ($destination->secretsLost())
                                        <div class="text-xs text-error">{{ __('The password cannot be read on this server (another APP_KEY) — type it again.') }}</div>
                                    @endif
                                </td>
                                <td data-cell="field" data-label="{{ __('Where') }}" class="font-mono text-xs break-all">{{ $where }}</td>
                                <td data-cell="field" data-label="{{ __('Plans') }}" class="text-sm tabular-nums">{{ $destination->plans_count ?: '—' }}</td>
                                <td data-cell="actions" class="text-end whitespace-nowrap w-px">
                                    <div class="inline-flex items-center gap-1">
                                        <form method="POST" action="{{ route('admin.system.backup-destinations.test', $destination) }}" class="inline">
                                            @csrf
                                            <button class="btn btn-ghost btn-sm" title="{{ __('Write, read back and delete a small file') }}">{{ __('Check') }}</button>
                                        </form>
                                        <a href="{{ route('admin.system.backup-destinations.edit', $destination) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" size="sm" /></a>
                                        <span class="inline-block w-px h-5 bg-base-300 mx-1 align-middle" aria-hidden="true"></span>
                                        @if ($destination->system)
                                            <span class="btn btn-ghost btn-sm btn-square btn-disabled" title="{{ __('Updates keep their backup here — it cannot be deleted.') }}"><x-icon name="trash-2" size="sm" /></span>
                                        @else
                                            <form method="POST" action="{{ route('admin.system.backup-destinations.destroy', $destination) }}" class="inline"
                                                  data-confirm="{{ __('Delete the storage ":storage"? The backups already in it stay where they are.', ['storage' => $destination->name]) }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
