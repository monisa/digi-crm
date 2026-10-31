<?php

use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

// Public auth endpoints (rate-limited per IP on top of the per-account login lockout).
Route::prefix('auth')->middleware('throttle:20,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/config', [GoogleAuthController::class, 'config']);
    Route::post('/google', [GoogleAuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
});

// Signed-in, any status (so pending users can see who they are, onboard and log out).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/onboarding', [OnboardingController::class, 'store']);
});

// Signed-in and active.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/dashboard/overview', [DashboardController::class, 'overview'])->middleware('permission:overview.view');

    Route::prefix('admin')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:settings.view');
        Route::put('/roles/{role}/permissions', [PermissionController::class, 'updateRole'])->middleware('permission:settings.edit');

        Route::get('/users', [UserController::class, 'index'])->middleware('permission:settings.view');
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('permission:settings.edit');
        Route::get('/users/{user}/permissions', [UserController::class, 'permissions'])->middleware('permission:settings.view');
        Route::put('/users/{user}/permissions', [UserController::class, 'updatePermissions'])->middleware('permission:settings.edit');
    });
});
