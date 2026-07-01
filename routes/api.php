<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientAccountController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\RouterController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\MpesaController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\DashboardController;
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

// RADIUS accounting webhook (no auth — protect via firewall/IP in production)
Route::post('/webhooks/radius/accounting', [RadiusAccountingController::class, 'accounting']);

// M-Pesa callbacks (NO auth)
Route::prefix('mpesa')->group(function () {
    // Use middleware class directly to avoid requiring Kernel changes
    Route::middleware(\App\Http\Middleware\VerifyMpesaCallback::class)->group(function () {
        Route::post('/stk-callback', [MpesaController::class, 'stkCallback']);
        Route::post('/c2b-validation', [MpesaController::class, 'c2bValidation']);
        Route::post('/c2b-confirmation', [MpesaController::class, 'c2bConfirmation']);
    });
});

// Public routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/password/forgot', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:5,1');
    Route::post('/password/reset', [PasswordResetController::class, 'reset']);
});

// Client Portal routes
Route::prefix('portal')->group(function () {
    Route::post('/register', [PortalRegisterController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [PortalAuthController::class, 'login'])->middleware('throttle:10,1');

    // Captive portal (hotspot) routes — intentionally public, no Sanctum auth.
    // MikroTik redirects unauthenticated hotspot clients here directly.
    // Throttles are split per-endpoint since /pay triggers a real M-Pesa
    // STK push and /status is an enumeration target.
    Route::prefix('captive')->group(function () {
        Route::middleware('throttle:60,1')->group(function () {
            Route::get('/plans', [CaptivePortalController::class, 'plans']);
        });

        Route::middleware('throttle:20,1')->group(function () {
            Route::get('/status/{username}', [CaptivePortalController::class, 'status']);
        });

        Route::middleware('throttle:5,1')->group(function () {
            Route::post('/pay', [CaptivePortalController::class, 'pay']);
        });
    });

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/logout', [PortalAuthController::class, 'logout']);
        Route::get('/dashboard', [PortalDashboardController::class, 'index']);
        Route::get('/invoices', [PortalInvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [PortalInvoiceController::class, 'show']);
        Route::get('/payments', [PortalPaymentController::class, 'index']);
        Route::post('/payments/stk-push', [PortalPaymentController::class, 'stkPush']);
        Route::get('/tickets', [PortalTicketController::class, 'index']);
        Route::post('/tickets', [PortalTicketController::class, 'store']);
        Route::get('/tickets/{ticket}', [PortalTicketController::class, 'show']);
        Route::post('/tickets/{ticket}/reply', [PortalTicketController::class, 'reply']);
        Route::get('/profile', [PortalProfileController::class, 'index']);
        Route::get('/balance', [PortalProfileController::class, 'balance']);
        Route::put('/profile', [PortalProfileController::class, 'update']);
        Route::post('/profile/change-password', [PortalProfileController::class, 'changePassword']);
    });
});

// Protected Admin/Staff routes
Route::middleware(['auth:sanctum'])->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });

    // Clients
    Route::prefix('clients')->middleware('permission:view clients')->group(function () {
        Route::get('/', [ClientController::class, 'index']);
        Route::post('/', [ClientController::class, 'store'])->middleware('permission:create clients');
        Route::get('/{client}', [ClientController::class, 'show']);
        Route::put('/{client}', [ClientController::class, 'update'])->middleware('permission:edit clients');
        Route::delete('/{client}', [ClientController::class, 'destroy'])->middleware('permission:delete clients');
        Route::get('/{client}/accounts', [ClientController::class, 'accounts']);
        Route::get('/{client}/invoices', [ClientController::class, 'invoices']);
        Route::get('/{client}/payments', [ClientController::class, 'payments']);
        Route::get('/{client}/balance', [ClientController::class, 'balance']);
        Route::get('/{client}/tickets', [ClientController::class, 'tickets']);
        Route::post('/{client}/suspend', [ClientController::class, 'suspend'])->middleware('permission:suspend clients');
        Route::post('/{client}/activate', [ClientController::class, 'activate'])->middleware('permission:activate clients');
        Route::post('/{client}/accounts', [ClientAccountController::class, 'store'])->middleware('permission:edit clients');
        Route::put('/{client}/accounts/{account}', [ClientAccountController::class, 'update'])->middleware('permission:edit clients');
        Route::delete('/{client}/accounts/{account}', [ClientAccountController::class, 'destroy'])->middleware('permission:edit clients');
        Route::get('/{client}/accounts/{account}/status', [ClientAccountController::class, 'serviceStatus']);
    });

    // Plans
    Route::prefix('plans')->middleware('permission:view plans')->group(function () {
        Route::get('/', [PlanController::class, 'index']);
        Route::post('/', [PlanController::class, 'store'])->middleware('permission:create plans');
        Route::get('/{plan}', [PlanController::class, 'show']);
        Route::put('/{plan}', [PlanController::class, 'update'])->middleware('permission:edit plans');
        Route::delete('/{plan}', [PlanController::class, 'destroy'])->middleware('permission:delete plans');
        Route::get('/{plan}/clients', [PlanController::class, 'clients']);
        Route::post('/{plan}/assign', [PlanController::class, 'assign'])->middleware('permission:edit clients');
    });

    // RADIUS
    Route::prefix('radius')->middleware('permission:view radius')->group(function () {
        Route::get('/sessions', [RadiusController::class, 'sessions']);
        Route::post('/sync', [RadiusController::class, 'sync'])->middleware('permission:sync radius');
    });

    // Routers
    Route::prefix('routers')->middleware('permission:view routers')->group(function () {
        Route::get('/', [RouterController::class, 'index']);
        Route::post('/', [RouterController::class, 'store'])->middleware('permission:create routers');
        Route::get('/{router}', [RouterController::class, 'show']);
        Route::put('/{router}', [RouterController::class, 'update'])->middleware('permission:edit routers');
        Route::delete('/{router}', [RouterController::class, 'destroy'])->middleware('permission:delete routers');
        Route::post('/{router}/test-connection', [RouterController::class, 'testConnection']);
        Route::get('/{router}/resources', [RouterController::class, 'resources']);
        Route::get('/{router}/sessions', [RouterController::class, 'sessions']);
    });

    // Invoices
    Route::prefix('invoices')->middleware('permission:view invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::post('/', [InvoiceController::class, 'store'])->middleware('permission:create invoices');
        Route::post('/bulk-generate', [InvoiceController::class, 'bulkGenerate'])->middleware('permission:create invoices');
        Route::get('/{invoice}', [InvoiceController::class, 'show']);
        Route::put('/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:edit invoices');
        Route::delete('/{invoice}', [InvoiceController::class, 'destroy'])->middleware('permission:delete invoices');
    });

    // Payments
    Route::prefix('payments')->middleware('permission:view payments')->group(function () {
        Route::get('/', [PaymentController::class, 'index']);
        Route::post('/', [PaymentController::class, 'store'])->middleware('permission:create payments');
        Route::get('/summary', [PaymentController::class, 'summary']);
        Route::get('/{payment}/receipt', [PaymentController::class, 'receipt']);
        Route::get('/{payment}', [PaymentController::class, 'show']);
        Route::delete('/{payment}', [PaymentController::class, 'destroy'])->middleware('permission:delete payments');
    });

    // M-Pesa protected
    Route::prefix('mpesa')->group(function () {
        Route::post('/stk-push', [MpesaController::class, 'stkPush']);
    });

    // SMS
    Route::prefix('sms')->middleware('permission:view sms')->group(function () {
        Route::post('/send', [SmsController::class, 'send'])->middleware('permission:send sms');
        Route::post('/send-bulk', [SmsController::class, 'sendBulk'])->middleware('permission:send sms');
        Route::get('/logs', [SmsController::class, 'logs']);
        Route::get('/balance', [SmsController::class, 'balance']);
        Route::get('/templates', [SmsController::class, 'templates']);
    });

    // Tickets
    Route::prefix('tickets')->middleware('permission:view tickets')->group(function () {
        Route::get('/stats', [TicketController::class, 'stats']);
        Route::get('/', [TicketController::class, 'index']);
        Route::post('/', [TicketController::class, 'store'])->middleware('permission:create tickets');
        Route::get('/{ticket}', [TicketController::class, 'show']);
        Route::put('/{ticket}', [TicketController::class, 'update'])->middleware('permission:edit tickets');
        Route::post('/{ticket}/reply', [TicketController::class, 'reply'])->middleware('permission:edit tickets');
        Route::post('/{ticket}/assign', [TicketController::class, 'assign'])->middleware('permission:assign tickets');
        Route::post('/{ticket}/close', [TicketController::class, 'close'])->middleware('permission:close tickets');
        Route::post('/{ticket}/escalate', [TicketController::class, 'escalate']);
    });

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/traffic', [DashboardController::class, 'traffic']);
        Route::get('/top-downloaders', [DashboardController::class, 'topDownloaders']);
    });

    // Analytics
    Route::prefix('analytics')->group(function () {
        Route::get('/income', [DashboardController::class, 'incomeAnalytics']);
    });

    // Expenditures
    Route::prefix('expenditures')->middleware('permission:view finance')->group(function () {
        Route::get('/summary', [ExpenditureController::class, 'summary']);
        Route::get('/categories', [ExpenditureController::class, 'categories']);
        Route::get('/', [ExpenditureController::class, 'index']);
        Route::post('/', [ExpenditureController::class, 'store'])->middleware('permission:create expenditure');
        Route::get('/{expenditure}', [ExpenditureController::class, 'show']);
        Route::put('/{expenditure}', [ExpenditureController::class, 'update'])->middleware('permission:create expenditure');
        Route::delete('/{expenditure}', [ExpenditureController::class, 'destroy'])->middleware('permission:create expenditure');
    });

    // Commissions
    Route::prefix('commissions')->middleware('permission:view commissions')->group(function () {
        Route::get('/', [CommissionController::class, 'index']);
        Route::get('/summary', [CommissionController::class, 'summary']);
        Route::post('/{commission}/approve', [CommissionController::class, 'approve'])->middleware('permission:approve commissions');
        Route::post('/{commission}/pay', [CommissionController::class, 'pay']);
    });

    // Inventory
    Route::prefix('inventory')->middleware('permission:view inventory')->group(function () {
        Route::get('/low-stock', [InventoryController::class, 'lowStock']);
        Route::get('/assigned', [InventoryController::class, 'assigned']);
        Route::get('/summary', [InventoryController::class, 'summary']);
        Route::get('/', [InventoryController::class, 'index']);
        Route::post('/', [InventoryController::class, 'store'])->middleware('permission:create inventory');
        Route::get('/{inventoryItem}', [InventoryController::class, 'show']);
        Route::put('/{inventoryItem}', [InventoryController::class, 'update'])->middleware('permission:edit inventory');
        Route::delete('/{inventoryItem}', [InventoryController::class, 'destroy'])->middleware('permission:delete inventory');
        Route::post('/{inventoryItem}/assign', [InventoryController::class, 'assign']);
        Route::post('/{inventoryItem}/return', [InventoryController::class, 'return']);
    });

    // Settings
    Route::prefix('settings')->middleware('permission:view settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index']);
        Route::put('/', [SettingsController::class, 'update'])->middleware('permission:edit settings');
        Route::post('/test-sms', [SettingsController::class, 'testSms'])->middleware('permission:edit settings');
        Route::post('/upload-logo', [SettingsController::class, 'uploadLogo'])->middleware('permission:edit settings');
    });

    // Logs
    Route::prefix('logs')->middleware('permission:view logs')->group(function () {
        Route::get('/export', [LogController::class, 'export']);
        Route::get('/', [LogController::class, 'index']);
        Route::get('/{systemLog}', [LogController::class, 'show']);
    });

    // Reports
    Route::prefix('reports')->middleware('permission:view reports')->group(function () {
        Route::get('/income', [ReportController::class, 'income']);
        Route::get('/clients', [ReportController::class, 'clients']);
        Route::get('/invoices', [ReportController::class, 'invoices']);
        Route::get('/sms', [ReportController::class, 'sms']);
        Route::get('/network', [ReportController::class, 'network']);
        Route::get('/inventory', [ReportController::class, 'inventory']);
        Route::get('/expenditure', [ReportController::class, 'expenditure']);
        Route::get('/{type}/export', [ReportController::class, 'export'])->middleware('permission:export reports');
    });

});