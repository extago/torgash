<?php

use Illuminate\Support\Facades\Route;
use Torgash\Newsletter\Http\Admin\CampaignsController;
use Torgash\Newsletter\Http\Admin\SubscribersController;

// Loaded by NewsletterServiceProvider with prefix "admin/newsletter", name prefix "admin.newsletter.".

Route::get('/', [SubscribersController::class, 'index'])->name('index');
Route::get('export', [SubscribersController::class, 'export'])->name('export');
Route::post('import', [SubscribersController::class, 'import'])->name('import');

// Campaigns (PLAN §57.4). "count" goes before the {campaign} routes.
Route::prefix('campaigns')->name('campaigns.')->group(function () {
    Route::get('/', [CampaignsController::class, 'index'])->name('index');
    Route::get('create', [CampaignsController::class, 'create'])->name('create');
    Route::post('/', [CampaignsController::class, 'store'])->name('store');
    Route::get('count', [CampaignsController::class, 'count'])->name('count');
    Route::get('{campaign}/edit', [CampaignsController::class, 'edit'])->name('edit');
    Route::put('{campaign}', [CampaignsController::class, 'update'])->name('update');
    Route::delete('{campaign}', [CampaignsController::class, 'destroy'])->name('destroy');
    Route::match(['get', 'post', 'put'], '{campaign}/preview', [CampaignsController::class, 'preview'])->name('preview');
    Route::match(['post', 'put'], '{campaign}/test', [CampaignsController::class, 'test'])->name('test');
    Route::match(['post', 'put'], '{campaign}/send', [CampaignsController::class, 'send'])->name('send');
    Route::post('{campaign}/cancel', [CampaignsController::class, 'cancel'])->name('cancel');
    Route::post('{campaign}/duplicate', [CampaignsController::class, 'duplicate'])->name('duplicate');
});

Route::delete('{subscriber}', [SubscribersController::class, 'destroy'])->name('destroy');
