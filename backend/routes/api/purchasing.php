<?php

// Purchasing and money: suppliers, purchases, sales/purchase returns, receipts, payments, expenses.
// Included inside the authenticated, store-resolved group in routes/api.php.

use App\Http\Controllers\Api\MoneyTransactionController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\PurchaseReturnController;
use App\Http\Controllers\Api\SalesReturnController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// Supplier list: anyone with a purchasing / payments permission (checked in the controller).
Route::get('/suppliers', [SupplierController::class, 'index']);
Route::middleware('can:suppliers.manage')->group(function () {
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
});

// Product picker for purchase / return forms (purchases.manage or returns.manage, checked in the controller).
Route::get('/purchasing/products', [PurchaseController::class, 'products']);

Route::middleware('can:purchases.manage')->group(function () {
    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::post('/purchases', [PurchaseController::class, 'store']);
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
    Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel']);
});

Route::middleware('can:returns.manage')->group(function () {
    Route::get('/purchase-returns/purchases', [PurchaseReturnController::class, 'purchases']);
    Route::get('/purchase-returns', [PurchaseReturnController::class, 'index']);
    Route::post('/purchase-returns', [PurchaseReturnController::class, 'store']);
    Route::get('/purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'show'])->whereNumber('purchaseReturn');

    Route::get('/sales-returns/lookup', [SalesReturnController::class, 'lookup']);
    Route::get('/sales-returns', [SalesReturnController::class, 'index']);
    Route::post('/sales-returns', [SalesReturnController::class, 'store']);
    Route::get('/sales-returns/{salesReturn}', [SalesReturnController::class, 'show'])->whereNumber('salesReturn');
});

Route::middleware('can:payments.manage')->group(function () {
    Route::get('/receipts/customers', [MoneyTransactionController::class, 'customersWithOutstanding']);
    foreach (['receipts', 'payments'] as $kind) {
        Route::get("/{$kind}", [MoneyTransactionController::class, 'index']);
        Route::post("/{$kind}", [MoneyTransactionController::class, 'store']);
        Route::get("/{$kind}/{transaction}", [MoneyTransactionController::class, 'show'])->whereNumber('transaction');
        Route::post("/{$kind}/{transaction}/cancel", [MoneyTransactionController::class, 'cancel'])->whereNumber('transaction');
    }
});

Route::middleware('can:expenses.manage')->group(function () {
    Route::get('/expenses/accounts', [MoneyTransactionController::class, 'expenseAccounts']);
    Route::get('/expenses', [MoneyTransactionController::class, 'index']);
    Route::post('/expenses', [MoneyTransactionController::class, 'store']);
    Route::get('/expenses/{transaction}', [MoneyTransactionController::class, 'show'])->whereNumber('transaction');
    Route::post('/expenses/{transaction}/cancel', [MoneyTransactionController::class, 'cancel'])->whereNumber('transaction');
});
