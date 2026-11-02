<?php

use App\Http\Controllers\Web;
use App\Http\Controllers\Web\Admin;
use Illuminate\Support\Facades\Route;

// Browser UI (server-rendered Blade). Authorisation is enforced here, in policies and in middleware;
// hiding a link in a view is cosmetic only. The JSON API lives in routes/api.php under /api/v1.

Route::view('/', 'welcome')->name('home');

// ---- Guests ---------------------------------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [Web\AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [Web\AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [Web\AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [Web\AuthController::class, 'register'])->middleware('throttle:auth-sensitive');
});
// Reset pages work whether or not someone happens to be signed in.
Route::get('/forgot-password', [Web\AuthController::class, 'showForgot'])->name('password.request');
Route::post('/forgot-password', [Web\AuthController::class, 'sendReset'])->middleware('throttle:auth-sensitive')->name('password.email');
Route::get('/reset-password/{token}', [Web\AuthController::class, 'showReset'])->name('password.reset');
Route::post('/reset-password', [Web\AuthController::class, 'reset'])->middleware('throttle:auth-sensitive')->name('password.update');

// ---- Signed-in farmers ---------------------------------------------------------------------------
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [Web\AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', Web\DashboardController::class)->name('dashboard');

    Route::get('/scan', [Web\ScanController::class, 'create'])->name('scans.create');
    Route::post('/scan', [Web\ScanController::class, 'store'])->middleware(['throttle:uploads', 'throttle:ai'])->name('scans.store');
    Route::get('/history', [Web\ScanController::class, 'index'])->name('scans.index');
    Route::get('/history/{id}', [Web\ScanController::class, 'show'])->name('scans.show');
    Route::delete('/history/{id}', [Web\ScanController::class, 'destroy'])->name('scans.destroy');

    Route::get('/advisories', [Web\AdvisoryController::class, 'index'])->name('advisory.index');
    Route::get('/advisories/{id}', [Web\AdvisoryController::class, 'show'])->whereNumber('id')->name('advisory.show');

    Route::get('/storage', Web\StorageController::class)->name('storage');

    Route::get('/profile', [Web\ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [Web\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [Web\ProfileController::class, 'password'])->middleware('throttle:auth-sensitive')->name('profile.password');
    Route::delete('/profile', [Web\ProfileController::class, 'destroy'])->middleware('throttle:auth-sensitive')->name('profile.destroy');
});

// ---- Administrators ------------------------------------------------------------------------------
Route::middleware(['auth', 'active', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');

    Route::get('/users', [Admin\UserController::class, 'index'])->name('users');
    Route::patch('/users/{user}', [Admin\UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [Admin\UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/reference', [Admin\ReferenceController::class, 'index'])->name('reference');
    Route::post('/crops', [Admin\ReferenceController::class, 'storeCrop'])->name('crops.store');
    Route::put('/crops/{crop}', [Admin\ReferenceController::class, 'updateCrop'])->name('crops.update');
    Route::delete('/crops/{crop}', [Admin\ReferenceController::class, 'destroyCrop'])->name('crops.destroy');
    Route::post('/diseases', [Admin\ReferenceController::class, 'storeDisease'])->name('diseases.store');
    Route::put('/diseases/{disease}', [Admin\ReferenceController::class, 'updateDisease'])->name('diseases.update');
    Route::delete('/diseases/{disease}', [Admin\ReferenceController::class, 'destroyDisease'])->name('diseases.destroy');

    Route::get('/advisories', [Admin\AdvisoryController::class, 'index'])->name('advisories');
    Route::post('/advisories', [Admin\AdvisoryController::class, 'store'])->name('advisories.store');
    Route::put('/advisories/{advisory}', [Admin\AdvisoryController::class, 'update'])->name('advisories.update');
    Route::delete('/advisories/{advisory}', [Admin\AdvisoryController::class, 'destroy'])->name('advisories.destroy');

    Route::get('/infrastructure', [Admin\InfrastructureController::class, 'index'])->name('infrastructure');
    Route::post('/infrastructure/checks', [Admin\InfrastructureController::class, 'runChecks'])->name('checks.run');
    Route::put('/infrastructure/settings', [Admin\InfrastructureController::class, 'saveSettings'])->name('settings.update');
    Route::post('/infrastructure/reconcile', [Admin\InfrastructureController::class, 'reconcile'])->name('storage.reconcile');
    Route::post('/infrastructure/pending/{operation}/retry', [Admin\InfrastructureController::class, 'retryPending'])->name('pending.retry');
});
