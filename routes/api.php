<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientAccountController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\RouterController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentAllocationController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\MpesaController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ExpenditureController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalDashboardController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalPaymentController;
use App\Http\Controllers\Portal\PortalTicketController;
use App\Http\Controllers\Portal\PortalProfileController;
use App\Http\Controllers\Portal\CaptivePortalController;
use App\Http\Controllers\Api\RadiusController;
use App\Http\Controllers\Api\RadiusAccountingController;
use App\Http\Controllers\Portal\PortalRegisterController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\FupController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminRoleController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\RadiusSettingsController;
use App\Http\Controllers\Api\TicketEscalateController;
use App\Http\Controllers\Api\TenantRegistrationController;
use App\Http\Controllers\Api\PlatformAdminController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PlatformSubscriptionController;
use App\Http\Controllers\Api\SubscriptionPaymentController;
use App\Http\Controllers\Api\CustomerSubscriptionController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ProspectController;
use App\Http\Controllers\Api\ClientNoteController;
use App\Http\Controllers\Api\ClientTagController;
use App\Http\Controllers\Api\ClientCustomFieldController;
use App\Http\Controllers\Api\WorkOrderController;
use App\Http\Controllers\Api\OltController;
use App\Http\Controllers\Api\FiberController;
use App\Http\Controllers\Api\NocController;
use App\Http\Controllers\Api\IpamController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\LoginHistoryController;
use App\Http\Controllers\Api\NetworkDashboardController;
use App\Http\Controllers\Api\ServiceNetworkController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\ServiceManagementController;
use App\Http\Controllers\Api\CustomerEquipmentController;
use App\Http\Controllers\Api\RouterConfigurationController;
use App\Http\Controllers\Api\RadiusAdvancedController;
use App\Http\Controllers\Api\FiberExtensionController;
use App\Http\Controllers\Api\InventoryManagementController;
use App\Http\Controllers\Api\InventoryOperationsController;
use App\Http\Controllers\Api\SupportCatalogController;
use App\Http\Controllers\Api\CommunicationsController;
use App\Http\Controllers\Api\CustomerExperienceController;
use App\Http\Controllers\Api\SecurityAdminController;
use App\Http\Controllers\Api\FieldOperationsController;
use App\Http\Controllers\Api\ReportingToolsController;

// ─── ISP self-registration (this is how a new tenant signs up for PrimeBill itself) ──
Route::prefix('tenants')->group(function () {
    Route::post('/register',   [TenantRegistrationController::class, 'register'])->middleware('throttle:5,1');
    Route::get('/check-slug',  [TenantRegistrationController::class, 'checkSlug'])->middleware('throttle:30,1');
});

// ─── Customer Lifecycle Management ─────────────────────────────────────────
Route::prefix('clients/{client}')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::prefix('subscriptions')->group(function () {
        Route::get('/',                            [CustomerSubscriptionController::class, 'index']);
        Route::get('/active',                      [CustomerSubscriptionController::class, 'active']);
        Route::get('/expiring-soon',               [CustomerSubscriptionController::class, 'expiringSoon']);
        Route::get('/{subscription}',              [CustomerSubscriptionController::class, 'show']);
        Route::post('/',                           [CustomerSubscriptionController::class, 'store']);
        Route::post('/{subscription}/activate',    [CustomerSubscriptionController::class, 'activate']);
        Route::post('/{subscription}/suspend',     [CustomerSubscriptionController::class, 'suspend']);
        Route::post('/{subscription}/resume',      [CustomerSubscriptionController::class, 'resume']);
        Route::post('/{subscription}/cancel',      [CustomerSubscriptionController::class, 'cancel']);
        Route::post('/{subscription}/upgrade',     [CustomerSubscriptionController::class, 'upgrade']);
        Route::post('/{subscription}/renew',       [CustomerSubscriptionController::class, 'renew']);
    });
});

// ─── Subscription & Licensing Engine ───────────────────────────────────────
Route::prefix('subscription')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/plans',           [SubscriptionController::class, 'plans']);
    Route::get('/current',         [SubscriptionController::class, 'current']);
    Route::post('/start-trial',    [SubscriptionController::class, 'startTrial']);
    Route::post('/convert',        [SubscriptionController::class, 'convert']);
    Route::post('/cancel',         [SubscriptionController::class, 'cancel']);
    Route::get('/invoices',        [SubscriptionController::class, 'invoices']);
    Route::get('/usage',           [SubscriptionController::class, 'usage']);

    // Subscription Payments (M-Pesa)
    Route::prefix('payment')->group(function () {
        Route::post('/initiate',   [SubscriptionPaymentController::class, 'initiate']);
        Route::post('/callback',   [SubscriptionPaymentController::class, 'callback']);
        Route::get('/history',     [SubscriptionPaymentController::class, 'history']);
    });
});

// RADIUS accounting webhook (no auth)
Route::post('/webhooks/radius/accounting', [RadiusAccountingController::class, 'accounting']);

// M-Pesa callbacks (NO auth)
Route::prefix('mpesa')->group(function () {
    Route::middleware(\App\Http\Middleware\VerifyMpesaCallback::class)->group(function () {
        Route::post('/stk-callback',      [MpesaController::class, 'stkCallback']);
        Route::post('/c2b-validation',    [MpesaController::class, 'c2bValidation']);
        Route::post('/c2b-confirmation',  [MpesaController::class, 'c2bConfirmation']);
    });
});

// Public auth routes
Route::prefix('auth')->group(function () {
    Route::post('/login',           [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/password/forgot', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:5,1');
    Route::post('/password/reset',  [PasswordResetController::class, 'reset']);
});

// Client Portal routes — slug-prefixed so a public, unauthenticated visitor
// (captive portal, registration, login) can be resolved to the correct
// tenant before any auth happens. TenantResolver reads {tenant_slug} from
// the route when there's no logged-in user yet, and switches to reading it
// from the authenticated user once there is one — see TenantResolver.
Route::prefix('portal/{tenant_slug}')->middleware('tenant')->group(function () {
    Route::post('/register', [PortalRegisterController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login',    [PortalAuthController::class, 'login'])->middleware('throttle:10,1');

    // Captive portal — public, no Sanctum auth required
    Route::prefix('captive')->group(function () {
        Route::middleware('throttle:60,1')->group(function () {
            Route::get('/plans',             [CaptivePortalController::class, 'plans']);
            Route::get('/theme',             [CaptivePortalController::class, 'theme']);
        });
        Route::middleware('throttle:20,1')->group(function () {
            Route::get('/status/{username}', [CaptivePortalController::class, 'status']);
        });
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('/pay',    [CaptivePortalController::class, 'pay']);
            Route::post('/redeem', [VoucherController::class, 'redeem']); // voucher redemption — also public
        });
    });

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/logout',                          [PortalAuthController::class, 'logout']);
        Route::get('/dashboard',                        [PortalDashboardController::class, 'index']);
        Route::get('/invoices',                         [PortalInvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}',               [PortalInvoiceController::class, 'show']);
        Route::get('/payments',                         [PortalPaymentController::class, 'index']);
        Route::post('/payments/stk-push',               [PortalPaymentController::class, 'stkPush']);
        Route::get('/tickets',                          [PortalTicketController::class, 'index']);
        Route::post('/tickets',                         [PortalTicketController::class, 'store']);
        Route::get('/tickets/{ticket}',                 [PortalTicketController::class, 'show']);
        Route::post('/tickets/{ticket}/reply',          [PortalTicketController::class, 'reply']);
        Route::get('/profile',                          [PortalProfileController::class, 'index']);
        Route::get('/balance',                          [PortalProfileController::class, 'balance']);
        Route::put('/profile',                          [PortalProfileController::class, 'update']);
        Route::post('/profile/change-password',         [PortalProfileController::class, 'changePassword']);
    });
});

// ─── Protected Admin/Staff routes ─────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'tenant', 'auth.harden', 'security.headers', 'ip.restriction'])->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::get('/me',                [AuthController::class, 'me']);
        Route::post('/logout',           [AuthController::class, 'logout']);
        Route::post('/change-password',  [AuthController::class, 'changePassword']);
    });

    // MFA
    Route::prefix('mfa')->middleware('auth:sanctum')->group(function () {
        Route::post('/generate',     [MfaController::class, 'generate']);
        Route::post('/enable',       [MfaController::class, 'enable']);
        Route::post('/verify',       [MfaController::class, 'verify']);
        Route::post('/challenge',    [MfaController::class, 'challenge']);
        Route::post('/disable',      [MfaController::class, 'disable']);
        Route::get('/status',        [MfaController::class, 'status']);
        Route::post('/backup-codes', [MfaController::class, 'regenerateBackupCodes']);
    });

    // API Keys
    Route::prefix('api-keys')->middleware('auth:sanctum')->group(function () {
        Route::get('/',  [ApiKeyController::class, 'index']);
        Route::post('/', [ApiKeyController::class, 'store']);
        Route::delete('/{id}', [ApiKeyController::class, 'destroy']);
    });

    // Login History
    Route::prefix('login-history')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [LoginHistoryController::class, 'index']);
        Route::get('/security-events', [LoginHistoryController::class, 'securityEvents']);
    });

    // Session Management
    Route::prefix('sessions')->middleware('auth:sanctum')->group(function () {
        Route::get('/',                [SessionController::class, 'index']);
        Route::delete('/revoke-all',   [SessionController::class, 'revokeAll']);
        Route::delete('/{id}',         [SessionController::class, 'destroy']);
    });

    // Leads (CRM)
    Route::prefix('leads')->middleware('permission:view leads')->group(function () {
        Route::get('/stats',              [LeadController::class, 'stats']); // before /{lead}
        Route::get('/',                   [LeadController::class, 'index']);
        Route::post('/',                  [LeadController::class, 'store'])->middleware('permission:create leads');
        Route::get('/{lead}',             [LeadController::class, 'show']);
        Route::put('/{lead}',             [LeadController::class, 'update'])->middleware('permission:edit leads');
        Route::delete('/{lead}',          [LeadController::class, 'destroy'])->middleware('permission:delete leads');
        Route::post('/{lead}/convert',    [LeadController::class, 'convert'])->middleware('permission:create prospects');
        Route::post('/{lead}/lost',       [LeadController::class, 'markLost'])->middleware('permission:edit leads');
    });

    // Prospects (Sales Pipeline)
    Route::prefix('prospects')->middleware('permission:view prospects')->group(function () {
        Route::get('/stats',              [ProspectController::class, 'stats']); // before /{prospect}
        Route::get('/',                   [ProspectController::class, 'index']);
        Route::post('/',                  [ProspectController::class, 'store'])->middleware('permission:create prospects');
        Route::get('/{prospect}',         [ProspectController::class, 'show']);
        Route::put('/{prospect}',         [ProspectController::class, 'update'])->middleware('permission:edit prospects');
        Route::delete('/{prospect}',      [ProspectController::class, 'destroy'])->middleware('permission:delete prospects');
        Route::post('/{prospect}/advance',[ProspectController::class, 'advance'])->middleware('permission:edit prospects');
        Route::post('/{prospect}/won',    [ProspectController::class, 'markWon'])->middleware('permission:edit prospects');
        Route::post('/{prospect}/lost',   [ProspectController::class, 'markLost'])->middleware('permission:edit prospects');
    });

    // Clients
    Route::prefix('clients')->middleware('permission:view clients')->group(function () {
        Route::get('/',                                     [ClientController::class, 'index']);
        Route::post('/',                                    [ClientController::class, 'store'])->middleware('permission:create clients');
        Route::get('/{client}',                             [ClientController::class, 'show']);
        Route::put('/{client}',                             [ClientController::class, 'update'])->middleware('permission:edit clients');
        Route::delete('/{client}',                          [ClientController::class, 'destroy'])->middleware('permission:delete clients');
        Route::get('/{client}/accounts',                    [ClientController::class, 'accounts']);
        Route::get('/{client}/invoices',                    [ClientController::class, 'invoices']);
        Route::get('/{client}/payments',                    [ClientController::class, 'payments']);
        Route::get('/{client}/balance',                     [ClientController::class, 'balance']);
        Route::get('/{client}/tickets',                     [ClientController::class, 'tickets']);

        // CRM — Notes
        Route::prefix('{client}/notes')->group(function () {
            Route::get('/',              [ClientNoteController::class, 'index']);
            Route::post('/',             [ClientNoteController::class, 'store'])->middleware('permission:edit clients');
            Route::get('/{noteId}',      [ClientNoteController::class, 'show']);
            Route::put('/{noteId}',      [ClientNoteController::class, 'update'])->middleware('permission:edit clients');
            Route::delete('/{noteId}',   [ClientNoteController::class, 'destroy'])->middleware('permission:edit clients');
            Route::post('/{noteId}/pin', [ClientNoteController::class, 'togglePin'])->middleware('permission:edit clients');
        });

        // CRM — Tags
        Route::get('/{client}/tags',      [ClientTagController::class, 'clientTags']);
        Route::post('/{client}/tags/assign',   [ClientTagController::class, 'assignToClient']);
        Route::delete('/{client}/tags/remove', [ClientTagController::class, 'removeFromClient']);

        // CRM — Custom Fields
        Route::get('/{client}/custom-fields',           [ClientCustomFieldController::class, 'clientValues']);
        Route::put('/{client}/custom-fields',           [ClientCustomFieldController::class, 'updateClientValues']);

        Route::post('/{client}/suspend',                    [ClientController::class, 'suspend'])->middleware('permission:suspend clients');
        Route::post('/{client}/activate',                   [ClientController::class, 'activate'])->middleware('permission:activate clients');
        Route::post('/{client}/accounts',                   [ClientAccountController::class, 'store'])->middleware('permission:edit clients');
        Route::put('/{client}/accounts/{account}',          [ClientAccountController::class, 'update'])->middleware('permission:edit clients');
        Route::delete('/{client}/accounts/{account}',       [ClientAccountController::class, 'destroy'])->middleware('permission:edit clients');
        Route::get('/{client}/accounts/{account}/status',   [ClientAccountController::class, 'serviceStatus']);
    });

    // Plans
    Route::prefix('plans')->middleware('permission:view plans')->group(function () {
        Route::get('/',              [PlanController::class, 'index']);
        Route::post('/',             [PlanController::class, 'store'])->middleware('permission:create plans');
        Route::get('/{plan}',        [PlanController::class, 'show']);
        Route::put('/{plan}',        [PlanController::class, 'update'])->middleware('permission:edit plans');
        Route::delete('/{plan}',     [PlanController::class, 'destroy'])->middleware('permission:delete plans');
        Route::get('/{plan}/clients',[PlanController::class, 'clients']);
        Route::post('/{plan}/assign',[PlanController::class, 'assign'])->middleware('permission:edit clients');
    });

    // Plan templates (quick-create presets for the "New Plan from Template" picker)
    Route::get('/plan-templates', [PlanController::class, 'templates'])->middleware('permission:view plans');

    // RADIUS
    Route::prefix('radius')->middleware('permission:view radius')->group(function () {
        Route::get('/stats',    [RadiusController::class, 'stats']);
        Route::get('/sessions', [RadiusController::class, 'sessions']);
        Route::post('/sync',    [RadiusController::class, 'sync'])->middleware('permission:sync radius');
    });

    // Routers
    Route::prefix('routers')->middleware('permission:view routers')->group(function () {
        Route::get('/',                        [RouterController::class, 'index']);
        Route::post('/',                       [RouterController::class, 'store'])->middleware('permission:create routers');
        Route::get('/{router}',                [RouterController::class, 'show']);
        Route::put('/{router}',                [RouterController::class, 'update'])->middleware('permission:edit routers');
        Route::delete('/{router}',             [RouterController::class, 'destroy'])->middleware('permission:delete routers');
        Route::post('/{router}/test-connection',[RouterController::class, 'testConnection']);
        Route::get('/{router}/resources',      [RouterController::class, 'resources']);
        Route::get('/{router}/sessions',       [RouterController::class, 'sessions']);
    });

    // Invoices
    Route::prefix('invoices')->middleware('permission:view invoices')->group(function () {
        Route::get('/',              [InvoiceController::class, 'index']);
        Route::post('/',             [InvoiceController::class, 'store'])->middleware('permission:create invoices');
        Route::post('/bulk-generate',[InvoiceController::class, 'bulkGenerate'])->middleware('permission:create invoices');
        Route::get('/{invoice}',     [InvoiceController::class, 'show']);
        Route::get('/{invoice}/pdf', [InvoiceController::class, 'pdf']);
        Route::put('/{invoice}',     [InvoiceController::class, 'update'])->middleware('permission:edit invoices');
        Route::delete('/{invoice}',  [InvoiceController::class, 'destroy'])->middleware('permission:delete invoices');
    });

    // Payments
    Route::prefix('payments')->middleware('permission:view payments')->group(function () {
        Route::get('/summary',           [PaymentController::class, 'summary']);
        Route::get('/{payment}/receipt', [PaymentController::class, 'receipt']);
        Route::get('/',                  [PaymentController::class, 'index']);
        Route::post('/',                 [PaymentController::class, 'store'])->middleware('permission:create payments');
        Route::get('/{payment}',         [PaymentController::class, 'show']);
        Route::delete('/{payment}',      [PaymentController::class, 'destroy'])->middleware('permission:delete payments');
    });

    // Payment Allocations — split one payment across multiple invoices
    Route::prefix('payment-allocations')->middleware('permission:view payments')->group(function () {
        Route::get('/',                            [PaymentAllocationController::class, 'index']);
        Route::post('/',                           [PaymentAllocationController::class, 'store'])->middleware('permission:create payments');
        Route::get('/{allocation}',                [PaymentAllocationController::class, 'show']);
        Route::post('/{allocation}/reverse',       [PaymentAllocationController::class, 'reverse'])->middleware('permission:create payments');
    });

    // Advanced Billing / Finance — wallets, credit/debit notes, refunds,
    // payment plans, financial statements, usage billing
    Route::prefix('finance')->middleware('permission:view finance')->group(function () {
        // Wallets
        Route::get('/wallet/balance',                [FinanceController::class, 'walletBalance']);
        Route::get('/wallet/transactions',           [FinanceController::class, 'walletTransactions']);
        Route::post('/wallet/deposit',               [FinanceController::class, 'walletDeposit'])->middleware('permission:create payments');
        Route::post('/wallet/withdraw',              [FinanceController::class, 'walletWithdraw'])->middleware('permission:create payments');

        // Credit Notes
        Route::get('/credit-notes',                  [FinanceController::class, 'creditNotesIndex']);
        Route::post('/credit-notes',                 [FinanceController::class, 'creditNoteStore'])->middleware('permission:create invoices');
        Route::post('/credit-notes/{creditNote}/reverse', [FinanceController::class, 'creditNoteReverse'])->middleware('permission:create invoices');

        // Debit Notes
        Route::get('/debit-notes',                   [FinanceController::class, 'debitNotesIndex']);
        Route::post('/debit-notes',                  [FinanceController::class, 'debitNoteStore'])->middleware('permission:create invoices');
        Route::post('/debit-notes/{debitNote}/reverse', [FinanceController::class, 'debitNoteReverse'])->middleware('permission:create invoices');

        // Refunds
        Route::get('/refunds',                       [FinanceController::class, 'refundsIndex']);
        Route::post('/refunds',                      [FinanceController::class, 'refundStore'])->middleware('permission:create payments');
        Route::post('/refunds/{refund}/reverse',     [FinanceController::class, 'refundReverse'])->middleware('permission:create payments');

        // Payment Plans
        Route::get('/payment-plans',                 [FinanceController::class, 'paymentPlansIndex']);
        Route::post('/payment-plans',                [FinanceController::class, 'paymentPlanStore'])->middleware('permission:create invoices');
        Route::post('/installments/{installment}/pay', [FinanceController::class, 'paymentPlanRecordPayment'])->middleware('permission:create payments');

        // Financial Statements
        Route::get('/statement/trial-balance',       [FinanceController::class, 'trialBalance']);
        Route::get('/statement/revenue',             [FinanceController::class, 'revenueRecognition']);
        Route::get('/statement/verify-ledger',       [FinanceController::class, 'verifyLedger']);

        // Usage Billing
        Route::get('/usage/compute',                 [FinanceController::class, 'usageCompute']);
        Route::post('/usage/record',                 [FinanceController::class, 'usageRecord'])->middleware('permission:create invoices');
    });

    // M-Pesa (protected)
    Route::prefix('mpesa')->group(function () {
        Route::post('/stk-push', [MpesaController::class, 'stkPush']);
    });

    // SMS
    Route::prefix('sms')->middleware('permission:view sms')->group(function () {
        Route::post('/send',      [SmsController::class, 'send'])->middleware('permission:send sms');
        Route::post('/send-bulk', [SmsController::class, 'sendBulk'])->middleware('permission:send sms');
        Route::get('/logs',       [SmsController::class, 'logs']);
        Route::get('/balance',    [SmsController::class, 'balance']);
        Route::get('/templates',  [SmsController::class, 'templates']);
    });

    // Tickets
    Route::prefix('tickets')->middleware('permission:view tickets')->group(function () {
        Route::get('/stats',              [TicketController::class, 'stats']); // before /{ticket}
        Route::get('/',                   [TicketController::class, 'index']);
        Route::post('/',                  [TicketController::class, 'store'])->middleware('permission:create tickets');
        Route::get('/{ticket}',           [TicketController::class, 'show']);
        Route::put('/{ticket}',           [TicketController::class, 'update'])->middleware('permission:edit tickets');
        Route::post('/{ticket}/reply',    [TicketController::class, 'reply'])->middleware('permission:edit tickets');
        Route::post('/{ticket}/assign',   [TicketController::class, 'assign'])->middleware('permission:assign tickets');
        Route::post('/{ticket}/close',    [TicketController::class, 'close'])->middleware('permission:close tickets');
        Route::post('/{ticket}/escalate', [TicketController::class, 'escalate']);
    });

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats',              [DashboardController::class, 'stats']);
        Route::get('/traffic',            [DashboardController::class, 'traffic']);
        Route::get('/top-downloaders',    [DashboardController::class, 'topDownloaders']);
        Route::get('/analytics',          [DashboardController::class, 'analytics']);
        Route::get('/expenditure-summary', [DashboardController::class, 'expenditureSummary']);
        Route::get('/invoice-aging',      [DashboardController::class, 'invoiceAging']);
        Route::get('/churn-analysis',    [DashboardController::class, 'churnAnalysis']);
    });

    // Analytics
    Route::prefix('analytics')->group(function () {
        Route::get('/income', [AnalyticsController::class, 'income']);
        Route::get('/summary', [AnalyticsController::class, 'summary']);
    });

    // Expenditures
    Route::prefix('expenditures')->middleware('permission:view finance')->group(function () {
        Route::get('/summary',          [ExpenditureController::class, 'summary']);
        Route::get('/categories',       [ExpenditureController::class, 'categories']);
        Route::get('/',                 [ExpenditureController::class, 'index']);
        Route::post('/',                [ExpenditureController::class, 'store'])->middleware('permission:create expenditure');
        Route::get('/{expenditure}',    [ExpenditureController::class, 'show']);
        Route::put('/{expenditure}',    [ExpenditureController::class, 'update'])->middleware('permission:create expenditure');
        Route::delete('/{expenditure}', [ExpenditureController::class, 'destroy'])->middleware('permission:create expenditure');
    });

    // Commissions
    Route::prefix('commissions')->middleware('permission:view commissions')->group(function () {
        Route::get('/',                      [CommissionController::class, 'index']);
        Route::get('/summary',               [CommissionController::class, 'summary']);
        Route::post('/{commission}/approve', [CommissionController::class, 'approve'])->middleware('permission:approve commissions');
        Route::post('/{commission}/pay',     [CommissionController::class, 'pay']);
    });

    // Inventory
    Route::prefix('inventory')->middleware('permission:view inventory')->group(function () {
        Route::get('/low-stock',          [InventoryController::class, 'lowStock']);
        Route::get('/assigned',           [InventoryController::class, 'assigned']);
        Route::get('/summary',            [InventoryController::class, 'summary']);
        Route::get('/',                   [InventoryController::class, 'index']);
        Route::post('/',                  [InventoryController::class, 'store'])->middleware('permission:create inventory');
        Route::get('/{inventoryItem}',    [InventoryController::class, 'show']);
        Route::put('/{inventoryItem}',    [InventoryController::class, 'update'])->middleware('permission:edit inventory');
        Route::delete('/{inventoryItem}', [InventoryController::class, 'destroy'])->middleware('permission:delete inventory');
        Route::post('/{inventoryItem}/assign', [InventoryController::class, 'assign']);
        Route::post('/{inventoryItem}/return', [InventoryController::class, 'return']);
    });

    // Settings
    Route::prefix('settings')->middleware('permission:view settings')->group(function () {
        Route::get('/',            [SettingsController::class, 'index']);
        Route::put('/',            [SettingsController::class, 'update'])->middleware('permission:edit settings');
        Route::post('/test-sms',   [SettingsController::class, 'testSms'])->middleware('permission:edit settings');
        Route::post('/upload-logo',[SettingsController::class, 'uploadLogo'])->middleware('permission:edit settings');
        Route::get('/radius',      [RadiusSettingsController::class, 'index']);
        Route::post('/radius/test',[RadiusSettingsController::class, 'test']);
    });

    // Logs
    Route::prefix('logs')->middleware('permission:view logs')->group(function () {
        Route::get('/export',       [LogController::class, 'export']);
        Route::get('/stats',        [LogController::class, 'stats']);
        Route::get('/',             [LogController::class, 'index']);
        Route::get('/{systemLog}',  [LogController::class, 'show']);
    });

    // Reports
    Route::prefix('reports')->middleware('permission:view reports')->group(function () {
        Route::get('/income',           [ReportController::class, 'income']);
        Route::get('/clients',          [ReportController::class, 'clients']);
        Route::get('/invoices',         [ReportController::class, 'invoices']);
        Route::get('/sms',              [ReportController::class, 'sms']);
        Route::get('/network',          [ReportController::class, 'network']);
        Route::get('/inventory',        [ReportController::class, 'inventory']);
        Route::get('/expenditure',      [ReportController::class, 'expenditure']);
        Route::get('/{type}/export',    [ReportController::class, 'export'])->middleware('permission:export reports');
    });

    // FUP — stats and named routes before wildcard
    Route::prefix('fup')->middleware('permission:view fup')->group(function () {
        Route::get('/stats',                 [FupController::class, 'stats']);  // before /{account_id}
        Route::get('/logs',                  [FupController::class, 'index']);
        Route::get('/status/{account_id}',   [FupController::class, 'status']);
        Route::post('/reset/{account_id}',   [FupController::class, 'reset'])->middleware('permission:edit fup');
    });

    // Vouchers — /stats and /batches MUST come before /{voucher}
    Route::prefix('vouchers')->group(function () {
        Route::get('/stats',        [VoucherController::class, 'stats']);    // before /{voucher}
        Route::get('/batches',      [VoucherController::class, 'batches']); // before /{voucher}
        Route::get('/',             [VoucherController::class, 'index']);
        Route::post('/generate',    [VoucherController::class, 'generate']);
        Route::delete('/{voucher}', [VoucherController::class, 'destroy']);
    });

    // Admin — Users & Roles
    Route::prefix('admin')->group(function () {
        Route::prefix('users')->group(function () {
            Route::get('/',           [AdminUserController::class, 'index']);
            Route::post('/',          [AdminUserController::class, 'store']);
            Route::put('/{user}',     [AdminUserController::class, 'update']);
            Route::delete('/{user}',  [AdminUserController::class, 'destroy']);
        });
        Route::prefix('roles')->group(function () {
            Route::get('/permissions',[AdminRoleController::class, 'permissions']); // before /{role}
            Route::get('/',           [AdminRoleController::class, 'index']);
            Route::post('/',          [AdminRoleController::class, 'store']);
            Route::put('/{role}',     [AdminRoleController::class, 'update']);
        });
    });

    // Loyalty Points
    Route::prefix('loyalty')->group(function () {
        Route::get('/leaderboard',        [LoyaltyController::class, 'leaderboard']);  // before /{clientId}
        Route::get('/transactions',       [LoyaltyController::class, 'transactions']);
        Route::get('/points/{clientId}',  [LoyaltyController::class, 'getPoints']);
        Route::post('/redeem',            [LoyaltyController::class, 'redeem']);
    });

    // Referral
    Route::prefix('referral')->group(function () {
        Route::get('/code',   [ReferralController::class, 'getCode']);
        Route::post('/join',  [ReferralController::class, 'join']);
        Route::get('/stats',  [ReferralController::class, 'stats']);
    });

    // Field Operations — Work Orders
    Route::prefix('work-orders')->middleware('permission:view work-orders')->group(function () {
        Route::get('/stats',              [WorkOrderController::class, 'stats']);
        Route::get('/',                   [WorkOrderController::class, 'index']);
        Route::get('/{workOrder}',        [WorkOrderController::class, 'show']);
        Route::put('/{workOrder}',        [WorkOrderController::class, 'update'])->middleware('permission:edit work-orders');
        Route::delete('/{workOrder}',     [WorkOrderController::class, 'destroy'])->middleware('permission:delete work-orders');
        Route::post('/{workOrder}/assign',[WorkOrderController::class, 'assignTechnician'])->middleware('permission:edit work-orders');
        Route::post('/{workOrder}/status',[WorkOrderController::class, 'updateStatus'])->middleware('permission:edit work-orders');
    });

        // Technicians list (all staff users with location/status + workload)
    Route::get('/technicians', [WorkOrderController::class, 'listTechnicians'])->middleware('permission:view work-orders');

    // Technician workload
    Route::get('/technicians/{technician}/workload', [WorkOrderController::class, 'technicianWorkload'])->middleware('permission:view work-orders');

    // Work Orders — create under client
    Route::prefix('clients/{client}/work-orders')->middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::post('/', [WorkOrderController::class, 'store'])->middleware('permission:create work-orders');
    });

    // CRM — Tags (root-level routes)
    Route::prefix('tags')->middleware('permission:view tags')->group(function () {
        Route::get('/',        [ClientTagController::class, 'index']);
        Route::post('/',       [ClientTagController::class, 'store'])->middleware('permission:create tags');
        Route::get('/{tag}',   [ClientTagController::class, 'show']);
        Route::put('/{tag}',   [ClientTagController::class, 'update'])->middleware('permission:edit tags');
        Route::delete('/{tag}',[ClientTagController::class, 'destroy'])->middleware('permission:delete tags');
    });

// CRM — Custom Fields (root-level routes)
    Route::prefix('custom-fields')->middleware('permission:view custom-fields')->group(function () {
        Route::get('/',        [ClientCustomFieldController::class, 'index']);
        Route::post('/',       [ClientCustomFieldController::class, 'store'])->middleware('permission:create custom-fields');
        Route::get('/{field}', [ClientCustomFieldController::class, 'show']);
        Route::put('/{field}', [ClientCustomFieldController::class, 'update'])->middleware('permission:edit custom-fields');
        Route::delete('/{field}', [ClientCustomFieldController::class, 'destroy'])->middleware('permission:delete custom-fields');
    });

    // IPAM — IP Pools, Subnets, Allocations, Reservations, DHCP & VLANs
    Route::prefix('ipam')->group(function () {
        // Summary
        Route::get('/summary', [IpamController::class, 'summary']);

        // Pools
        Route::prefix('pools')->middleware('permission:view ipam')->group(function () {
            Route::get('/',                    [IpamController::class, 'indexPools']);
            Route::post('/',                   [IpamController::class, 'storePool'])->middleware('permission:manage ipam');
            Route::get('/{pool}',              [IpamController::class, 'showPool']);
            Route::put('/{pool}',              [IpamController::class, 'updatePool'])->middleware('permission:manage ipam');
            Route::delete('/{pool}',           [IpamController::class, 'destroyPool'])->middleware('permission:manage ipam');
        });

        // Subnets
        Route::prefix('subnets')->middleware('permission:view ipam')->group(function () {
            Route::get('/',                    [IpamController::class, 'indexSubnets']);
            Route::post('/',                   [IpamController::class, 'storeSubnet'])->middleware('permission:manage ipam');
            Route::get('/{subnet}',            [IpamController::class, 'showSubnet']);
            Route::put('/{subnet}',            [IpamController::class, 'updateSubnet'])->middleware('permission:manage ipam');
            Route::delete('/{subnet}',         [IpamController::class, 'destroySubnet'])->middleware('permission:manage ipam');
        });

        // Allocations
        Route::prefix('allocations')->middleware('permission:view ipam')->group(function () {
            Route::get('/',                    [IpamController::class, 'indexAllocations']);
            Route::post('/',                   [IpamController::class, 'storeAllocation'])->middleware('permission:manage ipam');
            Route::post('/{allocation}/release', [IpamController::class, 'releaseAllocation'])->middleware('permission:manage ipam');
            Route::get('/{allocation}/history',  [IpamController::class, 'allocationHistory']);
        });

        // Reservations
        Route::prefix('reservations')->middleware('permission:view ipam')->group(function () {
            Route::get('/',                    [IpamController::class, 'indexReservations']);
            Route::post('/',                   [IpamController::class, 'storeReservation'])->middleware('permission:manage ipam');
            Route::delete('/{reservation}',    [IpamController::class, 'destroyReservation'])->middleware('permission:manage ipam');
        });

        // DHCP
        Route::prefix('dhcp')->middleware('permission:view ipam')->group(function () {
            Route::get('/pools',               [IpamController::class, 'indexDhcpPools']);
            Route::post('/pools',              [IpamController::class, 'storeDhcpPool'])->middleware('permission:manage ipam');
            Route::get('/leases',              [IpamController::class, 'indexDhcpLeases']);
            Route::post('/leases',             [IpamController::class, 'storeDhcpLease'])->middleware('permission:manage ipam');
        });

// VLANs
        Route::prefix('vlans')->middleware('permission:view ipam')->group(function () {
            Route::get('/',                    [IpamController::class, 'indexVlans']);
            Route::post('/',                   [IpamController::class, 'storeVlan'])->middleware('permission:manage ipam');
            Route::get('/{vlan}',              [IpamController::class, 'showVlan']);
            Route::put('/{vlan}',              [IpamController::class, 'updateVlan'])->middleware('permission:manage ipam');
            Route::delete('/{vlan}',           [IpamController::class, 'destroyVlan'])->middleware('permission:manage ipam');
            Route::post('/assign',             [IpamController::class, 'assignVlan'])->middleware('permission:manage ipam');
        });
    });

    // NOC — Network Operations Center
    Route::prefix('noc')->group(function () {
        Route::get('/overview',        [NocController::class, 'overview'])->middleware('permission:view network');
        Route::get('/devices',         [NocController::class, 'devices'])->middleware('permission:view network');
        Route::get('/devices/{router}',[NocController::class, 'showDevice'])->middleware('permission:view network');
        Route::get('/devices/{router}/metrics', [NocController::class, 'metrics'])->middleware('permission:view network');

        Route::get('/alerts',          [NocController::class, 'alerts'])->middleware('permission:view network');
        Route::post('/alerts/{alert}/acknowledge', [NocController::class, 'acknowledgeAlert'])->middleware('permission:manage network');
        Route::post('/alerts/{alert}/resolve',     [NocController::class, 'resolveAlert'])->middleware('permission:manage network');

        Route::get('/links',           [NocController::class, 'links'])->middleware('permission:view network');
        Route::post('/links',          [NocController::class, 'storeLink'])->middleware('permission:manage network');
        Route::put('/links/{link}',    [NocController::class, 'updateLink'])->middleware('permission:manage network');
        Route::delete('/links/{link}', [NocController::class, 'destroyLink'])->middleware('permission:manage network');
    });

    // Network Dashboard — new unified network management API
    Route::prefix('network')->middleware('permission:view network')->group(function () {
        Route::get('/dashboard',       [NetworkDashboardController::class, 'overview']);
        Route::get('/routers',         [NetworkDashboardController::class, 'routers']);
        Route::get('/routers/{id}',    [NetworkDashboardController::class, 'routerDetail']);
        Route::get('/sessions',        [NetworkDashboardController::class, 'sessions']);
        Route::get('/events',          [NetworkDashboardController::class, 'events']);
        Route::get('/control-logs',    [NetworkDashboardController::class, 'controlLogs']);
        Route::get('/radius-stats',    [NetworkDashboardController::class, 'radiusStats']);

        // Service management actions
        Route::prefix('services')->group(function () {
            Route::get('/{account}/status',  [ServiceNetworkController::class, 'status']);
            Route::post('/{account}/suspend',  [ServiceNetworkController::class, 'suspend'])->middleware('permission:suspend clients');
            Route::post('/{account}/restore',  [ServiceNetworkController::class, 'restore'])->middleware('permission:activate clients');
            Route::post('/{account}/disconnect', [ServiceNetworkController::class, 'disconnect'])->middleware('permission:manage network');
            Route::post('/{account}/coa',      [ServiceNetworkController::class, 'coa'])->middleware('permission:manage network');
        });
    });

    // Incidents / Outage Management
    Route::prefix('incidents')->middleware('permission:view incidents')->group(function () {
        Route::get('/stats', [IncidentController::class, 'stats']);
        Route::get('/',     [IncidentController::class, 'index']);
        Route::post('/',    [IncidentController::class, 'store'])->middleware('permission:create incidents');
        Route::get('/{incident}', [IncidentController::class, 'show']);
        Route::put('/{incident}', [IncidentController::class, 'update'])->middleware('permission:edit incidents');
        Route::delete('/{incident}', [IncidentController::class, 'destroy'])->middleware('permission:delete incidents');
        Route::post('/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->middleware('permission:edit incidents');
        Route::post('/{incident}/status', [IncidentController::class, 'updateStatus'])->middleware('permission:edit incidents');
        Route::post('/{incident}/resolve', [IncidentController::class, 'resolve'])->middleware('permission:edit incidents');
        Route::post('/{incident}/close', [IncidentController::class, 'close'])->middleware('permission:edit incidents');
    });

    // Fiber / OLT — OLTs, PON ports, ONTs
    Route::prefix('olts')->middleware('permission:view fiber')->group(function () {
        Route::get('/',                       [OltController::class, 'index']);
        Route::post('/',                      [OltController::class, 'store'])->middleware('permission:manage fiber');
        Route::get('/{olt}',                  [OltController::class, 'show']);
        Route::put('/{olt}',                  [OltController::class, 'update'])->middleware('permission:manage fiber');
        Route::delete('/{olt}',               [OltController::class, 'destroy'])->middleware('permission:manage fiber');
        Route::post('/{olt}/test-connection', [OltController::class, 'testConnection'])->middleware('permission:manage fiber');

        // PON Ports
        Route::get('/{olt}/pon-ports',        [OltController::class, 'ponPorts']);
        Route::post('/{olt}/pon-ports',       [OltController::class, 'storePonPort'])->middleware('permission:manage fiber');

        // ONTs
        Route::get('/{olt}/onts',             [OltController::class, 'onts']);
        Route::post('/{olt}/onts',            [OltController::class, 'storeOnt'])->middleware('permission:manage fiber');
        Route::post('/{olt}/poll-signal',     [OltController::class, 'pollSignal'])->middleware('permission:manage fiber');
        Route::delete('/{olt}/onts/{ont}',    [OltController::class, 'destroyOnt'])->middleware('permission:manage fiber');
    });

    // ONTs — top-level detail/update
    Route::get('/onts/{ont}', [OltController::class, 'showOnt'])->middleware('permission:view fiber');
    Route::put('/onts/{ont}', [OltController::class, 'updateOnt'])->middleware('permission:manage fiber');

    // Fiber infrastructure — routes, splitters, cabinets, distribution points
    Route::prefix('fiber')->middleware('permission:view fiber')->group(function () {
        Route::get('/routes',          [FiberController::class, 'routesIndex']);
        Route::post('/routes',         [FiberController::class, 'routesStore'])->middleware('permission:manage fiber');
        Route::put('/routes/{fiberRoute}',   [FiberController::class, 'routesUpdate'])->middleware('permission:manage fiber');
        Route::delete('/routes/{fiberRoute}',[FiberController::class, 'routesDestroy'])->middleware('permission:manage fiber');

        Route::get('/splitters',       [FiberController::class, 'splittersIndex']);
        Route::post('/splitters',      [FiberController::class, 'splittersStore'])->middleware('permission:manage fiber');
        Route::delete('/splitters/{fiberSplitter}', [FiberController::class, 'splittersDestroy'])->middleware('permission:manage fiber');

        Route::get('/cabinets',        [FiberController::class, 'cabinetsIndex']);
        Route::post('/cabinets',       [FiberController::class, 'cabinetsStore'])->middleware('permission:manage fiber');
        Route::delete('/cabinets/{cabinet}', [FiberController::class, 'cabinetsDestroy'])->middleware('permission:manage fiber');

        Route::get('/distribution-points', [FiberController::class, 'dpsIndex']);
        Route::post('/distribution-points',[FiberController::class, 'dpsStore'])->middleware('permission:manage fiber');
        Route::delete('/distribution-points/{distributionPoint}', [FiberController::class, 'dpsDestroy'])->middleware('permission:manage fiber');
    });
// ─── Reconciliation catalog domains (wired Phase D) ──────────────────────
    // Each group exposes uniform REST over a `{resource}/{id}` surface backed
    // by the HandlesCatalogResources trait. `{resource}` is whitelisted in the
    // controller (unknown segments 404) and every model is tenant-scoped via
    // its BelongsToTenant trait.
    Route::prefix('service-catalog')->middleware('permission:view service-catalog')->group(function () {
        Route::get('/{resource}',           [ServiceManagementController::class, 'catalogIndex']);
        Route::post('/{resource}',          [ServiceManagementController::class, 'catalogStore'])->middleware('permission:manage service-catalog');
        Route::get('/{resource}/{id}',      [ServiceManagementController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [ServiceManagementController::class, 'catalogUpdate'])->middleware('permission:manage service-catalog');
        Route::delete('/{resource}/{id}',   [ServiceManagementController::class, 'catalogDestroy'])->middleware('permission:manage service-catalog');
    });

    Route::prefix('equipment')->middleware('permission:view equipment')->group(function () {
        Route::get('/{resource}',           [CustomerEquipmentController::class, 'catalogIndex']);
        Route::post('/{resource}',          [CustomerEquipmentController::class, 'catalogStore'])->middleware('permission:manage equipment');
        Route::get('/{resource}/{id}',      [CustomerEquipmentController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [CustomerEquipmentController::class, 'catalogUpdate'])->middleware('permission:manage equipment');
        Route::delete('/{resource}/{id}',   [CustomerEquipmentController::class, 'catalogDestroy'])->middleware('permission:manage equipment');
    });

    Route::prefix('router-config')->middleware('permission:view router-config')->group(function () {
        Route::get('/{resource}',           [RouterConfigurationController::class, 'catalogIndex']);
        Route::post('/{resource}',          [RouterConfigurationController::class, 'catalogStore'])->middleware('permission:manage router-config');
        Route::get('/{resource}/{id}',      [RouterConfigurationController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [RouterConfigurationController::class, 'catalogUpdate'])->middleware('permission:manage router-config');
        Route::delete('/{resource}/{id}',   [RouterConfigurationController::class, 'catalogDestroy'])->middleware('permission:manage router-config');
    });

    Route::prefix('radius-advanced')->middleware('permission:view radius-advanced')->group(function () {
        Route::get('/{resource}',           [RadiusAdvancedController::class, 'catalogIndex']);
        Route::post('/{resource}',          [RadiusAdvancedController::class, 'catalogStore'])->middleware('permission:manage radius-advanced');
        Route::get('/{resource}/{id}',      [RadiusAdvancedController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [RadiusAdvancedController::class, 'catalogUpdate'])->middleware('permission:manage radius-advanced');
        Route::delete('/{resource}/{id}',   [RadiusAdvancedController::class, 'catalogDestroy'])->middleware('permission:manage radius-advanced');
    });

    Route::prefix('fiber-ext')->middleware('permission:view fiber-ext')->group(function () {
        Route::get('/{resource}',           [FiberExtensionController::class, 'catalogIndex']);
        Route::post('/{resource}',          [FiberExtensionController::class, 'catalogStore'])->middleware('permission:manage fiber-ext');
        Route::get('/{resource}/{id}',      [FiberExtensionController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [FiberExtensionController::class, 'catalogUpdate'])->middleware('permission:manage fiber-ext');
        Route::delete('/{resource}/{id}',   [FiberExtensionController::class, 'catalogDestroy'])->middleware('permission:manage fiber-ext');
    });
Route::prefix('inventory-ext')->middleware('permission:view inventory-ext')->group(function () {
        Route::get('/{resource}',           [InventoryManagementController::class, 'catalogIndex']);
        Route::post('/{resource}',          [InventoryManagementController::class, 'catalogStore'])->middleware('permission:manage inventory-ext');
        Route::get('/{resource}/{id}',      [InventoryManagementController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [InventoryManagementController::class, 'catalogUpdate'])->middleware('permission:manage inventory-ext');
        Route::delete('/{resource}/{id}',   [InventoryManagementController::class, 'catalogDestroy'])->middleware('permission:manage inventory-ext');
    });

    // ─── Inventory Engine — real workflow endpoints (Phase D2) ──────────────
    // These replace generic CRUD for stock movements, transfers and purchase
    // orders with genuine business workflows. Each action is guarded by a
    // granular permission and every service call is transactional + tenant
    // scoped.
    Route::prefix('inventory/operations')->group(function () {
        // Stock movements
        Route::post('/stock/receive',   [InventoryOperationsController::class, 'receive'])->middleware('permission:inventory.stock.receive');
        Route::post('/stock/issue',     [InventoryOperationsController::class, 'issue'])->middleware('permission:inventory.stock.issue');
        Route::post('/stock/adjust',    [InventoryOperationsController::class, 'adjust'])->middleware('permission:inventory.stock.adjust');
        Route::post('/stock/return',    [InventoryOperationsController::class, 'returnStock'])->middleware('permission:inventory.stock.receive');
        Route::get('/items/{id}/balances', [InventoryOperationsController::class, 'balances'])->middleware('permission:inventory.stock.view');

        // Stock transfers
        Route::get('/transfers',            [InventoryOperationsController::class, 'transferIndex'])->middleware('permission:inventory.transfer.view');
        Route::post('/transfers',           [InventoryOperationsController::class, 'transferStore'])->middleware('permission:inventory.transfer.create');
        Route::get('/transfers/{transfer}', [InventoryOperationsController::class, 'transferShow'])->middleware('permission:inventory.transfer.view');
        Route::post('/transfers/{transfer}/approve', [InventoryOperationsController::class, 'transferApprove'])->middleware('permission:inventory.transfer.approve');
        Route::post('/transfers/{transfer}/dispatch',[InventoryOperationsController::class, 'transferDispatch'])->middleware('permission:inventory.transfer.dispatch');
        Route::post('/transfers/{transfer}/receive', [InventoryOperationsController::class, 'transferReceive'])->middleware('permission:inventory.transfer.receive');
        Route::post('/transfers/{transfer}/cancel',  [InventoryOperationsController::class, 'transferCancel'])->middleware('permission:inventory.transfer.cancel');
        Route::post('/transfers/{transfer}/reverse', [InventoryOperationsController::class, 'transferReverse'])->middleware('permission:inventory.transfer.reverse');

        // Purchase orders
        Route::get('/purchase-orders',            [InventoryOperationsController::class, 'poIndex'])->middleware('permission:inventory.po.view');
        Route::post('/purchase-orders',           [InventoryOperationsController::class, 'poStore'])->middleware('permission:inventory.po.create');
        Route::get('/purchase-orders/{po}',       [InventoryOperationsController::class, 'poShow'])->middleware('permission:inventory.po.view');
        Route::post('/purchase-orders/{po}/submit',   [InventoryOperationsController::class, 'poSubmit'])->middleware('permission:inventory.po.submit');
        Route::post('/purchase-orders/{po}/approve',  [InventoryOperationsController::class, 'poApprove'])->middleware('permission:inventory.po.approve');
        Route::post('/purchase-orders/{po}/receive',  [InventoryOperationsController::class, 'poReceive'])->middleware('permission:inventory.po.receive');
        Route::post('/purchase-orders/{po}/complete', [InventoryOperationsController::class, 'poComplete'])->middleware('permission:inventory.po.complete');
        Route::post('/purchase-orders/{po}/cancel',   [InventoryOperationsController::class, 'poCancel'])->middleware('permission:inventory.po.cancel');
    });

    Route::prefix('support-catalog')->middleware('permission:view support-catalog')->group(function () {
        Route::get('/{resource}',           [SupportCatalogController::class, 'catalogIndex']);
        Route::post('/{resource}',          [SupportCatalogController::class, 'catalogStore'])->middleware('permission:manage support-catalog');
        Route::get('/{resource}/{id}',      [SupportCatalogController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [SupportCatalogController::class, 'catalogUpdate'])->middleware('permission:manage support-catalog');
        Route::delete('/{resource}/{id}',   [SupportCatalogController::class, 'catalogDestroy'])->middleware('permission:manage support-catalog');
    });

    Route::prefix('communications')->middleware('permission:view communications')->group(function () {
        Route::post('/campaigns/{id}/transition', [CommunicationsController::class, 'transitionCampaign'])->middleware('permission:manage communications');
        Route::get('/{resource}',           [CommunicationsController::class, 'catalogIndex']);
        Route::post('/{resource}',          [CommunicationsController::class, 'catalogStore'])->middleware('permission:manage communications');
        Route::get('/{resource}/{id}',      [CommunicationsController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [CommunicationsController::class, 'catalogUpdate'])->middleware('permission:manage communications');
        Route::delete('/{resource}/{id}',   [CommunicationsController::class, 'catalogDestroy'])->middleware('permission:manage communications');
    });

    Route::prefix('customer-experience')->middleware('permission:view customer-experience')->group(function () {
        Route::get('/{resource}',           [CustomerExperienceController::class, 'catalogIndex']);
        Route::post('/{resource}',          [CustomerExperienceController::class, 'catalogStore'])->middleware('permission:manage customer-experience');
        Route::get('/{resource}/{id}',      [CustomerExperienceController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [CustomerExperienceController::class, 'catalogUpdate'])->middleware('permission:manage customer-experience');
        Route::delete('/{resource}/{id}',   [CustomerExperienceController::class, 'catalogDestroy'])->middleware('permission:manage customer-experience');
    });

    Route::prefix('security-admin')->middleware('permission:view security-admin')->group(function () {
        Route::get('/{resource}',           [SecurityAdminController::class, 'catalogIndex']);
        Route::post('/{resource}',          [SecurityAdminController::class, 'catalogStore'])->middleware('permission:manage security-admin');
        Route::get('/{resource}/{id}',      [SecurityAdminController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [SecurityAdminController::class, 'catalogUpdate'])->middleware('permission:manage security-admin');
        Route::delete('/{resource}/{id}',   [SecurityAdminController::class, 'catalogDestroy'])->middleware('permission:manage security-admin');
    });

    Route::prefix('field-ops')->middleware('permission:view field-ops')->group(function () {
        Route::get('/{resource}',           [FieldOperationsController::class, 'catalogIndex']);
        Route::post('/{resource}',          [FieldOperationsController::class, 'catalogStore'])->middleware('permission:manage field-ops');
        Route::get('/{resource}/{id}',      [FieldOperationsController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [FieldOperationsController::class, 'catalogUpdate'])->middleware('permission:manage field-ops');
        Route::delete('/{resource}/{id}',   [FieldOperationsController::class, 'catalogDestroy'])->middleware('permission:manage field-ops');
    });

    Route::prefix('reporting')->middleware('permission:view reporting')->group(function () {
        Route::get('/{resource}',           [ReportingToolsController::class, 'catalogIndex']);
        Route::post('/{resource}',          [ReportingToolsController::class, 'catalogStore'])->middleware('permission:manage reporting');
        Route::get('/{resource}/{id}',      [ReportingToolsController::class, 'catalogShow']);
        Route::put('/{resource}/{id}',      [ReportingToolsController::class, 'catalogUpdate'])->middleware('permission:manage reporting');
        Route::delete('/{resource}/{id}',   [ReportingToolsController::class, 'catalogDestroy'])->middleware('permission:manage reporting');
    });

});
// ─── Platform-admin routes (PrimeBill's own cross-tenant operator view) ──────
// Deliberately its own top-level group — NOT nested inside the block above,
// since that block carries the 'tenant' middleware and these routes must
// NOT resolve or scope to any single tenant. Gated by 'platform_admin'
// (EnsurePlatformAdmin), which checks users.is_platform_admin directly.
Route::prefix('platform')->middleware(['auth:sanctum', 'platform_admin'])->group(function () {
    // Stats & Plans
    Route::get('/stats',                  [PlatformAdminController::class, 'stats']);
    Route::get('/plans',                  [PlatformAdminController::class, 'plans']);

    // Tenant CRUD
    Route::get('/tenants',                [PlatformAdminController::class, 'tenants']);
    Route::post('/tenants',               [PlatformAdminController::class, 'createTenant']);
    Route::get('/tenants/{tenant}',       [PlatformAdminController::class, 'showTenant']);
    Route::put('/tenants/{tenant}',       [PlatformAdminController::class, 'updateTenant']);
    Route::delete('/tenants/{tenant}',   [PlatformAdminController::class, 'destroy']);

    // Tenant Configuration
    Route::post('/tenants/{tenant}/company',     [PlatformAdminController::class, 'configureCompany']);
    Route::post('/tenants/{tenant}/branding',   [PlatformAdminController::class, 'configureBranding']);
    Route::post('/tenants/{tenant}/localization', [PlatformAdminController::class, 'configureLocalization']);
    Route::post('/tenants/{tenant}/plan',       [PlatformAdminController::class, 'assignPlan']);

    // Tenant Lifecycle
    Route::post('/tenants/{tenant}/suspend',    [PlatformAdminController::class, 'suspend']);
    Route::post('/tenants/{tenant}/activate',    [PlatformAdminController::class, 'activate']);
    Route::post('/tenants/{tenant}/archive',    [PlatformAdminController::class, 'archive']);

    // Quotas & Limits
    Route::post('/tenants/{tenant}/quotas',     [PlatformAdminController::class, 'updateQuotas']);

    // Feature Flags
    Route::post('/tenants/{tenant}/features',     [PlatformAdminController::class, 'updateFeatureFlags']);
    Route::post('/tenants/{tenant}/features/add',  [PlatformAdminController::class, 'addFeatureFlag']);
    Route::post('/tenants/{tenant}/features/remove', [PlatformAdminController::class, 'removeFeatureFlag']);

    // Health & Billing
    Route::get('/tenants/{tenant}/health',       [PlatformAdminController::class, 'tenantHealth']);
    Route::get('/tenants/{tenant}/billing',      [PlatformAdminController::class, 'tenantBilling']);
    Route::get('/tenants/{tenant}/subscription', [PlatformAdminController::class, 'tenantSubscription']);

    // Impersonation
    Route::post('/tenants/{tenant}/impersonate', [PlatformAdminController::class, 'impersonate']);
    Route::post('/impersonate/end',              [PlatformAdminController::class, 'endImpersonation']);

    // Admin User Management
    Route::post('/tenants/{tenant}/admin',      [PlatformAdminController::class, 'createAdmin']);

    // Audit Log
    Route::get('/audit-log',                    [PlatformAdminController::class, 'auditLog']);

    // Subscription Management
    Route::get('/subscriptions',           [PlatformSubscriptionController::class, 'index']);
    Route::get('/subscription-stats',      [PlatformSubscriptionController::class, 'stats']);
    Route::post('/subscriptions/{subscription}/upgrade', [PlatformSubscriptionController::class, 'upgrade']);
    Route::post('/subscriptions/{subscription}/suspend', [PlatformSubscriptionController::class, 'suspend']);
    Route::post('/subscriptions/{subscription}/resume', [PlatformSubscriptionController::class, 'resume']);
    Route::post('/subscriptions/{subscription}/cancel', [PlatformSubscriptionController::class, 'cancel']);
    Route::post('/subscriptions/{subscription}/renew', [PlatformSubscriptionController::class, 'renew']);
});
