<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 (see bootstrap/app.php). Every authorisation rule is enforced here and in
// policies/middleware: the React role checks are cosmetic only.

Route::get('health', [V1\InternalController::class, 'health']);
Route::post('internal/schedule', [V1\InternalController::class, 'schedule'])->middleware('throttle:10,1');

// ---- Public auth ----------------------------------------------------------------------------
Route::post('register', [V1\AuthController::class, 'register'])->middleware('throttle:auth-sensitive');
Route::post('login', [V1\AuthController::class, 'login'])->middleware('throttle:login');
Route::post('forgot-password', [V1\AuthController::class, 'forgotPassword'])->middleware('throttle:auth-sensitive');
Route::post('reset-password', [V1\AuthController::class, 'resetPassword'])->middleware('throttle:auth-sensitive');

// ---- Authenticated farmer routes --------------------------------------------------------------
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::post('logout', [V1\AuthController::class, 'logout']);
    Route::get('user', [V1\AuthController::class, 'user']);
    Route::put('user/profile', [V1\ProfileController::class, 'update']);
    Route::put('user/password', [V1\ProfileController::class, 'password'])->middleware('throttle:auth-sensitive');
    Route::delete('user', [V1\ProfileController::class, 'destroy'])->middleware('throttle:auth-sensitive');

    Route::get('dashboard', V1\DashboardController::class);
    Route::get('crops', [V1\CropController::class, 'index']);

    Route::get('scans', [V1\ScanController::class, 'index']);
    Route::post('scans', [V1\ScanController::class, 'store'])->middleware(['throttle:uploads', 'throttle:ai']);
    Route::get('scans/{id}', [V1\ScanController::class, 'show']);
    Route::delete('scans/{id}', [V1\ScanController::class, 'destroy']);

    Route::get('storage/usage', [V1\StorageController::class, 'usage']);
    Route::get('storage/quota', [V1\StorageController::class, 'quota']);

    Route::get('advisories', [V1\AdvisoryController::class, 'index']);
    Route::get('advisories/{id}', [V1\AdvisoryController::class, 'show'])->name('advisories.show');
});

// ---- Administrator routes ----------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'active', 'admin', 'throttle:api'])->prefix('admin')->group(function () {
    Route::get('dashboard', Admin\DashboardController::class);

    Route::get('users', [Admin\UserController::class, 'index']);
    Route::patch('users/{user}', [Admin\UserController::class, 'update']);
    Route::put('users/{user}/quota', [Admin\UserController::class, 'quota']);
    Route::delete('users/{user}', [Admin\UserController::class, 'destroy']);

    Route::get('crops', [Admin\ReferenceController::class, 'crops']);
    Route::post('crops', [Admin\ReferenceController::class, 'storeCrop']);
    Route::put('crops/{crop}', [Admin\ReferenceController::class, 'updateCrop']);
    Route::delete('crops/{crop}', [Admin\ReferenceController::class, 'destroyCrop']);

    Route::get('diseases', [Admin\ReferenceController::class, 'diseases']);
    Route::post('diseases', [Admin\ReferenceController::class, 'storeDisease']);
    Route::put('diseases/{disease}', [Admin\ReferenceController::class, 'updateDisease']);
    Route::delete('diseases/{disease}', [Admin\ReferenceController::class, 'destroyDisease']);

    Route::get('advisories', [Admin\AdvisoryController::class, 'index']);
    Route::post('advisories', [Admin\AdvisoryController::class, 'store']);
    Route::put('advisories/{advisory}', [Admin\AdvisoryController::class, 'update']);
    Route::delete('advisories/{advisory}', [Admin\AdvisoryController::class, 'destroy']);

    Route::get('storage', [Admin\InfrastructureController::class, 'storage']);
    Route::post('storage/reconcile', [Admin\InfrastructureController::class, 'reconcile']);
    Route::get('database-health', [Admin\InfrastructureController::class, 'databaseHealth']);
    Route::get('ai-health', [Admin\InfrastructureController::class, 'aiHealth']);
    Route::get('system-health', [Admin\InfrastructureController::class, 'systemHealth']);
    Route::post('system-health/run', [Admin\InfrastructureController::class, 'runChecks']);
    Route::get('settings', [Admin\InfrastructureController::class, 'settings']);
    Route::put('settings', [Admin\InfrastructureController::class, 'saveSettings']);
    Route::get('pending-operations', [Admin\InfrastructureController::class, 'pending']);
    Route::post('pending-operations/{operation}/retry', [Admin\InfrastructureController::class, 'retryPending']);
    Route::get('logs', [Admin\InfrastructureController::class, 'logs']);
});
