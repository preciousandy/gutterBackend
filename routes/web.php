<?php

use App\Http\Controllers\Admin\AdminWebController;
use Illuminate\Support\Facades\Route;

// Root redirects/renders Admin Portal
Route::get('/', [AdminWebController::class, 'loginView']);

// Admin Web Application (Blade)
Route::prefix('admin')->group(function () {
    Route::get('/login', [AdminWebController::class, 'loginView'])->name('admin.login');
    Route::name('login')->get('/login-redirect', fn() => redirect()->route('admin.login'));
    Route::post('/login', [AdminWebController::class, 'login'])->name('admin.login.submit');
    Route::post('/logout', [AdminWebController::class, 'logout'])->name('admin.logout');

    // Protected Admin Routes
    Route::middleware('auth:admin')->group(function () {
        Route::get('/', [AdminWebController::class, 'dashboard'])->name('admin.dashboard');
        Route::get('/dashboard', [AdminWebController::class, 'dashboard']);

        // App & Button Controls & Fee Settings & VAS Telecom Settings
        Route::get('/app-controls', [AdminWebController::class, 'appControls'])->name('admin.app_controls');
        Route::post('/app-controls/fees/{key}', [AdminWebController::class, 'updateFeeSetting'])->name('admin.app_controls.fee');
        Route::post('/app-controls/fees-batch', [AdminWebController::class, 'updateFeesBatch'])->name('admin.app_controls.fees_batch');
        Route::post('/app-controls/vas-settings', [AdminWebController::class, 'updateVasSettings'])->name('admin.app_controls.vas_settings');
        Route::get('/app-controls/test-bigisub', [AdminWebController::class, 'testBigisub'])->name('admin.app_controls.test_bigisub');
        Route::post('/app-controls/{key}/toggle', [AdminWebController::class, 'toggleControl'])->name('admin.app_controls.toggle');

        // Savings & Wealth Products Controls
        Route::post('/app-controls/savings/{id}', [AdminWebController::class, 'updateSavingsPlan'])->name('admin.app_controls.savings.update');
        Route::post('/app-controls/savings/{id}/toggle', [AdminWebController::class, 'toggleSavingsPlan'])->name('admin.app_controls.savings.toggle');

        // Target Savings Categories Controls
        Route::post('/app-controls/target-categories', [AdminWebController::class, 'storeTargetCategory'])->name('admin.app_controls.target_categories.store');
        Route::post('/app-controls/target-categories/{id}', [AdminWebController::class, 'updateTargetCategory'])->name('admin.app_controls.target_categories.update');
        Route::post('/app-controls/target-categories/{id}/toggle', [AdminWebController::class, 'toggleTargetCategory'])->name('admin.app_controls.target_categories.toggle');
        Route::delete('/app-controls/target-categories/{id}', [AdminWebController::class, 'destroyTargetCategory'])->name('admin.app_controls.target_categories.destroy');

        // Transactions Ledger
        Route::get('/transactions', [AdminWebController::class, 'transactions'])->name('admin.transactions');

        // Customers & User Management
        Route::get('/users', [AdminWebController::class, 'users'])->name('admin.users');
        Route::put('/users/{id}', [AdminWebController::class, 'updateUser'])->name('admin.users.update');
        Route::put('/users/{id}/status', [AdminWebController::class, 'updateUserStatus'])->name('admin.users.status');
        Route::delete('/users/{id}', [AdminWebController::class, 'destroyUser'])->name('admin.users.destroy');

        // KYC Compliance
        Route::get('/kyc', [AdminWebController::class, 'kyc'])->name('admin.kyc');
        Route::post('/kyc/{id}/approve', [AdminWebController::class, 'approveKyc'])->name('admin.kyc.approve');
        Route::post('/kyc/{id}/reject', [AdminWebController::class, 'rejectKyc'])->name('admin.kyc.reject');

        // Customer Disputes & Complaint Tickets
        Route::get('/complaints', [AdminWebController::class, 'complaints'])->name('admin.complaints');
        Route::put('/complaints/{id}', [AdminWebController::class, 'updateComplaint'])->name('admin.complaints.update');

        // Staff & RBAC Roles
        Route::get('/staff', [AdminWebController::class, 'staff'])->name('admin.staff');
        Route::post('/staff', [AdminWebController::class, 'storeStaff'])->name('admin.staff.store');
    });
});
