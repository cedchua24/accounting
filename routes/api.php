<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ChartAccountController;
use App\Http\Controllers\Api\CounterpartyController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\EndOfDaySettlementController;
use App\Http\Controllers\Api\FinancialAccountController;
use App\Http\Controllers\Api\FinancialAccountAdjustmentController;
use App\Http\Controllers\Api\FinancialAccountTransferController;
use App\Http\Controllers\Api\FinancialInstitutionController;
use App\Http\Controllers\Api\InterbranchOrderController;
use App\Http\Controllers\Api\IntegratedCustomerOrderController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\PaymentObligationController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\SalesInvoiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::apiResources([
        'chart-accounts' => ChartAccountController::class,
        'branches' => BranchController::class,
        'financial-institutions' => FinancialInstitutionController::class,
        'financial-accounts' => FinancialAccountController::class,
        'payment-methods' => PaymentMethodController::class,
        'payment-obligations' => PaymentObligationController::class,
        'counterparties' => CounterpartyController::class,
        'purchase-orders' => PurchaseOrderController::class,
        'expenses' => ExpenseController::class,
        'sales-invoices' => SalesInvoiceController::class,
    ]);
    Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::post('/payments/{payment}/post', [PaymentController::class, 'post']);
    Route::post('/payments/{payment}/void', [PaymentController::class, 'void']);
    Route::apiResource('end-of-day-settlements', EndOfDaySettlementController::class)
        ->parameters(['end-of-day-settlements' => 'endOfDaySettlement'])
        ->only(['index', 'store', 'show', 'destroy']);
    Route::post('/end-of-day-settlements/{endOfDaySettlement}/post', [EndOfDaySettlementController::class, 'post']);
    Route::post('/end-of-day-settlements/{endOfDaySettlement}/void', [EndOfDaySettlementController::class, 'void']);
    Route::get('/financial-accounts/{financialAccount}/balance', [FinancialAccountController::class, 'balance']);
    Route::get('/financial-accounts/{financialAccount}/movements', [FinancialAccountController::class, 'movements']);
    Route::apiResource('account-transfers', FinancialAccountTransferController::class)
        ->parameters(['account-transfers' => 'financialAccountTransfer'])
        ->only(['index', 'store', 'show', 'destroy']);
    Route::post('/account-transfers/{financialAccountTransfer}/post', [FinancialAccountTransferController::class, 'post']);
    Route::post('/account-transfers/{financialAccountTransfer}/void', [FinancialAccountTransferController::class, 'void']);
    Route::apiResource('account-adjustments', FinancialAccountAdjustmentController::class)
        ->parameters(['account-adjustments' => 'financialAccountAdjustment'])
        ->only(['index', 'store', 'show', 'destroy']);
    Route::post('/account-adjustments/{financialAccountAdjustment}/post', [FinancialAccountAdjustmentController::class, 'post']);
    Route::post('/account-adjustments/{financialAccountAdjustment}/void', [FinancialAccountAdjustmentController::class, 'void']);
    Route::get('/integrations/customer-order', [IntegratedCustomerOrderController::class, 'show']);
    Route::post('/integrations/customer-order/payments', [IntegratedCustomerOrderController::class, 'store']);
    Route::apiResource('interbranch-orders', InterbranchOrderController::class);
    Route::post('/interbranch-orders/{interbranchOrder}/post', [InterbranchOrderController::class, 'post']);
    Route::post('/interbranch-orders/{interbranchOrder}/void', [InterbranchOrderController::class, 'void']);
    Route::get('/journal-entries', [JournalEntryController::class, 'index']);
    Route::get('/journal-entries/{journalEntry}', [JournalEntryController::class, 'show']);
});
