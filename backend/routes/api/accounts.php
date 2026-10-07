<?php

// Accounts: chart of accounts, journal/contra vouchers, day book, ledgers, GST and financial reports.
// Included inside the authenticated, store-resolved group in routes/api.php.

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\DayClosingController;
use App\Http\Controllers\Api\FinancialReportController;
use App\Http\Controllers\Api\GstReportController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\SalesAnalysisController;
use App\Http\Controllers\Api\VoucherController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:accounts.view')->group(function () {
    // Fixed paths first so they are not taken for an {account} id.
    Route::get('/accounts', [AccountController::class, 'index']);
    Route::get('/accounts/options', [AccountController::class, 'options']);
    Route::get('/accounts/outstanding', [LedgerController::class, 'outstanding']);
    Route::get('/accounts/day-closing', [DayClosingController::class, 'show']);
    Route::get('/accounts/trial-balance', [FinancialReportController::class, 'trialBalance']);
    Route::get('/accounts/profit-loss', [FinancialReportController::class, 'profitLoss']);
    Route::get('/accounts/balance-sheet', [FinancialReportController::class, 'balanceSheet']);
    Route::get('/accounts/gst/gstr1', [GstReportController::class, 'gstr1']);
    Route::get('/accounts/gst/gstr3b', [GstReportController::class, 'gstr3b']);
    Route::get('/accounts/{account}/ledger', [LedgerController::class, 'show'])->whereNumber('account');

    // Day book
    Route::get('/vouchers', [VoucherController::class, 'index']);
    Route::get('/vouchers/{voucher}', [VoucherController::class, 'show'])->whereNumber('voucher');
});

Route::middleware('can:accounts.manage')->group(function () {
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::put('/accounts/{account}', [AccountController::class, 'update'])->whereNumber('account');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->whereNumber('account');
    Route::post('/vouchers', [VoucherController::class, 'store']);
    Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->whereNumber('voucher');
});

// Closing the cash drawer: cashiers (billing.create) or accountants (accounts.manage); checked in the controller.
Route::post('/accounts/day-closing', [DayClosingController::class, 'store']);

Route::get('/reports/sales-analysis', SalesAnalysisController::class)->middleware('can:reports.view');
