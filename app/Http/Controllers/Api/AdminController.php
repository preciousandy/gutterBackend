<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AppControl;
use App\Models\Complaint;
use App\Models\KycVerification;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BigisubService;
use App\Services\FeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AdminController extends Controller
{
    /**
     * Resolve the authenticated admin user from request.
     */
    protected function getAdmin(Request $request): ?AdminUser
    {
        $bearer = $request->bearerToken();
        if (!$bearer) {
            return null;
        }

        $token = PersonalAccessToken::findToken($bearer);
        if (!$token || $token->tokenable_type !== AdminUser::class) {
            return null;
        }

        return $token->tokenable;
    }

    /**
     * Ensure request has authenticated admin.
     */
    protected function authorizeAdmin(Request $request): AdminUser
    {
        $admin = $this->getAdmin($request);
        if (!$admin || $admin->status !== 'active') {
            abort(response()->json(['message' => 'Unauthorized or account suspended.'], 401));
        }

        return $admin;
    }

    /**
     * Public endpoint: Mobile app configuration and feature switches.
     */
    public function appConfig(): JsonResponse
    {
        $controls = AppControl::all()->pluck('is_enabled', 'key');
        $fees = FeeService::getAllSettings();

        return response()->json([
            'status' => 'success',
            'features' => $controls,
            'fees' => $fees,
            'maintenance' => (bool) ($controls['system_maintenance_mode'] ?? false),
        ]);
    }

    /**
     * Admin Login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $admin = AdminUser::where('email', $request->email)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json([
                'message' => 'Invalid email or password credentials.',
            ], 422);
        }

        if ($admin->status !== 'active') {
            return response()->json([
                'message' => 'Your admin access has been deactivated. Contact Super Admin.',
            ], 403);
        }

        $admin->update(['last_login_at' => now()]);

        // Revoke older tokens for clean session
        $admin->tokens()->delete();
        $token = $admin->createToken('admin-access', ['admin'])->plainTextToken;

        return response()->json([
            'message' => 'Admin authentication successful.',
            'token' => $token,
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'status' => $admin->status,
                'last_login_at' => $admin->last_login_at,
            ],
            'permissions' => [
                'is_super_admin' => $admin->isSuperAdmin(),
                'can_manage_controls' => $admin->canManageAppControls(),
                'can_manage_kyc' => $admin->canManageKyc(),
                'can_manage_complaints' => $admin->canManageComplaints(),
                'can_view_financials' => $admin->canViewFinancials(),
            ],
        ]);
    }

    /**
     * Get Current Admin Profile & Permissions
     */
    public function me(Request $request): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);

        return response()->json([
            'admin' => $admin,
            'permissions' => [
                'is_super_admin' => $admin->isSuperAdmin(),
                'can_manage_controls' => $admin->canManageAppControls(),
                'can_manage_kyc' => $admin->canManageKyc(),
                'can_manage_complaints' => $admin->canManageComplaints(),
                'can_view_financials' => $admin->canViewFinancials(),
            ],
        ]);
    }

    /**
     * Admin Logout
     */
    public function logout(Request $request): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        $admin->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Dashboard Financial Metrics & Operational KPI Overview
     */
    public function metrics(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $totalUsers = User::count();
        $verifiedUsers = User::where('kyc_status', 'verified')->count();
        $pendingKyc = User::where('kyc_status', 'pending')
            ->orWhereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('kyc_verifications')
                    ->whereColumn('kyc_verifications.user_id', 'users.id')
                    ->where('kyc_verifications.status', 'pending');
            })->count();

        $totalWalletBalance = (float) User::sum('wallet_balance');

        $totalTransactionsCount = Transaction::count();
        $successfulTransactionsCount = Transaction::where('status', 'successful')->count();
        $failedTransactionsCount = Transaction::where('status', 'failed')->count();

        $totalVolume = (float) Transaction::where('status', 'successful')->sum('amount');
        $todayVolume = (float) Transaction::where('status', 'successful')
            ->whereDate('created_at', today())
            ->sum('amount');

        // Volume breakdown by category
        $billsVolume = (float) Transaction::where('status', 'successful')
            ->whereIn('type', ['airtime', 'data', 'bills', 'electricity', 'cable', 'betting'])
            ->sum('amount');

        $transfersVolume = (float) Transaction::where('status', 'successful')
            ->whereIn('type', ['transfer', 'withdrawal'])
            ->sum('amount');

        $cryptoVolume = (float) Transaction::where('status', 'successful')
            ->whereIn('type', ['crypto_deposit', 'crypto_swap', 'crypto'])
            ->sum('amount');

        $openComplaints = Complaint::where('status', 'open')->count();

        return response()->json([
            'metrics' => [
                'users' => [
                    'total' => $totalUsers,
                    'verified' => $verifiedUsers,
                    'pending_kyc' => $pendingKyc,
                ],
                'financials' => [
                    'total_wallet_pool' => $totalWalletBalance,
                    'total_volume' => $totalVolume,
                    'today_volume' => $todayVolume,
                    'bills_volume' => $billsVolume,
                    'transfers_volume' => $transfersVolume,
                    'crypto_volume' => $cryptoVolume,
                ],
                'operations' => [
                    'total_transactions' => $totalTransactionsCount,
                    'successful_transactions' => $successfulTransactionsCount,
                    'failed_transactions' => $failedTransactionsCount,
                    'success_rate' => $totalTransactionsCount > 0 ? round(($successfulTransactionsCount / $totalTransactionsCount) * 100, 1) : 100,
                    'open_complaints' => $openComplaints,
                ],
            ],
        ]);
    }

    /**
     * List Transactions with Filters & Search
     */
    public function transactions(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = Transaction::with('user:id,first_name,last_name,email,phone')
            ->orderByDesc('id');

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $transactions = $query->paginate($request->integer('per_page', 15));

        return response()->json($transactions);
    }

    /**
     * List Users with Filters
     */
    public function users(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = User::select([
            'id', 'first_name', 'last_name', 'email', 'phone',
            'wallet_balance', 'tier', 'kyc_status', 'status',
            'status_reason', 'status_updated_by', 'status_updated_at',
            'last_login_at', 'bank_name', 'account_number', 'created_at'
        ])->orderByDesc('id');

        // Quick KPI filter
        $filter = $request->query('filter');
        if ($filter === 'active') {
            $query->where('status', 'active');
        } elseif ($filter === 'today') {
            $query->whereDate('last_login_at', today());
        } elseif ($filter === 'pending_kyc') {
            $query->where('kyc_status', 'pending');
        } elseif ($filter === 'frozen') {
            $query->where('status', 'frozen');
        } elseif ($filter === 'suspended') {
            $query->whereIn('status', ['suspended', 'blocked']);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('kyc_status') && $request->kyc_status !== 'all') {
            $query->where('kyc_status', $request->kyc_status);
        }

        if ($request->filled('tier') && $request->tier !== 'all') {
            $query->where('tier', $request->tier);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'kpis' => [
                'total_users' => User::count(),
                'active_users' => User::where('status', 'active')->count(),
                'logged_in_today' => User::whereDate('last_login_at', today())->count(),
                'pending_kyc' => User::where('kyc_status', 'pending')->count(),
                'frozen_users' => User::where('status', 'frozen')->count(),
                'suspended_users' => User::whereIn('status', ['suspended', 'blocked'])->count(),
            ],
            'users' => $users,
        ]);
    }

    /**
     * Update User Profile (Tier, KYC Status, Wallet Balance, Details)
     */
    public function updateUser(Request $request, int $id): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        if (!$admin->canManageKyc() && !$admin->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized action for your role.'], 403);
        }

        $user = User::findOrFail($id);

        $validated = $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'tier' => 'nullable|integer|in:1,2,3',
            'kyc_status' => 'nullable|string|in:unverified,pending,verified,rejected',
            'status' => 'nullable|string|in:active,frozen,suspended,blocked',
            'status_reason' => 'nullable|string|max:500',
            'wallet_balance' => 'nullable|numeric|min:0',
        ]);

        if (isset($validated['first_name'])) {
            $user->first_name = $validated['first_name'];
        }
        if (isset($validated['last_name'])) {
            $user->last_name = $validated['last_name'];
        }
        if (isset($validated['phone'])) {
            $user->phone = $validated['phone'];
        }
        if (isset($validated['tier'])) {
            $user->tier = $validated['tier'];
        }
        if (isset($validated['kyc_status'])) {
            $user->kyc_status = $validated['kyc_status'];
        }
        if (isset($validated['wallet_balance']) && $admin->isSuperAdmin()) {
            $user->wallet_balance = $validated['wallet_balance'];
        }

        if (isset($validated['status']) && $validated['status'] !== $user->status) {
            $user->status = $validated['status'];
            $user->status_reason = $validated['status_reason'] ?? $user->status_reason;
            $user->status_updated_by = $admin->name;
            $user->status_updated_at = now();

            if (in_array($user->status, ['suspended', 'blocked'], true)) {
                $user->tokens()->delete();
            }
        }

        $user->save();

        return response()->json([
            'message' => 'User profile updated successfully.',
            'user' => $user,
        ]);
    }

    /**
     * Update User Status (Freeze, Unfreeze, Suspend, Unsuspend, Block)
     */
    public function updateUserStatus(Request $request, int $id): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        if (!$admin->canManageKyc() && !$admin->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized action for your role.'], 403);
        }

        $validated = $request->validate([
            'action' => 'required|string|in:active,freeze,unfreeze,suspend,unsuspend,block',
            'reason' => 'nullable|string|max:500',
        ]);

        $user = User::findOrFail($id);
        $action = $validated['action'];
        $reason = $validated['reason'] ?? null;

        $newStatus = match ($action) {
            'freeze' => 'frozen',
            'unfreeze', 'unsuspend', 'active' => 'active',
            'suspend' => 'suspended',
            'block' => 'blocked',
            default => 'active',
        };

        $user->update([
            'status' => $newStatus,
            'status_reason' => $reason,
            'status_updated_by' => $admin->name,
            'status_updated_at' => now(),
        ]);

        if (in_array($newStatus, ['suspended', 'blocked'], true)) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => "User status successfully updated to {$newStatus}.",
            'user' => $user,
        ]);
    }

    /**
     * Soft-Delete User Account (Fintech Standard)
     */
    public function destroyUser(Request $request, int $id): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        if (!$admin->isSuperAdmin()) {
            return response()->json(['message' => 'Only Super Administrators can delete user accounts.'], 403);
        }

        $user = User::findOrFail($id);
        $user->tokens()->delete();

        $user->update([
            'status' => 'suspended',
            'status_reason' => 'Account deleted by Admin: ' . $admin->name,
            'status_updated_by' => $admin->name,
            'status_updated_at' => now(),
        ]);

        $user->delete();

        return response()->json([
            'message' => "User #{$id} has been safely deleted.",
        ]);
    }

    /**
     * List App Feature Switches & Controls
     */
    public function appControls(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $controls = AppControl::orderBy('category')->orderBy('id')->get();

        return response()->json([
            'controls' => $controls,
        ]);
    }

    /**
     * Update App Feature Switch
     */
    public function updateAppControl(Request $request, string $key): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        if (!$admin->canManageAppControls()) {
            return response()->json(['message' => 'Only Super Admin can modify app controls.'], 403);
        }

        $control = AppControl::where('key', $key)->firstOrFail();

        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        $control->update([
            'is_enabled' => $validated['is_enabled'],
            'updated_by' => $admin->name,
        ]);

        return response()->json([
            'message' => "App control '{$control->label}' updated successfully.",
            'control' => $control,
        ]);
    }

    /**
     * Get all fee and charge settings across the 8 services.
     */
    public function feeSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        return response()->json([
            'status' => 'success',
            'data' => FeeService::getAllSettings(),
        ]);
    }

    /**
     * Update flat and percentage fee setting for a specific service.
     */
    public function updateFeeSetting(Request $request, string $key): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        if (!$admin->canManageAppControls()) {
            return response()->json(['message' => 'Unauthorized action for your role.'], 403);
        }

        $validated = $request->validate([
            'flat_fee' => 'required|numeric|min:0',
            'percent_fee' => 'required|numeric|min:0|max:100',
            'is_enabled' => 'nullable|boolean',
        ]);

        $control = FeeService::updateSetting(
            $key,
            (float) $validated['flat_fee'],
            (float) $validated['percent_fee'],
            $request->has('is_enabled') ? $request->boolean('is_enabled') : null,
            $admin->name
        );

        return response()->json([
            'message' => "Fee setting for '{$control->label}' updated successfully.",
            'data' => FeeService::getSetting($key),
        ]);
    }

    /**
     * Get VAS & Telecom API Settings
     */
    public function vasSettings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $provider = (string) AppControl::getSetting('vas_provider', config('services.bigisub.default_provider', 'bigisub'));
        $apiKey = (string) AppControl::getSetting('bigisub_api_key', config('services.bigisub.api_key', 'f89dca9d3c0814c375320ddfda09d91ce0bb10e5'));
        $pin = (string) AppControl::getSetting('bigisub_pin', config('services.bigisub.pin', ''));
        $allowFallback = AppControl::isFeatureEnabled('vas_allow_fallback', false);

        $bigisub = app(BigisubService::class);
        $telemetry = $bigisub->getWalletBalance();

        return response()->json([
            'provider' => $provider,
            'bigisub_api_key' => $apiKey,
            'bigisub_pin' => $pin ? '****' : '',
            'allow_fallback' => $allowFallback,
            'telemetry' => $telemetry,
        ]);
    }

    /**
     * Update VAS & Telecom API Settings
     */
    public function updateVasSettings(Request $request): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        if (!$admin->canManageAppControls()) {
            return response()->json(['message' => 'Only Super Admin can modify API settings.'], 403);
        }

        $validated = $request->validate([
            'vas_provider' => 'required|in:bigisub,vtpass',
            'bigisub_api_key' => 'required|string',
            'bigisub_pin' => 'nullable|string|max:10',
            'allow_fallback' => 'nullable|boolean',
        ]);

        AppControl::setSetting('vas_provider', $validated['vas_provider'], [
            'label' => 'Primary Telecom & VAS Gateway',
            'category' => 'vas_settings',
            'description' => 'Active provider used to vend Airtime and Mobile Data.',
            'updated_by' => $admin->name,
        ]);

        AppControl::setSetting('bigisub_api_key', trim($validated['bigisub_api_key']), [
            'label' => 'Bigisub API Token / Key',
            'category' => 'vas_settings',
            'description' => 'Permanent partner API token from bigisub.ng Developer settings.',
            'updated_by' => $admin->name,
        ]);

        if (isset($validated['bigisub_pin'])) {
            AppControl::setSetting('bigisub_pin', trim($validated['bigisub_pin']), [
                'label' => 'Bigisub 4-Digit Account PIN',
                'category' => 'vas_settings',
                'description' => '4-digit transaction PIN configured on your Bigisub account.',
                'updated_by' => $admin->name,
            ]);
        }

        if ($request->has('allow_fallback')) {
            $fallbackCtrl = AppControl::firstOrNew(['key' => 'vas_allow_fallback']);
            $fallbackCtrl->label = 'Allow Fallback to VTpass';
            $fallbackCtrl->category = 'vas_settings';
            $fallbackCtrl->is_enabled = $request->boolean('allow_fallback');
            $fallbackCtrl->updated_by = $admin->name;
            $fallbackCtrl->save();
        }

        $bigisub = new BigisubService();
        $telemetry = $bigisub->getWalletBalance();

        return response()->json([
            'message' => 'VAS API credentials updated successfully.',
            'provider' => $validated['vas_provider'],
            'telemetry' => $telemetry,
        ]);
    }

    /**
     * Test Bigisub Live Connection
     */
    public function testBigisub(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $bigisub = app(BigisubService::class);
        $result = $bigisub->getWalletBalance();

        return response()->json($result);
    }

    /**
     * List Complaints & Support Tickets
     */
    public function complaints(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = Complaint::orderByDesc('id');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        $complaints = $query->paginate($request->integer('per_page', 15));

        return response()->json($complaints);
    }

    /**
     * Update Complaint Status & Notes
     */
    public function updateComplaint(Request $request, int $id): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        if (!$admin->canManageComplaints()) {
            return response()->json(['message' => 'Unauthorized action for your role.'], 403);
        }

        $complaint = Complaint::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:open,in_progress,resolved,rejected',
            'admin_notes' => 'nullable|string',
        ]);

        $complaint->update([
            'status' => $validated['status'],
            'admin_notes' => $validated['admin_notes'] ?? $complaint->admin_notes,
            'assigned_to' => $admin->name,
            'resolved_at' => $validated['status'] === 'resolved' ? now() : null,
        ]);

        return response()->json([
            'message' => "Complaint ticket {$complaint->ticket_id} updated.",
            'complaint' => $complaint,
        ]);
    }

    /**
     * List Admin Staff
     */
    public function staff(Request $request): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);

        $staff = AdminUser::select(['id', 'name', 'email', 'role', 'status', 'last_login_at', 'created_at'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'staff' => $staff,
        ]);
    }

    /**
     * Create Admin Staff
     */
    public function storeStaff(Request $request): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        if (!$admin->isSuperAdmin()) {
            return response()->json(['message' => 'Only Super Admin can invite admin staff.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:admin_users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:super_admin,compliance_officer,support_lead,financial_auditor',
        ]);

        $newAdmin = AdminUser::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Admin staff created successfully.',
            'admin' => $newAdmin,
        ], 201);
    }
}
