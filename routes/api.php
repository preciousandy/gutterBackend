<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BeneficiaryController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\CryptoController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\LimitController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PalmPayWebhookController;
use App\Http\Controllers\Api\PinController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Prefixed with /api/v1 (configured in bootstrap/app.php)
|
*/

// Public Authentication routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:register');
});

// Public Webhooks (Quidax & PalmPay)
Route::post('/crypto/quidax/webhook', [CryptoController::class, 'quidaxWebhook']);
Route::post('/webhooks/palmpay', [PalmPayWebhookController::class, 'handle']);


// Authenticated routes
Route::middleware(['auth:sanctum', 'throttle:api', 'account.active'])->group(function () {
    // Current authenticated user & Dashboard
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/limits', [LimitController::class, 'index']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

    // Transfers & Banking (PalmPay & Gutter-to-Gutter)
    Route::get('/banks', [TransferController::class, 'banks']);
    Route::post('/transfers/verify-account', [TransferController::class, 'verifyAccount']);
    Route::post('/transfers/resolve-gutter-user', [TransferController::class, 'resolveGutterUser']);
    Route::post('/transfers', [TransferController::class, 'store'])
        ->middleware(['kyc.verified', 'account.not_frozen']);

    // Transactions History & Details
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);

    // Beneficiaries
    Route::get('/beneficiaries', [BeneficiaryController::class, 'index']);
    Route::post('/beneficiaries', [BeneficiaryController::class, 'store'])
        ->middleware(['kyc.verified', 'account.not_frozen']);
    Route::delete('/beneficiaries/{id}', [BeneficiaryController::class, 'destroy'])
        ->middleware(['account.not_frozen']);

    // Virtual USD Cards
    Route::get('/cards', [\App\Http\Controllers\Api\CardController::class, 'index']);
    Route::get('/cards/pricing', [\App\Http\Controllers\Api\CardController::class, 'pricing']);
    Route::post('/cards', [\App\Http\Controllers\Api\CardController::class, 'store'])
        ->middleware(['account.not_frozen']);
    Route::get('/cards/{id}/details', [\App\Http\Controllers\Api\CardController::class, 'details']);
    Route::post('/cards/{id}/freeze', [\App\Http\Controllers\Api\CardController::class, 'freeze']);
    Route::post('/cards/{id}/unfreeze', [\App\Http\Controllers\Api\CardController::class, 'unfreeze']);

    // KYC Verification (Identity & Face Check)
    Route::prefix('kyc')->group(function () {
        Route::post('/submit', [KycController::class, 'submit'])
            ->middleware('throttle:kyc-submit');
        Route::post('/face-check', [KycController::class, 'faceCheck'])
            ->middleware('throttle:kyc-submit');
        Route::get('/status', [KycController::class, 'status']);
    });

    // Transaction PIN
    Route::post('/pin', [PinController::class, 'store']);
    Route::post('/pin/change', [PinController::class, 'change'])
        ->middleware('throttle:pin-change');
    Route::post('/pin/verify', [PinController::class, 'verify'])
        ->middleware('throttle:10,1');

    // Savings & Wealth Products (G-Vault, Target, SafeBox, Spend & Save)
    Route::prefix('savings')->group(function () {
        Route::get('/plans', [\App\Http\Controllers\Api\SavingsController::class, 'plans']);
        Route::get('/target-categories', [\App\Http\Controllers\Api\SavingsController::class, 'targetCategories']);
        Route::get('/dashboard', [\App\Http\Controllers\Api\SavingsController::class, 'dashboard']);
        Route::get('/{id}/break-preview', [\App\Http\Controllers\Api\SavingsController::class, 'breakPreview']);
        Route::post('/break-preview', [\App\Http\Controllers\Api\SavingsController::class, 'breakPreview']);
        Route::post('/deposit', [\App\Http\Controllers\Api\SavingsController::class, 'deposit'])
            ->middleware(['throttle:savings-deposit', 'account.not_frozen']);
        Route::post('/withdraw', [\App\Http\Controllers\Api\SavingsController::class, 'withdraw'])
            ->middleware(['throttle:savings-withdraw', 'account.not_frozen']);
        Route::post('/break', [\App\Http\Controllers\Api\SavingsController::class, 'breakSavings'])
            ->middleware(['throttle:savings-withdraw', 'account.not_frozen']);
    });

    // Email OTP onboarding
    Route::prefix('auth/email')->group(function () {
        Route::post('/otp', [AuthController::class, 'sendEmailOtp'])
            ->middleware('throttle:otp-send');
        Route::post('/verify', [AuthController::class, 'verifyEmailOtp'])
            ->middleware('throttle:otp-verify');
        Route::post('/change', [AuthController::class, 'changeEmail'])
            ->middleware('throttle:6,1');
    });

    // Crypto Platform (Deposit, Addresses, Market, Auto-convert, Buy/Sell Swaps)
    Route::prefix('crypto')->group(function () {
        Route::get('/market', [CryptoController::class, 'market']);
        Route::get('/balances', [CryptoController::class, 'balances']);
        Route::post('/auto-convert', [CryptoController::class, 'toggleAutoConvert']);
        Route::get('/addresses', [CryptoController::class, 'getAddresses']);
        Route::post('/quote', [CryptoController::class, 'quote']);
        Route::post('/orders', [CryptoController::class, 'placeOrder'])
            ->middleware('account.not_frozen');
        Route::post('/receive/simulate', [CryptoController::class, 'simulateDeposit']);
        Route::get('/send/fee', [CryptoController::class, 'sendFee']);
        Route::post('/send', [CryptoController::class, 'send'])
            ->middleware('account.not_frozen');
    });

    // Bills & Utilities (Airtime, Mobile Data & Electricity via Bigisub / VTpass)
    Route::get('/bills/plans', [BillController::class, 'plans']);
    Route::get('/bills/electricity/providers', [BillController::class, 'electricityProviders']);
    Route::post('/bills/electricity/verify', [BillController::class, 'verifyMeter']);
    Route::get('/bills/betting/billers', [BillController::class, 'bettingBillers']);
    Route::post('/bills/betting/verify', [BillController::class, 'verifyBetting']);
    Route::get('/bills/cable/plans', [BillController::class, 'cablePlans']);
    Route::post('/bills/cable/verify', [BillController::class, 'verifyCable']);
    Route::post('/bills/purchase', [BillController::class, 'purchase'])
        ->middleware(['throttle:bill-payment', 'account.not_frozen']);
    Route::post('/bills/requery/{id}', [BillController::class, 'requery']);

    // Support & User Complaints
    Route::post('/support/complaint', [SupportController::class, 'storeComplaint']);
});

// Real-time App Configuration for Mobile Client
Route::get('/app/config', [\App\Http\Controllers\Api\AdminController::class, 'appConfig']);

// Admin Portal APIs
Route::prefix('admin')->group(function () {
    Route::post('/login', [\App\Http\Controllers\Api\AdminController::class, 'login']);
    Route::get('/me', [\App\Http\Controllers\Api\AdminController::class, 'me']);
    Route::post('/logout', [\App\Http\Controllers\Api\AdminController::class, 'logout']);

    // Financial Metrics & Dashboard
    Route::get('/metrics', [\App\Http\Controllers\Api\AdminController::class, 'metrics']);
    Route::get('/transactions', [\App\Http\Controllers\Api\AdminController::class, 'transactions']);

    // User & KYC Management
    Route::get('/users', [\App\Http\Controllers\Api\AdminController::class, 'users']);
    Route::put('/users/{id}', [\App\Http\Controllers\Api\AdminController::class, 'updateUser']);
    Route::put('/users/{id}/status', [\App\Http\Controllers\Api\AdminController::class, 'updateUserStatus']);
    Route::delete('/users/{id}', [\App\Http\Controllers\Api\AdminController::class, 'destroyUser']);

    // Mobile App Real-Time Feature Switches & Button Controls
    Route::get('/app-controls', [\App\Http\Controllers\Api\AdminController::class, 'appControls']);
    Route::put('/app-controls/{key}', [\App\Http\Controllers\Api\AdminController::class, 'updateAppControl']);
    Route::get('/fee-settings', [\App\Http\Controllers\Api\AdminController::class, 'feeSettings']);
    Route::put('/fee-settings/{key}', [\App\Http\Controllers\Api\AdminController::class, 'updateFeeSetting']);
    Route::get('/vas-settings', [\App\Http\Controllers\Api\AdminController::class, 'vasSettings']);
    Route::put('/vas-settings', [\App\Http\Controllers\Api\AdminController::class, 'updateVasSettings']);
    Route::post('/vas-settings/test-bigisub', [\App\Http\Controllers\Api\AdminController::class, 'testBigisub']);

    // Complaints & Support Disputes
    Route::get('/complaints', [\App\Http\Controllers\Api\AdminController::class, 'complaints']);
    Route::put('/complaints/{id}', [\App\Http\Controllers\Api\AdminController::class, 'updateComplaint']);

    // Staff RBAC Management
    Route::get('/staff', [\App\Http\Controllers\Api\AdminController::class, 'staff']);
    Route::post('/staff', [\App\Http\Controllers\Api\AdminController::class, 'storeStaff']);
});



