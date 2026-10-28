<?php

return [
    // The engine's own version — what the System page reports and what an
    // update server will one day be asked about (PLAN §45).
    'version' => '0.3.3',

    // Where pg_dump / pg_restore live, for backups (PLAN §45). Empty means
    // "on the PATH", which is how a Linux server has them; OSPanel keeps them
    // in its module folder, so a Windows dev box names it in .env.
    'pg_bin' => env('TORGASH_PG_BIN', ''),

    // The stylesheet is BUILT from the templates (Tailwind 4 + daisyUI 5), so
    // a module or a theme bringing a class nobody else uses needs a rebuild on
    // the shop's own server (PLAN §45.2). The builder is tailwind-cli-extra
    // (MIT), pinned: the release that built the shipped app.css and the
    // sha256 of each platform's file, checked before a downloaded one is ever
    // run. `builder` names an existing binary instead (a server that keeps
    // its tools elsewhere).
    'styles' => [
        'builder' => env('TORGASH_STYLE_BUILDER', ''),
        'release' => 'v2.10.13',
        'url' => 'https://github.com/dobicinaitis/tailwind-cli-extra/releases/download/{release}/tailwindcss-extra-{platform}',
        'sha256' => [
            'windows-x64.exe' => '11c3ef953e219866a282e8afd8a7b228b642eba97f6eceb522363ec4eb8d5f68',
            'linux-x64' => '1a27e698b7fa198f1344da8f8a0cb51564d0569c2d1d5404095ef03ee4370bfc',
            'linux-x64-musl' => '7ed625f86c1250ec95819d865a2f5af40029c165e908bb937834a8298b4a8702',
            'linux-arm64' => '53bd34bf9e1c85ca3e776d0615277596cb1929471d51fd9f0e770a7ae292ff67',
            'linux-arm64-musl' => 'ee2c5cd18b1545b30f3574103565abd24016cf3fd0f84b4693f35ec703ec9667',
            'macos-x64' => 'd01758498624ba68d0b35a5d2e900664174e15db4f65a73e040f7dbc52130505',
            'macos-arm64' => '2af26fd5b30d77d82451d9ad0c12d5cf94d0ec97c8b3e80e453ac8ea7d9f0e04',
        ],
    ],

    // Updates (PLAN §45.7): packages are signed with Ed25519 on the machine
    // releases are built on. A shop holds only the PUBLIC keys — a list, so a
    // key can be changed by one release that ships both. `release_key` is the
    // path to the SECRET key file and is set only where releases are built.
    'updates' => [
        'public_keys' => [
            // Extago, made 2026-10-16.
            'i4CiKI1vJtpB4ncjkc5PVKlYUOFKnPjlCHKQIV53KeA=',
        ],
        'release_key' => env('TORGASH_RELEASE_KEY', ''),
        // The update delivery service (PLAN §45.8). Trusted with availability
        // only: what it offers must still be signed by a key above.
        'server' => env('TORGASH_UDS_URL', 'https://extago.com/uds/torgash/'),
        'verify_tls' => (bool) env('TORGASH_UDS_VERIFY_TLS', true),
        // Set only on the machine that publishes releases.
        'publish_token' => env('TORGASH_UDS_TOKEN', ''),
        // A GitHub repository (owner/repo) whose Releases are asked when the
        // server above does not answer — for shops that cannot reach it.
        // Built in, like the server: every release goes to both.
        // The update address on the System page may also be a GitHub one.
        'github' => env('TORGASH_GITHUB_REPO', 'extago/torgash'),
        // Only for a private repository.
        'github_token' => env('TORGASH_GITHUB_TOKEN', ''),
        // Set only on the machine that publishes releases (torgash:release
        // --github): a token that may write the repository's contents.
        'github_publish_token' => env('TORGASH_GITHUB_PUBLISH_TOKEN', ''),
    ],

    // Drop code caches, restart workers and rebuild the stylesheet whenever
    // the code changes (a module installed, enabled, updated) — PLAN §45.4.
    // Off in the test suite, which installs every module on every rebuild.
    'refresh_on_change' => (bool) env('TORGASH_REFRESH_ON_CHANGE', true),

    // The language the shop's content is written in first (content_base_locale()):
    // required in every translatable field, what a missing translation falls
    // back to. Set by the installer to the shop's language. Empty: the
    // translator's fallback (APP_FALLBACK_LOCALE), English.
    'content_locale' => env('TORGASH_CONTENT_LOCALE', ''),

    // FrankenPHP's admin endpoint that restarts every worker at once, e.g.
    // http://localhost:2019/frankenphp/workers/restart — called from the
    // console after the code changes. Empty: workers notice by themselves,
    // one request later (App\Core\Support\WebWorkers).
    'worker_restart_url' => env('TORGASH_WORKER_RESTART_URL', ''),

    // Absolute paths to the extension directories. Core never writes outside var/.
    'modules_path' => base_path('modules'),
    'themes_path' => base_path('themes'),

    // Fallback theme when a storefront does not define one.
    'default_theme' => 'default',

    // What the installer offers (App\Core\Install\Installer, PLAN §54):
    // named sets of modules, data rather than code. A set lists what the
    // owner asked for; whatever those need is installed with them. `all` is
    // every module shipped with the engine except the example one.
    'bundles' => [
        'minimal' => [
            'torgash.catalog', 'torgash.cart', 'torgash.checkout', 'torgash.payments', 'torgash.shipping',
            'torgash.media', 'torgash.menus', 'torgash.blocks', 'torgash.pages', 'torgash.editor',
            'torgash.seo', 'torgash.guard', 'torgash.privacy',
        ],
        'store' => [
            '@minimal',
            'torgash.search', 'torgash.reviews', 'torgash.wishlist', 'torgash.compare', 'torgash.promotions',
            'torgash.banners', 'torgash.enquiries', 'torgash.snippets', 'torgash.locations', 'torgash.warehouses',
            'torgash.options', 'torgash.kits', 'torgash.blog', 'torgash.exim', 'torgash.speed',
        ],
        'all' => ['*'],
    ],

    // Who works the queue (letters, imports): `scheduler` — the cron line
    // that runs the scheduler also starts a short-lived worker every minute,
    // so a host with no long-running processes still sends its mail; or
    // `external` — a worker of its own (systemd, supervisor) does it.
    'queue_worker' => env('QUEUE_WORKER', 'scheduler'),

    // Never installed by a bundle: it exists to be copied.
    'example_modules' => ['torgash.hello'],

    // Safe mode for EVERY visitor (App\Core\Support\SafeMode): the engine's
    // own theme, no shop CSS, no code snippets. The emergency switch for a
    // storefront that a theme or a snippet has broken for everyone; staff can
    // turn the same thing on for themselves alone from the admin.
    'safe_mode' => (bool) env('TORGASH_SAFE_MODE', false),

    // Codes that prove an address (PLAN §44). Asking DNS whether an email's
    // domain takes mail catches "gmial.com" before a code is sent; a server
    // with no outbound DNS, or a test run, turns it off.
    'verification' => [
        'check_mail_domain' => (bool) env('TORGASH_CHECK_MAIL_DOMAIN', true),
    ],
];
