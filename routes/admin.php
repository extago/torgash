<?php

use App\Http\Controllers\Admin\AdministratorsController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CurrenciesController;
use App\Http\Controllers\Admin\CustomerGroupsController;
use App\Http\Controllers\Admin\CustomersController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LanguagesController;
use App\Http\Controllers\Admin\ModulesController;
use App\Http\Controllers\Admin\PermissionGroupsController;
use App\Http\Controllers\Admin\RedirectsController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StorefrontsController;
use App\Http\Controllers\Admin\TasksController;
use App\Http\Controllers\Admin\ThemesController;
use App\Http\Controllers\LocalizationController;
use Illuminate\Support\Facades\Route;

// Loaded by CoreServiceProvider with prefix "admin", name prefix "admin.".

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.attempt');

    // A lost password, and the page an invitation's link lands on (PLAN §41).
    Route::get('password/forgot', [\App\Http\Controllers\Admin\PasswordController::class, 'forgot'])->name('password.forgot');
    Route::post('password/forgot', [\App\Http\Controllers\Admin\PasswordController::class, 'send'])
        ->middleware('throttle:5,10')->name('password.email');
    Route::get('password/reset/{token}', [\App\Http\Controllers\Admin\PasswordController::class, 'edit'])->name('password.reset');
    Route::post('password/reset', [\App\Http\Controllers\Admin\PasswordController::class, 'update'])
        ->middleware('throttle:10,10')->name('password.update');
});

// A sign-in carried from another of the shop's addresses (App\Core\Access\Handoff):
// neither guest-only nor signed-in-only — whoever holds the ticket arrives as
// its owner, whatever this host's session was.
Route::get('handoff/{token}', \App\Http\Controllers\Admin\HandoffController::class)
    ->middleware('throttle:20,1')->where('token', '[A-Za-z0-9]{48}')->name('handoff');

Route::middleware(['auth', 'can:admin.access'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    // The storefront without the shop's theme, CSS and snippets — for this
    // person only (App\Core\Support\SafeMode).
    Route::post('safe-mode', \App\Http\Controllers\Admin\SafeModeController::class)->name('safe_mode');
    // Plain GET links (not a POST form inside a dropdown) — matches the usual
    // language switchers and sidesteps any click-inside-a-focus-dropdown fragility.
    Route::get('content-locale', [LocalizationController::class, 'setAdminContentLocale'])->name('content-locale.set');
    Route::get('interface-locale', [LocalizationController::class, 'setAdminLocale'])->name('interface-locale.set');
    // Which currency the admin READS money in. A third axis beside the two
    // languages above and, like them, a property of the person looking rather
    // than of anything stored — see admin_price().
    //
    // POST, unlike those two: a GET link that changes state is one a browser
    // may follow on its own, and Chrome's link prefetching duly set this before
    // anybody had clicked anything. The storefront's own currency switcher is a
    // POST form for the same reason.
    Route::post('currency', [LocalizationController::class, 'setAdminCurrency'])->name('currency.set');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    // One person's own arrangement of it — what they hide, what order they read.
    Route::post('dashboard/layout', [DashboardController::class, 'layout'])->name('dashboard.layout');
    // The sidebar folded into a strip of icons — this person's, remembered (PLAN §62).
    Route::post('preferences/sidebar', [\App\Http\Controllers\Admin\PreferencesController::class, 'sidebar'])->name('preferences.sidebar');
    // "Where is that set?" — screens and settings by the words for them.
    Route::get('search', [SearchController::class, 'index'])->name('search');
    // The bell's own refresh (App\Core\Admin\AdminNotifications).
    Route::get('notifications', [DashboardController::class, 'notifications'])->name('notifications');

    Route::get('modules', [ModulesController::class, 'index'])->name('modules');
    Route::get('modules/{id}/docs', [ModulesController::class, 'docs'])->name('modules.docs');
    Route::get('modules/{id}/logo', [ModulesController::class, 'logo'])->name('modules.logo');
    Route::get('modules/{id}/docs/media/{path}', [ModulesController::class, 'docsMedia'])
        ->where('path', '.*')->name('modules.docs.media');
    // Changing what the shop is made of is a permission of its own; putting new
    // code on the server (upload) is the super group's alone — see the controller.
    Route::middleware('can:modules.manage')->group(function () {
        Route::post('modules/upload', [ModulesController::class, 'upload'])->name('modules.upload');
        Route::post('modules/{id}/install', [ModulesController::class, 'install'])->name('modules.install');
        Route::post('modules/{id}/uninstall', [ModulesController::class, 'uninstall'])->name('modules.uninstall');
        Route::post('modules/{id}/toggle', [ModulesController::class, 'toggle'])->name('modules.toggle');
    });

    // Every module's settings — what the shop DOES — are not simply "in the admin".
    Route::middleware('can:settings.manage')->group(function () {
        Route::get('settings', [SettingsController::class, 'index'])->name('settings');
        Route::get('settings/{module}', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::post('settings/{module}', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/{module}/reset', [SettingsController::class, 'reset'])->name('settings.reset');
        // Where the shop's files live — read-only; the choice is in .env.
        Route::get('storage', [\App\Http\Controllers\Admin\StorageController::class, 'index'])->name('storage');
        Route::post('storage/check', [\App\Http\Controllers\Admin\StorageController::class, 'check'])->name('storage.check');
    });

    // Every code sent to prove an address — customers' own data.
    Route::get('verification-codes', [\App\Http\Controllers\Admin\VerificationCodesController::class, 'index'])
        ->middleware('can:customers.manage')->name('verification-codes');

    // Every letter the shop sends: its words, the frame, a preview (PLAN §41).
    Route::middleware('can:mail.manage')->prefix('letters')->name('letters.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\LettersController::class, 'index'])->name('index');
        Route::post('frame', [\App\Http\Controllers\Admin\LettersController::class, 'frame'])->name('frame');
        Route::post('server', [\App\Http\Controllers\Admin\LettersController::class, 'server'])->name('server');
        Route::get('{event}', [\App\Http\Controllers\Admin\LettersController::class, 'edit'])->where('event', '[a-z0-9_.]+')->name('edit');
        Route::post('{event}', [\App\Http\Controllers\Admin\LettersController::class, 'update'])->where('event', '[a-z0-9_.]+')->name('update');
        Route::match(['get', 'post'], '{event}/preview', [\App\Http\Controllers\Admin\LettersController::class, 'preview'])->where('event', '[a-z0-9_.]+')->name('preview');
        Route::post('{event}/test', [\App\Http\Controllers\Admin\LettersController::class, 'test'])->where('event', '[a-z0-9_.]+')->name('test');
    });

    // What the server is, and the backups (PLAN §45) — a backup is every
    // customer's data in one file, so not simply "in the admin".
    Route::middleware('can:system.manage')->prefix('system')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\SystemController::class, 'index'])->name('system');
        Route::post('audit', [\App\Http\Controllers\Admin\SystemController::class, 'audit'])->name('system.audit');
        Route::post('styles', [\App\Http\Controllers\Admin\SystemController::class, 'styles'])->name('system.styles');
        Route::post('demo/remove', [\App\Http\Controllers\Admin\SystemController::class, 'removeDemo'])->name('system.demo.remove');
        Route::post('jobs/prune', [\App\Http\Controllers\Admin\SystemController::class, 'pruneJobs'])->name('system.jobs.prune');
        Route::post('updates/check', [\App\Http\Controllers\Admin\SystemController::class, 'checkUpdates'])->name('system.updates.check');
        Route::post('updates/server', [\App\Http\Controllers\Admin\SystemController::class, 'updateServer'])->name('system.updates.server');
        Route::post('updates/install', [\App\Http\Controllers\Admin\SystemController::class, 'installUpdate'])->name('system.updates.install');
        // By hand, for a shop that cannot download from the update server.
        Route::post('updates/upload', [\App\Http\Controllers\Admin\SystemController::class, 'uploadUpdate'])->name('system.updates.upload');
        Route::post('updates/install-file', [\App\Http\Controllers\Admin\SystemController::class, 'installUpdateFile'])->name('system.updates.install_file');
        Route::delete('updates/file', [\App\Http\Controllers\Admin\SystemController::class, 'deleteUpdateFile'])->name('system.updates.delete_file');
        Route::get('backups', [\App\Http\Controllers\Admin\SystemController::class, 'backups'])->name('system.backups');
        Route::post('backups', [\App\Http\Controllers\Admin\SystemController::class, 'backup'])->name('system.backups.create');
        Route::post('backups/settings', [\App\Http\Controllers\Admin\SystemController::class, 'saveBackupSettings'])->name('system.backups.settings');
        Route::get('backups/{name}', [\App\Http\Controllers\Admin\SystemController::class, 'download'])->where('name', '[A-Za-z0-9._-]+')->name('system.backups.download');
        Route::delete('backups/{name}', [\App\Http\Controllers\Admin\SystemController::class, 'destroy'])->where('name', '[A-Za-z0-9._-]+')->name('system.backups.destroy');
    });

    // Where the cache and the sessions live — written to .env.
    Route::middleware('can:cache.manage')->group(function () {
        Route::get('cache', [\App\Http\Controllers\Admin\CacheController::class, 'index'])->name('cache');
        Route::post('cache', [\App\Http\Controllers\Admin\CacheController::class, 'update'])->name('cache.update');
        Route::post('cache/clear', [\App\Http\Controllers\Admin\CacheController::class, 'clear'])->name('cache.clear');
    });

    // Which addresses answer, in what language and currency, and what the
    // shop's books are kept in — not simply "in the admin".
    Route::middleware('can:storefronts.manage')->group(function () {
        Route::patch('storefronts/{storefront}/status', [StorefrontsController::class, 'status'])->name('storefronts.status');
        Route::post('storefronts/{storefront}/default', [StorefrontsController::class, 'makeDefault'])->name('storefronts.default');
        Route::post('storefronts/{storefront}/preview-link', [StorefrontsController::class, 'newPreviewLink'])->name('storefronts.preview_link');
        Route::resource('storefronts', StorefrontsController::class)->except(['show']);
    });

    // A language adds a copy of every address and a column of every text.
    Route::middleware('can:languages.manage')->group(function () {
        Route::patch('languages/{language}/status', [LanguagesController::class, 'status'])->name('languages.status');
        Route::post('languages/{language}/base', [LanguagesController::class, 'makeBase'])->name('languages.base');
        Route::resource('languages', LanguagesController::class)->except(['show']);
    });

    // A rate reprices every product priced in that currency.
    Route::middleware('can:currencies.manage')->group(function () {
        // Before the resource: `currencies/refresh` would otherwise be read as
        // `currencies/{currency}` and go looking for a currency called "refresh".
        Route::post('currencies/refresh', [CurrenciesController::class, 'refresh'])->name('currencies.refresh');
        Route::patch('currencies/{currency}/status', [CurrenciesController::class, 'status'])->name('currencies.status');
        Route::resource('currencies', CurrenciesController::class)->except(['show']);
    });

    // Old addresses and where they lead now. Core's, like the table: a shop has
    // addresses whether or not it has a catalogue.
    Route::middleware('can:redirects.manage')->group(function () {
        Route::get('redirects', [RedirectsController::class, 'index'])->name('redirects.index');
        Route::post('redirects', [RedirectsController::class, 'store'])->name('redirects.store');
        Route::post('redirects/import', [RedirectsController::class, 'import'])->name('redirects.import');
        Route::get('redirects/export', [RedirectsController::class, 'export'])->name('redirects.export');
        Route::post('redirects/bulk', [RedirectsController::class, 'bulk'])->name('redirects.bulk');
        Route::put('redirects/{id}', [RedirectsController::class, 'update'])->name('redirects.update');
        Route::delete('redirects/{id}', [RedirectsController::class, 'destroy'])->name('redirects.destroy');
    });

    // Retrying a failed job re-sends a letter; cancelling stops an import.
    Route::middleware('can:tasks.manage')->group(function () {
        Route::get('tasks', [TasksController::class, 'index'])->name('tasks');
        Route::get('tasks/rows', [TasksController::class, 'rows'])->name('tasks.rows');
        Route::post('tasks/clear', [TasksController::class, 'clear'])->name('tasks.clear');
        Route::post('tasks/{task}/cancel', [TasksController::class, 'cancel'])->name('tasks.cancel');
        Route::post('tasks/failed/retry-all', [TasksController::class, 'retryAll'])->name('tasks.retry_all');
        Route::post('tasks/failed/forget-all', [TasksController::class, 'forgetAll'])->name('tasks.forget_all');
        Route::post('tasks/failed/{uuid}/retry', [TasksController::class, 'retry'])->name('tasks.retry');
        Route::post('tasks/failed/{uuid}/forget', [TasksController::class, 'forget'])->name('tasks.forget');
    });

    // Managing staff is managing who can do everything else, so it sits behind
    // its own permission rather than inside admin.access.
    // Themes (PLAN §37): the list, putting one on a storefront, and the
    // configurator — its preview keeps a draft in this session, which only a
    // frame of the storefront reads (see App\Core\Themes\ThemeSettings).
    Route::middleware('can:themes.manage')->prefix('themes')->name('themes.')->group(function () {
        Route::get('/', [ThemesController::class, 'index'])->name('index');
        Route::post('apply', [ThemesController::class, 'apply'])->name('apply');
        Route::get('customize/{storefront}', [ThemesController::class, 'customize'])->name('customize');
        Route::post('customize/{storefront}/open', [ThemesController::class, 'open'])->name('open');
        Route::post('customize/{storefront}/preview', [ThemesController::class, 'preview'])->name('preview');
        Route::post('customize/{storefront}/image', [ThemesController::class, 'image'])->name('image');
        Route::post('customize/{storefront}', [ThemesController::class, 'save'])->name('save');
        Route::post('customize/{storefront}/reset', [ThemesController::class, 'reset'])->name('reset');
        Route::post('customize/{storefront}/preset', [ThemesController::class, 'preset'])->name('preset');
        Route::get('customize/{storefront}/export', [ThemesController::class, 'export'])->name('export');
        Route::post('customize/{storefront}/import', [ThemesController::class, 'import'])->name('import');
        Route::post('customize/{storefront}/styles', [ThemesController::class, 'storeStyle'])->name('styles.store');
        Route::post('customize/{storefront}/styles/{style}', [ThemesController::class, 'applyStyle'])->name('styles.apply');
        Route::delete('customize/{storefront}/styles/{style}', [ThemesController::class, 'destroyStyle'])->name('styles.destroy');
    });

    Route::middleware('can:staff.manage')->group(function () {
        Route::resource('administrators', AdministratorsController::class)->except(['show']);
        Route::resource('permission-groups', PermissionGroupsController::class)
            ->parameters(['permission-groups' => 'permissionGroup'])
            ->except(['show']);
    });

    Route::middleware('can:customers.manage')->group(function () {
        Route::get('customers', [CustomersController::class, 'index'])->name('customers.index');
        Route::get('customers/create', [CustomersController::class, 'create'])->name('customers.create');
        Route::post('customers', [CustomersController::class, 'store'])->name('customers.store');
        Route::get('customers/{customer}', [CustomersController::class, 'edit'])->name('customers.edit');
        Route::put('customers/{customer}', [CustomersController::class, 'update'])->name('customers.update');
        Route::delete('customers/{customer}', [CustomersController::class, 'destroy'])->name('customers.destroy');
        Route::patch('customers/{customer}/group', [CustomersController::class, 'setGroup'])->name('customers.group');
        Route::post('customers/bulk', [CustomersController::class, 'bulk'])->name('customers.bulk');
        Route::post('customer-groups/{customerGroup}/default', [CustomerGroupsController::class, 'makeDefault'])->name('customer-groups.default');
        Route::resource('customer-groups', CustomerGroupsController::class)->except(['show']);
    });
});
