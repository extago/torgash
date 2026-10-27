<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\AddressesController;
use App\Http\Controllers\Account\CodeController;
use App\Http\Controllers\Account\ContactController;
use App\Http\Controllers\Account\NewPasswordController;
use App\Http\Controllers\Account\RegisterController;
use App\Http\Controllers\Account\SessionController;
use Illuminate\Support\Facades\Route;

// Loaded from routes/web.php with prefix "account", name prefix "account.".

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->name('register.store');
    Route::get('login', [SessionController::class, 'create'])->name('login');
    Route::post('login', [SessionController::class, 'store'])->name('login.store');

    // A code instead of a password: to sign in, or to get back in (PLAN §44).
    Route::get('code', [CodeController::class, 'create'])->name('code');
    Route::post('code', [CodeController::class, 'store'])->name('code.store');
    Route::get('code/enter', [CodeController::class, 'enter'])->name('code.enter');
    Route::post('code/enter', [CodeController::class, 'verify'])->name('code.verify');
    Route::post('code/resend', [CodeController::class, 'resend'])->name('code.resend');
    Route::get('code/{code}/{secret}', [CodeController::class, 'link'])->whereNumber('code')->name('code.link');

    // "An account was made for you — choose a password" (PLAN §41).
    Route::get('password/set/{token}', [\App\Http\Controllers\Account\SetPasswordController::class, 'edit'])->name('password.set');
    Route::post('password/set', [\App\Http\Controllers\Account\SetPasswordController::class, 'update'])
        ->middleware('throttle:10,10')->name('password.set.update');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [SessionController::class, 'destroy'])->name('logout');

    Route::get('/', [AccountController::class, 'index'])->name('dashboard');
    Route::put('profile', [AccountController::class, 'updateProfile'])->name('profile.update');
    Route::put('password', [AccountController::class, 'updatePassword'])->name('password.update');
    // A new email or phone, proven by a code sent to it (PLAN §44).
    Route::delete('contact/phone', [ContactController::class, 'destroy'])->name('contact.phone.destroy');
    Route::delete('contact/email', [ContactController::class, 'destroyEmail'])->name('contact.email.destroy');
    Route::get('contact/{kind}', [ContactController::class, 'edit'])->whereIn('kind', ContactController::KINDS)->name('contact');
    Route::post('contact/{kind}', [ContactController::class, 'store'])->whereIn('kind', ContactController::KINDS)->name('contact.store');
    Route::get('contact/{kind}/enter', [ContactController::class, 'enter'])->whereIn('kind', ContactController::KINDS)->name('contact.enter');
    Route::post('contact/{kind}/enter', [ContactController::class, 'verify'])->whereIn('kind', ContactController::KINDS)->name('contact.verify');
    Route::post('contact/{kind}/resend', [ContactController::class, 'resend'])->whereIn('kind', ContactController::KINDS)->name('contact.resend');

    Route::get('password/new', [NewPasswordController::class, 'edit'])->name('password.new');
    Route::put('password/new', [NewPasswordController::class, 'update'])->name('password.new.update');
    Route::get('orders', [AccountController::class, 'orders'])->name('orders');

    Route::resource('addresses', AddressesController::class)->except(['show']);
    Route::patch('addresses/{address}/default', [AddressesController::class, 'makeDefault'])->name('addresses.default');
});
