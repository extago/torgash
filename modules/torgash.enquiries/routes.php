<?php

use Illuminate\Support\Facades\Route;
use Torgash\Enquiries\Http\AccountEnquiriesController;
use Torgash\Enquiries\Http\EnquiryController;

// Registered through StorefrontRoutes::group(), so it exists in every locale
// shape — a Russian visitor posts to /ru/enquiry and gets the Russian answer.
//
// One route for every kind of enquiry: the kind is a field of the form, checked
// against the registry. A route per kind would mean a module registering one
// could not have an endpoint at all.
Route::post('/enquiry', [EnquiryController::class, 'store'])->name('enquiries.store');

// The customer's own threads, under the account area's address and name so the
// menu item core renders points at them like any other. Registered HERE rather
// than in routes/account.php, because core must not learn this module's name to
// keep working — uninstall it and both the routes and the menu item go.
Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('enquiries', [AccountEnquiriesController::class, 'index'])->name('enquiries.index');
    Route::get('enquiries/{enquiry}', [AccountEnquiriesController::class, 'show'])->name('enquiries.show');
    Route::post('enquiries/{enquiry}/messages', [AccountEnquiriesController::class, 'reply'])->name('enquiries.reply');
});
