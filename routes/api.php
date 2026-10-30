<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ChartAccountController;
use App\Http\Controllers\Api\ChartAccountGroupController;
use App\Http\Controllers\Api\CounterpartyController;
use App\Http\Controllers\Api\CreditCardController;
use App\Http\Controllers\Api\CustomerPaymentReconciliationController;
use App\Http\Controllers\Api\CustomerRefundController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EndOfDaySettlementController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ExpenseItemController;
use App\Http\Controllers\Api\ExpenseLedgerController;
use App\Http\Controllers\Api\ExpenseSetupController;
use App\Http\Controllers\Api\ExpenseTypeController;
use App\Http\Controllers\Api\FinancialAccountAdjustmentController;
use App\Http\Controllers\Api\FinancialAccountApplicationTypeController;
use App\Http\Controllers\Api\FinancialAccountController;
use App\Http\Controllers\Api\FinancialAccountTransferController;
use App\Http\Controllers\Api\FinancialInstitutionController;
use App\Http\Controllers\Api\IntegratedCustomerOrderController;
use App\Http\Controllers\Api\IntegratedExpenseController;
use App\Http\Controllers\Api\IntegratedPurchaseOrderController;
use App\Http\Controllers\Api\InterbranchOrderController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\LoanInstallmentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\PaymentObligationController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\SalesInvoiceController;
use App\Http\Controllers\Api\UserAccountController;
use App\Http\Controllers\Api\UserController;
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
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard/total-cash-balance', [DashboardController::class, 'totalCashBalance']);
    Route::get('/dashboard/collections', [DashboardController::class, 'collections']);
    Route::get('/dashboard/payments', [DashboardController::class, 'payments']);
    Route::get('/dashboard/upcoming-dues', [DashboardController::class, 'upcomingDues']);
    Route::get('/dashboard/upcoming-dues-list', [DashboardController::class, 'upcomingDuesList']);
    Route::get('/dashboard/cash-flow', [DashboardController::class, 'cashFlow']);
    Route::get('/dashboard/recent-activity', [DashboardController::class, 'recentActivity']);
    Route::get('/financial-account-application-types', [FinancialAccountApplicationTypeController::class, 'index']);
    Route::get('/credit-cards', [CreditCardController::class, 'index']);
    Route::get('/credit-cards/{financialAccount}', [CreditCardController::class, 'show']);
    Route::get('/credit-cards/{financialAccount}/payment-methods', [CreditCardController::class, 'paymentMethods']);
    Route::get('/credit-cards/{financialAccount}/funding-accounts', [CreditCardController::class, 'fundingAccounts']);
    Route::post('/credit-cards/{financialAccount}/payments', [CreditCardController::class, 'pay']);
    Route::get('/credit-cards/{financialAccount}/adjustments', [CreditCardController::class, 'adjustments']);
    Route::post('/credit-cards/{financialAccount}/adjustments', [CreditCardController::class, 'storeAdjustment']);
    Route::get('/credit-cards/{financialAccount}/adjustments/{financialAccountAdjustment}', [CreditCardController::class, 'showAdjustment']);
    Route::post('/credit-cards/{financialAccount}/adjustments/{financialAccountAdjustment}/void', [CreditCardController::class, 'voidAdjustment']);
    Route::apiResource('users', UserController::class)->middleware('super_admin');
    Route::get('/financial-institutions/list', [FinancialInstitutionController::class, 'list']);
    Route::get('/user-accounts/list', [UserAccountController::class, 'list']);
    Route::get('/chart-account-groups/list', [ChartAccountGroupController::class, 'list']);
    Route::get('/chart-accounts/list', [ChartAccountController::class, 'list']);
    Route::get('/chart-accounts/tree', [ChartAccountController::class, 'tree']);
    Route::get('/expense-types/list', [ExpenseTypeController::class, 'list']);
    Route::get('/expense-categories/list', [ExpenseCategoryController::class, 'list']);
    Route::get('/expense-items/list', [ExpenseItemController::class, 'list']);
    Route::get('/expense-setup', [ExpenseSetupController::class, 'index']);
    Route::get('/expense-ledger', [ExpenseLedgerController::class, 'index']);
    Route::get('/expenses/ledger', [ExpenseLedgerController::class, 'index']);
    Route::apiResources([
        'chart-account-groups' => ChartAccountGroupController::class,
        'chart-accounts' => ChartAccountController::class,
        'expense-types' => ExpenseTypeController::class,
        'expense-categories' => ExpenseCategoryController::class,
        'expense-items' => ExpenseItemController::class,
        'branches' => BranchController::class,
        'financial-institutions' => FinancialInstitutionController::class,
        'financial-accounts' => FinancialAccountController::class,
        'user-accounts' => UserAccountController::class,
        'payment-methods' => PaymentMethodController::class,
        'payment-obligations' => PaymentObligationController::class,
        'counterparties' => CounterpartyController::class,
        'purchase-orders' => PurchaseOrderController::class,
        'expenses' => ExpenseController::class,
        'sales-invoices' => SalesInvoiceController::class,
    ]);
    Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve']);
    Route::post('/expenses/{expense}/reject', [ExpenseController::class, 'reject']);
    Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::get('/loans/creation-options', [LoanController::class, 'creationOptions']);
    Route::get('/loans/upcoming-dues', [LoanController::class, 'upcomingDues']);
    Route::get('/loan-installments/upcoming-dues', [LoanController::class, 'upcomingDues']);
    Route::get('/loans/list', [LoanController::class, 'index']);
    Route::apiResource('loans', LoanController::class);
    Route::post('/loans/{loan}/cancel', [LoanController::class, 'cancel']);
    Route::get('/loans/{loan}/payment-methods', [LoanController::class, 'paymentMethods']);
    Route::get('/loans/{loan}/payment-accounts', [LoanController::class, 'paymentAccounts']);
    Route::get('/loans/{loan}/payments', [LoanController::class, 'payments']);
    Route::post('/loans/{loan}/payments', [LoanController::class, 'storePayment']);
    Route::delete('/loans/{loan}/payments/{loanPayment}', [LoanController::class, 'destroyPayment']);
    Route::patch('/loans/{loan}/installments/{loanInstallment}', [LoanInstallmentController::class, 'update']);
    Route::get('/loans/{loan}/installments/{loanInstallment}/adjustments', [LoanInstallmentController::class, 'adjustments']);
    Route::post('/payments/{payment}/post', [PaymentController::class, 'post']);
    Route::post('/payments/{payment}/void', [PaymentController::class, 'void']);
    Route::apiResource('customer-refunds', CustomerRefundController::class)
        ->parameters(['customer-refunds' => 'customerRefund'])
        ->only(['index', 'store', 'show', 'destroy']);
    Route::post('/customer-refunds/{customerRefund}/post', [CustomerRefundController::class, 'post']);
    Route::post('/customer-refunds/{customerRefund}/void', [CustomerRefundController::class, 'void']);
    Route::apiResource('end-of-day-settlements', EndOfDaySettlementController::class)
        ->parameters(['end-of-day-settlements' => 'endOfDaySettlement'])
        ->only(['index', 'store', 'show', 'destroy']);
    Route::post('/end-of-day-settlements/{endOfDaySettlement}/post', [EndOfDaySettlementController::class, 'post']);
    Route::post('/end-of-day-settlements/{endOfDaySettlement}/void', [EndOfDaySettlementController::class, 'void']);
    Route::get('/financial-accounts/{financialAccount}/balance', [FinancialAccountController::class, 'balance']);
    Route::get('/financial-accounts/{financialAccount}/transactions', [FinancialAccountController::class, 'movements']);
    Route::get('/financial-accounts/{financialAccount}/movements', [FinancialAccountController::class, 'movements']);
    Route::get('/financial-accounts/{financialAccount}/adjustments', [FinancialAccountAdjustmentController::class, 'accountIndex']);
    Route::post('/financial-accounts/{financialAccount}/adjustments', [FinancialAccountAdjustmentController::class, 'accountStore']);
    Route::get('/financial-accounts/{financialAccount}/adjustments/{financialAccountAdjustment}', [FinancialAccountAdjustmentController::class, 'accountShow']);
    Route::post('/financial-accounts/{financialAccount}/adjustments/{financialAccountAdjustment}/void', [FinancialAccountAdjustmentController::class, 'accountVoid']);
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
    Route::post('/integrations/customer-orders/summaries', [IntegratedCustomerOrderController::class, 'summaries']);
    Route::get('/integrations/customer-orders/refund-candidates', [IntegratedCustomerOrderController::class, 'refundCandidates']);
    Route::post('/integrations/customer-orders/refund-candidates', [IntegratedCustomerOrderController::class, 'refundCandidates']);
    Route::post('/shopOrderTransaction/fetchOnlineShopOrderRefundTransactionListV2', [IntegratedCustomerOrderController::class, 'refundCandidates']);
    Route::get('/integrations/customer-payment-report', [IntegratedCustomerOrderController::class, 'paymentReport']);
    Route::post('/integrations/customer-order/payments', [IntegratedCustomerOrderController::class, 'store']);
    Route::get('/integrations/purchase-order', [IntegratedPurchaseOrderController::class, 'show']);
    Route::post('/integrations/purchase-orders/summaries', [IntegratedPurchaseOrderController::class, 'summaries']);
    Route::post('/integrations/purchase-order/payments', [IntegratedPurchaseOrderController::class, 'store']);
    Route::post('/integrations/expenses', [IntegratedExpenseController::class, 'store']);
    Route::post('/integrations/expenses/summaries', [IntegratedExpenseController::class, 'summaries']);
    Route::post('/integrations/expenses/cancel', [IntegratedExpenseController::class, 'cancel']);
    Route::get('/integrations/customer-payment-reconciliation', [CustomerPaymentReconciliationController::class, 'index']);
    Route::put('/integrations/customer-payment-lines/{paymentLine}/confirmation', [CustomerPaymentReconciliationController::class, 'update']);
    Route::apiResource('interbranch-orders', InterbranchOrderController::class);
    Route::post('/interbranch-orders/{interbranchOrder}/post', [InterbranchOrderController::class, 'post']);
    Route::post('/interbranch-orders/{interbranchOrder}/void', [InterbranchOrderController::class, 'void']);
    Route::get('/journal-entries', [JournalEntryController::class, 'index']);
    Route::get('/journal-entries/{journalEntry}', [JournalEntryController::class, 'show']);
});
