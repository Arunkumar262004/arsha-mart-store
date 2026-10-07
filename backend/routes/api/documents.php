<?php

// Sales documents: quotations, delivery challans, stock transfers, tax invoices.
// Included inside the authenticated, store-resolved group in routes/api.php.

use App\Http\Controllers\Api\DeliveryChallanController;
use App\Http\Controllers\Api\DocumentProductController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\TaxInvoiceController;
use Illuminate\Support\Facades\Route;

// Products for the document forms (any sales-document permission).
Route::get('/documents/products', DocumentProductController::class);

// Tax invoices: every bill, viewed as a GST invoice.
// One invoice is also open to cashiers (billing.create), so they can print
// the A4 invoice straight after a B2B bill; the controller checks that.
Route::get('/invoices', [TaxInvoiceController::class, 'index'])->middleware('can:orders.view');
Route::get('/invoices/{order}', [TaxInvoiceController::class, 'show']);
Route::get('/invoices/{order}/pdf', [TaxInvoiceController::class, 'pdf']);

// Quotations. Converting one into a bill also needs billing rights.
Route::middleware('can:quotations.manage')->group(function () {
    Route::post('/quotations/{quotation}/convert', [QuotationController::class, 'convert'])->middleware('can:billing.create');
    Route::put('/quotations/{quotation}/status', [QuotationController::class, 'setStatus']);
    Route::apiResource('quotations', QuotationController::class);
});

// Delivery challans. Invoicing them also needs billing rights.
Route::middleware('can:challans.manage')->group(function () {
    Route::post('/challans/invoice', [DeliveryChallanController::class, 'invoice'])->middleware('can:billing.create')->name('challans.invoice');
    Route::post('/challans/{challan}/return', [DeliveryChallanController::class, 'markReturned']);
    Route::post('/challans/{challan}/cancel', [DeliveryChallanController::class, 'cancel']);
    Route::get('/challans', [DeliveryChallanController::class, 'index']);
    Route::post('/challans', [DeliveryChallanController::class, 'store']);
    Route::get('/challans/{challan}', [DeliveryChallanController::class, 'show']);
});

// Stock transfers between stores.
Route::middleware('can:transfers.manage')->group(function () {
    Route::get('/transfers', [StockTransferController::class, 'index']);
    Route::get('/transfers/destinations', [StockTransferController::class, 'destinations']);
    Route::post('/transfers', [StockTransferController::class, 'store']);
    Route::get('/transfers/{transfer}', [StockTransferController::class, 'show']);
    Route::post('/transfers/{transfer}/receive', [StockTransferController::class, 'receive']);
    Route::post('/transfers/{transfer}/cancel', [StockTransferController::class, 'cancel']);
});
