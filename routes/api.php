<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AdminOperationsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\VistaAuthController;
use App\Http\Middleware\AdminShopContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// These are first-party session APIs: every request receives Laravel web/CSRF middleware.
// Sanctum resolves the authenticated session; no browser bearer tokens are issued.
Route::middleware('web')->prefix('v1')->group(function () {
    Route::get('auth/vista/config', [VistaAuthController::class, 'config']);
    Route::post('auth/vista/start', [VistaAuthController::class, 'start'])->middleware('throttle:login');
    Route::post('auth/vista/finish', [VistaAuthController::class, 'finish'])->middleware('throttle:login');
    Route::get('health', fn () => response()->json(['status' => 'ok']));
    Route::get('auth/verify-email/{id}/{hash}', [AuthController::class, 'verify'])->middleware(['signed:relative', 'throttle:verification'])->name('verification.verify');
    Route::post('auth/accept-invitation', [\App\Http\Controllers\Api\V1\EmployeeController::class, 'accept'])->middleware('throttle:recovery');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:recovery');
    foreach (['auth' => 'shop', 'admin/auth' => 'admin'] as $prefix => $actor) {
        Route::prefix($prefix)->group(function () use ($actor) {
            // Return the token for this audience's session; a shared XSRF cookie
            // can be overwritten by another tab on the same hostname.
            Route::get('csrf', fn (Request $r) => response()->json(['token' => $r->session()->token()])->header('Cache-Control', 'no-store'));
            Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
            Route::post('forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:recovery');
            Route::post('reset-password', [AuthController::class, 'reset'])->middleware('throttle:recovery');
            Route::middleware(['auth:sanctum', 'session.valid', 'actor:'.$actor])->group(function () {
                Route::get('me', [AuthController::class, 'me']);
                Route::post('logout', [AuthController::class, 'logout']);
                Route::post('verification-notification', [AuthController::class, 'resend'])->middleware('throttle:verification');
                Route::post('password', [AuthController::class, 'changePassword'])->middleware('throttle:recovery');
                Route::post('email', [AuthController::class, 'changeEmail'])->middleware('throttle:recovery');
            });
        });
    }
    Route::middleware(['auth:sanctum', 'session.valid', 'actor:shop', 'account.verified', 'tenant', \App\Http\Middleware\WorkspaceAccess::class])->group(base_path('routes/workspace.php'));

    Route::prefix('admin')->middleware(['auth:sanctum', 'session.valid', 'actor:admin', 'account.verified'])->group(function () {
        Route::prefix('shops/{managedShop}/workspace')->whereNumber('managedShop')->middleware([AdminShopContext::class, \App\Http\Middleware\WorkspaceAccess::class])->group(base_path('routes/workspace.php'));
        Route::get('audit', [AdminOperationsController::class, 'audit']);
        Route::get('operations', [AdminOperationsController::class, 'health']);
        Route::get('overview', [AdminController::class, 'overview']);
        Route::get('shops', [AdminController::class, 'shops']);
        Route::patch('shops/{shop}/status', [AdminController::class, 'status']);
    });
});

Route::post('v1/communications/callback/{uuid}', [\App\Http\Controllers\Api\V1\CommunicationWebhookController::class, 'status'])->whereUuid('uuid')->middleware('throttle:120,1');
Route::post('v1/communications/inbound', [\App\Http\Controllers\Api\V1\CommunicationWebhookController::class, 'inbound'])->middleware('throttle:120,1');
Route::match(['get', 'post'], 'v1/communications/preferences/{uuid}/{hash}', [\App\Http\Controllers\Api\V1\CommunicationWebhookController::class, 'preferences'])->whereUuid('uuid')->middleware('throttle:30,1');
