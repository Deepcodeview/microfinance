<?php

declare(strict_types=1);

use App\Controllers\AccountingController;
use App\Controllers\AdminController;
use App\Controllers\ApplicationController;
use App\Controllers\ApprovalController;
use App\Controllers\AuthController;
use App\Controllers\CashHandoverController;
use App\Controllers\CollectionController;
use App\Controllers\CustomerController;
use App\Controllers\DepositController;
use App\Controllers\DepositProductController;
use App\Controllers\DocumentController;
use App\Controllers\LoanController;
use App\Controllers\LoanProductController;
use App\Controllers\MetaController;
use App\Controllers\MigrationController;
use App\Controllers\NotificationController;
use App\Controllers\PaymentController;
use App\Controllers\PublicController;
use App\Controllers\ReportController;
use App\Controllers\SupportController;
use App\Controllers\UserController;
use App\Core\Router;

return function (Router $r): void {
    $r->add('GET', '/api/v1/health', [MetaController::class, 'health'], public: true);
    $r->add('GET', '/api/v1/receipts/verify/{code}', [PaymentController::class, 'verifyReceipt'], public: true);

    // Customer app before sign-in: plans, calculators, self-registration
    $r->add('GET', '/api/v1/public/catalog', [PublicController::class, 'catalog'], public: true);
    $r->add('GET', '/api/v1/public/app-version', [PublicController::class, 'appVersion'], public: true);
    $r->add('POST', '/api/v1/public/loan-products/{id}/preview', [PublicController::class, 'loanPreview'], public: true);
    $r->add('POST', '/api/v1/public/deposit-products/{id}/preview', [PublicController::class, 'depositPreview'], public: true);
    $r->add('POST', '/api/v1/public/register', [PublicController::class, 'register'], public: true);

    // Auth
    $r->add('POST', '/api/v1/auth/login', [AuthController::class, 'login'], public: true);
    $r->add('POST', '/api/v1/auth/mfa', [AuthController::class, 'verifyMfa'], public: true);
    $r->add('POST', '/api/v1/auth/logout', [AuthController::class, 'logout']);
    $r->add('POST', '/api/v1/me/mfa/setup', [AuthController::class, 'mfaSetup']);
    $r->add('POST', '/api/v1/me/mfa/enable', [AuthController::class, 'mfaEnable']);
    $r->add('POST', '/api/v1/me/mfa/disable', [AuthController::class, 'mfaDisable']);
    $r->add('GET', '/api/v1/me', [AuthController::class, 'me']);
    $r->add('POST', '/api/v1/me/password', [UserController::class, 'changeOwnPassword']);

    $r->add('GET', '/api/v1/dashboard', [MetaController::class, 'dashboard'], 'dashboard.view');
    $r->add('GET', '/api/v1/dashboard/insights', [MetaController::class, 'insights'], 'reports.view');
    $r->add('GET', '/api/v1/branches', [MetaController::class, 'branches']);

    // Notifications
    $r->add('GET', '/api/v1/notifications', [NotificationController::class, 'index']);
    $r->add('POST', '/api/v1/notifications/read-all', [NotificationController::class, 'markAllRead']);
    $r->add('POST', '/api/v1/notifications/{id}/read', [NotificationController::class, 'markRead']);
    $r->add('GET', '/api/v1/notifications/outbox', [NotificationController::class, 'outbox'], 'settings.manage');
    $r->add('POST', '/api/v1/branches', [AdminController::class, 'storeBranch'], 'settings.manage');

    // Customers
    $r->add('GET', '/api/v1/customers', [CustomerController::class, 'index'], 'customers.view');
    $r->add('POST', '/api/v1/customers', [CustomerController::class, 'store'], 'customers.create');
    $r->add('GET', '/api/v1/customers/{id}', [CustomerController::class, 'show'], 'customers.view');
    $r->add('PATCH', '/api/v1/customers/{id}', [CustomerController::class, 'update'], 'customers.update');
    $r->add('POST', '/api/v1/customers/{id}/location', [CustomerController::class, 'setLocation'], 'customers.locate');
    $r->add('POST', '/api/v1/customers/{id}/assign-agent', [CustomerController::class, 'assignAgent'], 'customers.assign');
    $r->add('POST', '/api/v1/customers/{id}/portal-access', [CustomerController::class, 'portalAccess'], 'customers.update');
    $r->add('GET', '/api/v1/customers/{id}/documents', [DocumentController::class, 'index'], 'documents.view');
    $r->add('POST', '/api/v1/customers/{id}/documents', [DocumentController::class, 'store'], 'documents.upload');
    $r->add('GET', '/api/v1/documents/{id}/download', [DocumentController::class, 'download'], 'documents.view');
    $r->add('POST', '/api/v1/documents/{id}/review', [DocumentController::class, 'review'], 'documents.review');

    // Customer service requests
    $r->add('GET', '/api/v1/service-requests', [SupportController::class, 'index'], 'support.view');
    $r->add('POST', '/api/v1/service-requests', [SupportController::class, 'store'], 'support.view');
    $r->add('GET', '/api/v1/service-requests/{id}', [SupportController::class, 'show'], 'support.view');
    $r->add('POST', '/api/v1/service-requests/{id}/messages', [SupportController::class, 'reply'], 'support.view');
    $r->add('PATCH', '/api/v1/service-requests/{id}', [SupportController::class, 'update'], 'support.manage');
    $r->add('GET', '/api/v1/agents', [CustomerController::class, 'agents'], 'customers.assign');

    // Self-registration review and applications made in the customer app
    $r->add('POST', '/api/v1/customers/{id}/approve-registration', [CustomerController::class, 'approveRegistration'], 'customers.approve');
    $r->add('POST', '/api/v1/customers/{id}/reject-registration', [CustomerController::class, 'rejectRegistration'], 'customers.approve');
    $r->add('GET', '/api/v1/my/applications', [ApplicationController::class, 'mine'], 'applications.submit');
    $r->add('POST', '/api/v1/my/applications', [ApplicationController::class, 'submit'], 'applications.submit');
    $r->add('POST', '/api/v1/my/applications/{id}/cancel', [ApplicationController::class, 'cancel'], 'applications.submit');
    $r->add('GET', '/api/v1/applications', [ApplicationController::class, 'index'], 'applications.review');
    $r->add('POST', '/api/v1/applications/{id}/accept', [ApplicationController::class, 'accept'], 'applications.review');
    $r->add('POST', '/api/v1/applications/{id}/reject', [ApplicationController::class, 'reject'], 'applications.review');

    // Loan products
    $r->add('GET', '/api/v1/loan-products', [LoanProductController::class, 'index'], 'loan_products.view');
    $r->add('GET', '/api/v1/loan-products/defaults', [LoanProductController::class, 'defaults'], 'loan_products.view');
    $r->add('POST', '/api/v1/loan-products/preview', [LoanProductController::class, 'preview'], 'loan_products.view');
    $r->add('POST', '/api/v1/loan-products', [LoanProductController::class, 'store'], 'loan_products.manage');
    $r->add('GET', '/api/v1/loan-products/{id}', [LoanProductController::class, 'show'], 'loan_products.view');
    $r->add('PATCH', '/api/v1/loan-products/{id}', [LoanProductController::class, 'update'], 'loan_products.manage');
    $r->add('POST', '/api/v1/loan-products/{id}/versions', [LoanProductController::class, 'addVersion'], 'loan_products.manage');
    $r->add('DELETE', '/api/v1/loan-products/{id}', [LoanProductController::class, 'destroy'], 'products.delete');

    // Loans
    $r->add('GET', '/api/v1/loans', [LoanController::class, 'index'], 'loans.view');
    $r->add('POST', '/api/v1/loans', [LoanController::class, 'store'], 'loans.apply');
    $r->add('GET', '/api/v1/loans/{id}', [LoanController::class, 'show'], 'loans.view');
    $r->add('POST', '/api/v1/loans/{id}/approve', [LoanController::class, 'approve'], 'loans.approve');
    $r->add('POST', '/api/v1/loans/{id}/reject', [LoanController::class, 'reject'], 'loans.approve');
    $r->add('POST', '/api/v1/loans/{id}/disburse', [LoanController::class, 'disburse'], 'loans.disburse');
    $r->add('POST', '/api/v1/loans/{id}/disburse-field', [LoanController::class, 'disburseField'], 'loans.disburse_field');
    $r->add('POST', '/api/v1/loans/{id}/write-off', [LoanController::class, 'requestWriteOff'], 'loans.write_off');
    $r->add('GET', '/api/v1/loans/{id}/statement', [LoanController::class, 'statement'], 'loans.view');
    $r->add('GET', '/api/v1/loans/{id}/settlement-quote', [LoanController::class, 'settlementQuote'], 'loans.view');

    // Payments & receipts
    $r->add('GET', '/api/v1/payments', [PaymentController::class, 'index'], 'payments.view');
    $r->add('POST', '/api/v1/payments', [PaymentController::class, 'store'], 'payments.create');
    $r->add('POST', '/api/v1/payments/upi-intent', [PaymentController::class, 'upiIntent'], 'payments.create');
    $r->add('POST', '/api/v1/my/payments/upi-intent', [PaymentController::class, 'upiIntent'], 'payments.self');
    $r->add('POST', '/api/v1/my/payments', [PaymentController::class, 'selfPay'], 'payments.self');
    $r->add('GET', '/api/v1/payments/{id}', [PaymentController::class, 'show'], 'payments.view');
    $r->add('GET', '/api/v1/payments/{id}/proof', [PaymentController::class, 'proof'], 'payments.view');
    $r->add('POST', '/api/v1/payments/{id}/confirm', [PaymentController::class, 'confirm'], 'payments.confirm');
    $r->add('POST', '/api/v1/payments/{id}/fail', [PaymentController::class, 'fail'], 'payments.confirm');
    $r->add('POST', '/api/v1/payments/{id}/reverse', [PaymentController::class, 'reverse'], 'payments.reverse');

    // Field collections & cash control
    $r->add('GET', '/api/v1/collections/today', [CollectionController::class, 'today'], 'collections.view');
    $r->add('GET', '/api/v1/collections/map', [CollectionController::class, 'map'], 'collections.view');
    $r->add('POST', '/api/v1/visits', [CollectionController::class, 'storeVisit'], 'visits.create');
    $r->add('GET', '/api/v1/cash/my-summary', [CashHandoverController::class, 'mySummary'], 'cash.handover');
    $r->add('GET', '/api/v1/cash/movements', [CashHandoverController::class, 'movements'], 'cash.handover');
    $r->add('GET', '/api/v1/cash/agents', [CashHandoverController::class, 'agents'], 'cash.float');
    $r->add('POST', '/api/v1/cash/floats', [CashHandoverController::class, 'issueFloat'], 'cash.float');
    $r->add('GET', '/api/v1/cash-handovers', [CashHandoverController::class, 'index'], 'cash.handover');
    $r->add('POST', '/api/v1/cash-handovers', [CashHandoverController::class, 'store'], 'cash.handover');
    $r->add('GET', '/api/v1/cash-handovers/{id}', [CashHandoverController::class, 'show'], 'cash.handover');
    $r->add('POST', '/api/v1/cash-handovers/{id}/verify', [CashHandoverController::class, 'verify'], 'cash.verify');
    $r->add('POST', '/api/v1/cash-handovers/{id}/resolve', [CashHandoverController::class, 'resolve'], 'cash.resolve');

    // Deposits (writes gated by the deposits_enabled compliance setting)
    $r->add('GET', '/api/v1/deposit-products', [DepositProductController::class, 'index'], 'deposits.view');
    $r->add('GET', '/api/v1/deposit-products/defaults', [DepositProductController::class, 'defaults'], 'deposits.view');
    $r->add('POST', '/api/v1/deposit-products/preview', [DepositProductController::class, 'preview'], 'deposits.view');
    $r->add('POST', '/api/v1/deposit-products', [DepositProductController::class, 'store'], 'deposit_products.manage');
    $r->add('GET', '/api/v1/deposit-products/{id}', [DepositProductController::class, 'show'], 'deposits.view');
    $r->add('PATCH', '/api/v1/deposit-products/{id}', [DepositProductController::class, 'update'], 'deposit_products.manage');
    $r->add('POST', '/api/v1/deposit-products/{id}/versions', [DepositProductController::class, 'addVersion'], 'deposit_products.manage');
    $r->add('DELETE', '/api/v1/deposit-products/{id}', [DepositProductController::class, 'destroy'], 'products.delete');
    $r->add('GET', '/api/v1/deposit-accounts', [DepositController::class, 'index'], 'deposits.view');
    $r->add('POST', '/api/v1/deposit-accounts', [DepositController::class, 'store'], 'deposits.open');
    $r->add('GET', '/api/v1/deposit-accounts/{id}', [DepositController::class, 'show'], 'deposits.view');
    $r->add('GET', '/api/v1/deposit-accounts/{id}/statement', [DepositController::class, 'statement'], 'deposits.view');
    $r->add('POST', '/api/v1/deposit-accounts/{id}/payout-request', [DepositController::class, 'requestPayout'], 'payouts.request');
    $r->add('GET', '/api/v1/payout-requests', [DepositController::class, 'payouts'], 'deposits.view');
    $r->add('POST', '/api/v1/payout-requests/{id}/decide', [DepositController::class, 'decidePayout'], 'payouts.approve');
    $r->add('POST', '/api/v1/payout-requests/{id}/pay', [DepositController::class, 'payPayout'], 'payouts.pay');

    // Maker-checker approvals
    $r->add('GET', '/api/v1/approvals', [ApprovalController::class, 'index'], 'approvals.view');
    $r->add('POST', '/api/v1/approvals/{id}/decide', [ApprovalController::class, 'decide'], 'approvals.view');
    $r->add('POST', '/api/v1/approvals/{id}/cancel', [ApprovalController::class, 'cancel']);

    // Accounting
    $r->add('GET', '/api/v1/accounting/accounts', [AccountingController::class, 'accounts'], 'accounting.view');
    $r->add('GET', '/api/v1/accounting/accounts/{code}/ledger', [AccountingController::class, 'ledger'], 'accounting.view');
    $r->add('GET', '/api/v1/accounting/income-statement', [AccountingController::class, 'incomeStatement'], 'accounting.view');
    $r->add('GET', '/api/v1/accounting/balance-sheet', [AccountingController::class, 'balanceSheet'], 'accounting.view');
    $r->add('GET', '/api/v1/accounting/controls', [AccountingController::class, 'controls'], 'accounting.view');
    $r->add('GET', '/api/v1/accounting/funds', [AccountingController::class, 'funds'], 'accounting.view');
    $r->add('POST', '/api/v1/accounting/funds', [AccountingController::class, 'moveFunds'], 'accounting.funds');
    $r->add('POST', '/api/v1/accounting/journals', [AccountingController::class, 'requestJournal'], 'accounting.journal');
    $r->add('GET', '/api/v1/accounting/settlements', [AccountingController::class, 'settlements'], 'accounting.view');
    $r->add('GET', '/api/v1/accounting/settlements/unsettled', [AccountingController::class, 'unsettled'], 'accounting.view');
    $r->add('POST', '/api/v1/accounting/settlements', [AccountingController::class, 'settle'], 'accounting.reconcile');
    $r->add('GET', '/api/v1/accounting/provisions', [AccountingController::class, 'provisions'], 'accounting.view');
    $r->add('POST', '/api/v1/accounting/provisions/run', [AccountingController::class, 'runProvisions'], 'accounting.reconcile');
    $r->add('GET', '/api/v1/accounting/day-close', [AccountingController::class, 'dayCloseStatus'], 'accounting.view');
    $r->add('POST', '/api/v1/accounting/day-close', [AccountingController::class, 'closeDay'], 'accounting.close');

    // Reports (each report checks its own permission)
    $r->add('GET', '/api/v1/reports/{name}', [ReportController::class, 'show']);

    // Administration
    $r->add('POST', '/api/v1/migration/import', [MigrationController::class, 'import'], 'migration.import');
    $r->add('GET', '/api/v1/users', [UserController::class, 'index'], 'users.manage');
    $r->add('GET', '/api/v1/roles', [UserController::class, 'roles'], 'users.manage');
    $r->add('POST', '/api/v1/users', [UserController::class, 'store'], 'users.manage');
    $r->add('PATCH', '/api/v1/users/{id}', [UserController::class, 'update'], 'users.manage');
    $r->add('POST', '/api/v1/users/{id}/reset-mfa', [UserController::class, 'resetMfa'], 'users.manage');
    $r->add('POST', '/api/v1/users/{id}/reset-password', [UserController::class, 'resetPassword'], 'users.manage');
    $r->add('GET', '/api/v1/audit-logs', [AdminController::class, 'auditLogs'], 'audit.view');
    $r->add('GET', '/api/v1/settings', [AdminController::class, 'settings'], 'settings.manage');
    $r->add('PATCH', '/api/v1/settings', [AdminController::class, 'updateSettings'], 'settings.manage');
    $r->add('GET', '/api/v1/holidays', [AdminController::class, 'holidays']);
    $r->add('POST', '/api/v1/holidays', [AdminController::class, 'storeHoliday'], 'holidays.manage');
    $r->add('DELETE', '/api/v1/holidays/{date}', [AdminController::class, 'deleteHoliday'], 'holidays.manage');
};
