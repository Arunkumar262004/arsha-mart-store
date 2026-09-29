<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Report download from a QR code: no login, the signed and expiring URL is the permission.
Route::get('/reports/{report}/shared', [ReportController::class, 'shared'])
    ->whereIn('report', ['orders', 'customers', 'stock', 'employees'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('reports.shared');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Session & profile (every signed-in user)
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/me/password', [ProfileController::class, 'updatePassword']);
    Route::get('/notifications', [NotificationController::class, 'index']);

    Route::get('/dashboard', DashboardController::class)->middleware('can:dashboard.view');

    // Billing
    Route::middleware('can:billing.create')->group(function () {
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/customers/lookup', [CustomerController::class, 'lookup']);
    });

    // Order history
    Route::middleware('can:orders.view')->group(function () {
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::get('/customers/{email}/orders', [CustomerController::class, 'orders']);
    });

    // Inventory. The product list is also needed to build a bill.
    Route::get('/products', [ProductController::class, 'index'])->middleware('can:products.view-or-bill');
    Route::middleware('can:products.view')->group(function () {
        Route::get('/products/low-stock', [ProductController::class, 'lowStock']);
        Route::get('/products/{product}/movements', [StockController::class, 'movements']);
    });
    Route::middleware('can:products.manage')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
    });
    Route::post('/products/{product}/stock', [StockController::class, 'adjust'])->middleware('can:stock.adjust');

    // Reports
    Route::middleware('can:reports.view')->prefix('reports')->group(function () {
        Route::get('/employee-options', [ReportController::class, 'employeeOptions']);
        Route::get('/orders', [ReportController::class, 'orders']);
        Route::get('/customers', [ReportController::class, 'customers']);
        Route::get('/stock', [ReportController::class, 'stock']);
        Route::get('/employees', [ReportController::class, 'employees']);
        Route::get('/{report}/export', [ReportController::class, 'export'])->whereIn('report', ['orders', 'customers', 'stock', 'employees']);
        Route::get('/{report}/share-link', [ReportController::class, 'shareLink'])->whereIn('report', ['orders', 'customers', 'stock', 'employees']);
    });

    // Settings: admin only
    Route::middleware('can:settings.manage')->group(function () {
        Route::get('/permissions', [RoleController::class, 'permissions']);
        Route::apiResource('roles', RoleController::class)->except('show');
        Route::apiResource('users', UserController::class)->except('show');
        Route::put('/users/{user}/password', [UserController::class, 'resetPassword']);
    });
});
