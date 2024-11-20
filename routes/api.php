<?php

use App\Http\Controllers\Api\v1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::controller(AuthController::class)->group(function () {

        Route::post('/register', [AuthController::class, 'register']);
        Route::middleware(['throttle:6,1'])->group(function () {
            Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
        });

        Route::middleware(['throttle:5,1'])->group(function () {
            Route::post('/login', [AuthController::class, 'login']);
        });

        Route::middleware(['throttle:6,1'])->group(function () {
            Route::post('/password/reset-request', [AuthController::class, 'requestPasswordReset']);
            Route::post('/password/verify-code', [AuthController::class, 'verifyResetCode']);
        });
        Route::post('/password/reset', [AuthController::class, 'resetPassword']);

        Route::middleware(['auth:sanctum'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);

            Route::middleware(['throttle:3,1'])->group(function () {
                Route::post('/email/change/request', [AuthController::class, 'requestEmailChange']);
                Route::post('/email/change/verify', [AuthController::class, 'verifyEmailChange']);
            });

            Route::post('/password/change', [AuthController::class, 'changePassword']);
        });
    });
});
