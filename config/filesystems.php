<?php

/*
 * Where the shop's own files live (Torgash): `public` is what anybody may see
 * (product photos, pictures in texts, review photos, theme and category
 * pictures), `private` is what the shop was sent and serves through a route
 * that asks who is asking (enquiry attachments, files a customer attached to
 * an option). Each is a local folder or an S3-compatible bucket — AWS, R2,
 * MinIO, Backblaze — chosen by TORGASH_STORAGE, never by code: every caller
 * goes through Storage::disk('public'|'private') and nothing reads a file by
 * its local path. The disk NAMES never change, because media rows store them.
 *
 * `local` stays a local folder whatever is chosen: it holds the engine's own
 * working files (exports, imports, exchange sessions), which belong on the
 * server that is doing the work.
 *
 * Credentials stay in .env on purpose — a secret in the settings table is in
 * every database dump. `files-local-*` / `files-s3-*` are both halves spelled
 * out, so `php artisan torgash:files-copy` can read one and write the other.
 */
$s3 = fn (string $root, bool $public) => array_filter([
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION') ?: 'auto',
    // Private files may live in a bucket of their own — and must, wherever a
    // bucket is published as a WHOLE (Cloudflare R2, Backblaze B2): there a
    // private file in the public bucket has an address anybody can guess,
    // media ids being sequential. Empty = the same bucket, under `private/`.
    'bucket' => $public ? env('AWS_BUCKET') : (env('AWS_PRIVATE_BUCKET') ?: env('AWS_BUCKET')),
    'root' => $root,
    // The address public files are READ from — the bucket's public domain or
    // a CDN in front of it. Private files have none: they are streamed.
    'url' => $public ? (env('TORGASH_STORAGE_URL') ?: env('AWS_URL')) : null,
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    // Per-object ACLs: AWS wants one on a public file; Cloudflare R2 has no
    // ACLs and publishes a bucket as a whole, so there this is switched off.
    'visibility' => $public && env('TORGASH_STORAGE_ACL', true) ? 'public' : null,
    'throw' => true,
    'report' => false,
], fn ($value) => $value !== null);

$localPublic = [
    'driver' => 'local',
    'root' => storage_path('app/public'),
    'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
    'visibility' => 'public',
    'throw' => false,
    'report' => false,
];

$localPrivate = [
    'driver' => 'local',
    // The folder `local` uses too: attachments written before the private
    // disk existed are already here, under the same paths.
    'root' => storage_path('app/private'),
    'throw' => false,
    'report' => false,
];

$onS3 = env('TORGASH_STORAGE', 'local') === 's3';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => $onS3 ? $s3('public', true) : $localPublic,

        'private' => $onS3 ? $s3('private', false) : $localPrivate,

        'files-local-public' => $localPublic,
        'files-local-private' => $localPrivate,
        'files-s3-public' => $s3('public', true),
        'files-s3-private' => $s3('private', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Only meaningful while `public` is a local folder: `/storage/...` is then
    | served straight by the web server.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
