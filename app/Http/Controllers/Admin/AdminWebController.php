<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AppControl;
use App\Models\Complaint;
use App\Models\CryptoFeeSetting;
use App\Models\CryptoRefund;
use App\Models\CryptoTransaction;
use App\Models\KycVerification;
use App\Models\SavingsPlan;
use App\Models\SavingsTargetCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BigisubService;
use App\Services\CryptoService;
use App\Services\FeeService;
use App\Services\QuidaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AdminWebController extends Controller
{
    /**
     * Get the active admin or demo simulated admin.
     */
    protected function getActiveAdmin(Request $request): AdminUser
    {
        /** @var AdminUser|null $admin */
        $admin = Auth::guard('admin')->user();

        if (!$admin) {
            // Default fallback if not logged in (redirect to login)
            abort(redirect()->route('admin.login'));
        }

        // Support role preview simulation for Super Admins
        if ($admin->isSuperAdmin() && $request->has('preview_role')) {
            $previewRole = $request->get('preview_role');
            if (in_array($previewRole, ['super_admin', 'compliance_officer', 'support_lead', 'financial_auditor'])) {
                session(['admin_preview_role' => $previewRole]);
            }
        }

        if (session()->has('admin_preview_role') && $admin->isSuperAdmin()) {
            $admin->role = session('admin_preview_role');
        }

        return $admin;
    }

    /**
     * Admin Login Page
     */
    public function loginView(): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    /**
     * Handle Admin Login
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $admin = AdminUser::where('email', $credentials['email'])->first();

        if (!$admin || !Hash::check($credentials['password'], $admin->password)) {
            return back()->withInput()->with('error', 'Invalid admin email or password credentials.');
        }

        if ($admin->status !== 'active') {
            return back()->withInput()->with('error', 'Your administrator access is currently suspended.');
        }

        Auth::guard('admin')->login($admin, $request->boolean('remember'));
        $admin->update(['last_login_at' => now()]);
        session()->forget('admin_preview_role');

        return redirect()->intended(route('admin.dashboard'))->with('success', "Welcome back, {$admin->name}!");
    }

    /**
     * Admin Logout
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'You have been safely signed out.');
    }

    /**
     * Financial Overview Dashboard
     */
    public function dashboard(Request $request): View
    {
        $admin = $this->getActiveAdmin($request);

        // Core Financial Metrics
        $totalUsers = User::count();
        $verifiedUsers = User::where('kyc_status', 'verified')->count();
        $pendingKyc = User::where('kyc_status', 'pending')->count();
        $activeUsers = User::where('status', 'active')->count();
        $loggedInToday = User::whereDate('last_login_at', today())->count();
        $frozenUsers = User::where('status', 'frozen')->count();
        $suspendedUsers = User::whereIn('status', ['suspended', 'blocked'])->count();

        $totalWalletPool = (float) User::sum('wallet_balance');
        $totalVolume = (float) Transaction::where('status', 'successful')->sum('amount');
        $todayVolume = (float) Transaction::where('status', 'successful')
            ->whereDate('created_at', today())
            ->sum('amount');

        $billsVolume = (float) Transaction::where('status', 'successful')
            ->whereIn('type', ['airtime', 'data', 'bills', 'electricity', 'cable', 'betting'])
            ->sum('amount');

        $transfersVolume = (float) Transaction::where('status', 'successful')
            ->whereIn('type', ['transfer', 'withdrawal'])
            ->sum('amount');

        $cryptoVolume = (float) Transaction::where('status', 'successful')
            ->whereIn('type', ['crypto_deposit', 'crypto_swap', 'crypto'])
            ->sum('amount');

        $totalTransactionsCount = Transaction::count();
        $successfulTransactionsCount = Transaction::where('status', 'successful')->count();
        $failedTransactionsCount = Transaction::where('status', 'failed')->count();
        $successRate = $totalTransactionsCount > 0 ? round(($successfulTransactionsCount / $totalTransactionsCount) * 100, 1) : 100;

        $openComplaints = Complaint::where('status', 'open')->count();

        $recentTransactions = Transaction::with('user')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $vasProvider = (string) AppControl::getSetting('vas_provider', config('services.bigisub.default_provider', 'bigisub'));
        $cryptoMetrics = app(CryptoService::class)->getAdminCryptoMetrics();

        return view('admin.dashboard', compact(
            'admin',
            'totalUsers',
            'verifiedUsers',
            'pendingKyc',
            'activeUsers',
            'loggedInToday',
            'frozenUsers',
            'suspendedUsers',
            'totalWalletPool',
            'totalVolume',
            'todayVolume',
            'billsVolume',
            'transfersVolume',
            'cryptoVolume',
            'totalTransactionsCount',
            'successfulTransactionsCount',
            'failedTransactionsCount',
            'successRate',
            'openComplaints',
            'recentTransactions',
            'vasProvider',
            'cryptoMetrics'
        ));
    }

    /**
     * Mobile App & Button Controls + VAS API Configuration
     */
    public function appControls(Request $request): View
    {
        $admin = $this->getActiveAdmin($request);
        $controls = AppControl::where('category', '!=', 'vas_settings')->orderBy('category')->orderBy('id')->get();
        $openComplaints = Complaint::where('status', 'open')->count();

        // Bigisub & VAS Configuration
        $vasProvider = (string) AppControl::getSetting('vas_provider', config('services.bigisub.default_provider', 'bigisub'));
        $bigisubApiKey = (string) AppControl::getSetting('bigisub_api_key', config('services.bigisub.api_key', 'f89dca9d3c0814c375320ddfda09d91ce0bb10e5'));
        $bigisubPin = (string) AppControl::getSetting('bigisub_pin', config('services.bigisub.pin', ''));
        $allowFallback = AppControl::isFeatureEnabled('vas_allow_fallback', false);

        // Fetch live wallet balance
        $bigisubService = app(BigisubService::class);
        $bigisubStatus = $bigisubService->getWalletBalance();

        // Fee & Control Settings across all 8 core services
        $feeSettings = FeeService::getAllSettings();

        // Savings & Wealth Products
        $savingsPlans = SavingsPlan::orderBy('order_index')->get();
        $targetCategories = SavingsTargetCategory::orderBy('order_index')->orderBy('id')->get();

        return view('admin.app-controls', compact(
            'admin',
            'controls',
            'feeSettings',
            'savingsPlans',
            'targetCategories',
            'openComplaints',
            'vasProvider',
            'bigisubApiKey',
            'bigisubPin',
            'allowFallback',
            'bigisubStatus'
        ));
    }

    /**
     * Update a Savings Plan (percentage, minimum deposit, name, description, lock period)
     */
    public function updateSavingsPlan(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            return response()->json(['message' => 'Unauthorized action for your role.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'tagline' => 'nullable|string|max:255',
            'annual_rate' => 'required|numeric|min:0|max:100',
            'early_break_fee_percentage' => 'nullable|numeric|min:0|max:100',
            'min_deposit' => 'required|numeric|min:0',
            'lock_period' => 'nullable|string|max:50',
            'is_enabled' => 'nullable|boolean',
            'allow_withdrawals' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        $plan = SavingsPlan::findOrFail($id);
        $plan->update([
            'name' => $validated['name'],
            'tagline' => $validated['tagline'] ?? $plan->tagline,
            'annual_rate' => (float) $validated['annual_rate'],
            'early_break_fee_percentage' => $request->has('early_break_fee_percentage') ? (float) $validated['early_break_fee_percentage'] : ($plan->early_break_fee_percentage ?? 2.50),
            'min_deposit' => (float) $validated['min_deposit'],
            'lock_period' => $validated['lock_period'] ?? $plan->lock_period,
            'is_enabled' => $request->has('is_enabled') ? (bool) $request->input('is_enabled') : $plan->is_enabled,
            'allow_withdrawals' => $request->has('allow_withdrawals') ? (bool) $request->input('allow_withdrawals') : $plan->allow_withdrawals,
            'description' => $validated['description'] ?? $plan->description,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "{$plan->name} updated successfully.",
                'data' => $plan,
            ]);
        }

        return back()->with('success', "{$plan->name} updated successfully.");
    }

    /**
     * Toggle a Savings Plan enabled/disabled status
     */
    public function toggleSavingsPlan(Request $request, int $id): JsonResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            return response()->json(['message' => 'Unauthorized action for your role.'], 403);
        }

        $plan = SavingsPlan::findOrFail($id);
        $plan->is_enabled = !$plan->is_enabled;
        $plan->save();

        return response()->json([
            'success' => true,
            'is_enabled' => $plan->is_enabled,
            'message' => "{$plan->name} is now " . ($plan->is_enabled ? 'ENABLED' : 'DISABLED') . '.',
        ]);
    }

    /**
     * Create a new Target Savings Category
     */
    public function storeTargetCategory(Request $request): JsonResponse|RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            return response()->json(['message' => 'Unauthorized action for your role.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:30',
            'description' => 'nullable|string|max:255',
            'is_enabled' => 'nullable|boolean',
        ]);

        $maxOrder = SavingsTargetCategory::max('order_index') ?? 0;

        $category = SavingsTargetCategory::create([
            'name' => $validated['name'],
            'icon' => !empty($validated['icon']) ? $validated['icon'] : 'flag-outline',
            'color' => !empty($validated['color']) ? $validated['color'] : '#7C3AED',
            'description' => $validated['description'] ?? null,
            'is_enabled' => $request->has('is_enabled') ? (bool) $request->input('is_enabled') : true,
            'order_index' => $maxOrder + 1,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Target category '{$category->name}' created successfully.",
                'data' => $category,
            ]);
        }

        return back()->with('success', "Target category '{$category->name}' created successfully.");
    }

    /**
     * Update an existing Target Savings Category
     */
    public function updateTargetCategory(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            return response()->json(['message' => 'Unauthorized action for your role.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:30',
            'description' => 'nullable|string|max:255',
            'is_enabled' => 'nullable|boolean',
        ]);

        $category = SavingsTargetCategory::findOrFail($id);
        $category->update([
            'name' => $validated['name'],
            'icon' => !empty($validated['icon']) ? $validated['icon'] : $category->icon,
            'color' => !empty($validated['color']) ? $validated['color'] : $category->color,
            'description' => $validated['description'] ?? $category->description,
            'is_enabled' => $request->has('is_enabled') ? (bool) $request->input('is_enabled') : $category->is_enabled,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Target category '{$category->name}' updated successfully.",
                'data' => $category,
            ]);
        }

        return back()->with('success', "Target category '{$category->name}' updated successfully.");
    }

    /**
     * Toggle a Target Savings Category enabled/disabled
     */
    public function toggleTargetCategory(Request $request, int $id): JsonResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            return response()->json(['message' => 'Unauthorized action for your role.'], 403);
        }

        $category = SavingsTargetCategory::findOrFail($id);
        $category->is_enabled = !$category->is_enabled;
        $category->save();

        return response()->json([
            'success' => true,
            'is_enabled' => $category->is_enabled,
            'message' => "Category '{$category->name}' is now " . ($category->is_enabled ? 'ENABLED' : 'DISABLED') . '.',
        ]);
    }

    /**
     * Delete a Target Savings Category
     */
    public function destroyTargetCategory(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            return response()->json(['message' => 'Unauthorized action for your role.'], 403);
        }

        $category = SavingsTargetCategory::findOrFail($id);
        $name = $category->name;
        $category->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Target category '{$name}' was deleted successfully.",
            ]);
        }

        return back()->with('success', "Target category '{$name}' was deleted successfully.");
    }

    /**
     * Update Telecom / VAS API Settings (Bigisub & VTpass)
     */
    public function updateVasSettings(Request $request): RedirectResponse|JsonResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Unauthorized action for your role.'], 403);
            }
            return back()->with('error', 'Only Super Admin can update API credentials.');
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

        $fallback = $request->boolean('allow_fallback');
        $fallbackCtrl = AppControl::firstOrNew(['key' => 'vas_allow_fallback']);
        $fallbackCtrl->label = 'Allow Fallback to VTpass';
        $fallbackCtrl->category = 'vas_settings';
        $fallbackCtrl->description = 'Automatically retry on VTpass if Bigisub returns network failure.';
        $fallbackCtrl->is_enabled = $fallback;
        $fallbackCtrl->updated_by = $admin->name;
        $fallbackCtrl->save();

        // Test connection live with updated credentials
        $svc = new BigisubService();
        $test = $svc->getWalletBalance();

        $msg = "VAS API Settings successfully updated! Active provider: " . ucfirst($validated['vas_provider']) . ".";
        if ($test['success']) {
            $msg .= " Verified connection to Bigisub: Float balance ₦" . number_format($test['balance'], 2) . " ({$test['account_name']}).";
        } else {
            $msg .= " Note: Bigisub returned: {$test['message']}";
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
                'bigisub' => $test,
            ]);
        }

        return back()->with('success', $msg);
    }

    /**
     * Test Bigisub Live Connection (AJAX)
     */
    public function testBigisub(Request $request): JsonResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $svc = app(BigisubService::class);
        $result = $svc->getWalletBalance();

        return response()->json($result);
    }

    /**
     * Toggle App Control (AJAX / POST)
     */
    public function toggleControl(Request $request, string $key): JsonResponse|RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Unauthorized action for your role.'], 403);
            }
            return back()->with('error', 'Only Super Admin can toggle mobile application controls.');
        }

        $control = AppControl::where('key', $key)->firstOrFail();
        $newState = $request->has('enabled') ? $request->boolean('enabled') : !$control->is_enabled;

        $control->update([
            'is_enabled' => $newState,
            'updated_by' => $admin->name,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Control '{$control->label}' updated.",
                'is_enabled' => $control->is_enabled,
                'updated_by' => $control->updated_by,
            ]);
        }

        return back()->with('success', "Control '{$control->label}' set to " . ($control->is_enabled ? 'ENABLED' : 'DISABLED') . '.');
    }

    /**
     * Update Fee Setting for a specific service (AJAX / POST)
     */
    public function updateFeeSetting(Request $request, string $key): JsonResponse|RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Unauthorized action for your role.'], 403);
            }
            return back()->with('error', 'Only Super Admin can update service fee configurations.');
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

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Fee configuration for '{$control->label}' saved successfully.",
                'data' => FeeService::getSetting($key),
            ]);
        }

        return back()->with('success', "Fee configuration for '{$control->label}' saved successfully.");
    }

    /**
     * Batch update all service fee settings (POST)
     */
    public function updateFeesBatch(Request $request): RedirectResponse|JsonResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Unauthorized action for your role.'], 403);
            }
            return back()->with('error', 'Only Super Admin can update service fee configurations.');
        }

        $fees = $request->input('fees', []);
        foreach ($fees as $serviceKey => $data) {
            if (isset($data['flat_fee']) && isset($data['percent_fee'])) {
                FeeService::updateSetting(
                    $serviceKey,
                    (float) $data['flat_fee'],
                    (float) $data['percent_fee'],
                    isset($data['is_enabled']) ? (bool) $data['is_enabled'] : false,
                    $admin->name
                );
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'All service fee settings and controls updated successfully.',
                'data' => FeeService::getAllSettings(),
            ]);
        }

        return back()->with('success', 'All service fee settings and controls updated successfully.');
    }

    /**
     * Financial Transactions Ledger
     */
    public function transactions(Request $request): View
    {
        $admin = $this->getActiveAdmin($request);
        $openComplaints = Complaint::where('status', 'open')->count();

        $query = Transaction::with('user')->orderByDesc('id');

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

        $transactions = $query->paginate(20)->withQueryString();

        return view('admin.transactions', compact('admin', 'transactions', 'openComplaints'));
    }

    /**
     * Customer 360 & User Management
     */
    public function users(Request $request): View
    {
        $admin = $this->getActiveAdmin($request);
        $openComplaints = Complaint::where('status', 'open')->count();

        // Live KPI Metrics for User Management
        $totalUsers = User::count();
        $activeUsers = User::where('status', 'active')->count();
        $loggedInToday = User::whereDate('last_login_at', today())->count();
        $pendingKyc = User::where('kyc_status', 'pending')->count();
        $frozenUsers = User::where('status', 'frozen')->count();
        $suspendedUsers = User::whereIn('status', ['suspended', 'blocked'])->count();

        $query = User::orderByDesc('id');

        // Quick KPI filter tabs
        $activeFilter = $request->query('filter', 'all');
        if ($activeFilter === 'active') {
            $query->where('status', 'active');
        } elseif ($activeFilter === 'today') {
            $query->whereDate('last_login_at', today());
        } elseif ($activeFilter === 'pending_kyc') {
            $query->where('kyc_status', 'pending');
        } elseif ($activeFilter === 'frozen') {
            $query->where('status', 'frozen');
        } elseif ($activeFilter === 'suspended') {
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

        $users = $query->paginate(20)->withQueryString();

        return view('admin.users', compact(
            'admin',
            'users',
            'openComplaints',
            'totalUsers',
            'activeUsers',
            'loggedInToday',
            'pendingKyc',
            'frozenUsers',
            'suspendedUsers',
            'activeFilter'
        ));
    }

    /**
     * Update User Profile (Details, Tier, KYC, Wallet Override)
     */
    public function updateUser(Request $request, int $id): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageKyc() && !$admin->isSuperAdmin()) {
            return back()->with('error', 'Unauthorized action for your role.');
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

        return back()->with('success', "Customer #{$user->id} ({$user->first_name} {$user->last_name}) profile updated successfully.");
    }

    /**
     * Update User Status (Freeze, Unfreeze, Suspend, Reactivate, Block)
     */
    public function updateUserStatus(Request $request, int $id): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageKyc() && !$admin->isSuperAdmin()) {
            return back()->with('error', 'Unauthorized action for your role.');
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

        // Revoke active sessions if suspended or blocked
        if (in_array($newStatus, ['suspended', 'blocked'], true)) {
            $user->tokens()->delete();
        }

        $label = match ($newStatus) {
            'frozen' => 'frozen (Post-No-Debit: can login, all outgoing transactions blocked)',
            'suspended' => 'suspended (Login blocked & all active sessions terminated)',
            'blocked' => 'blocked permanently (Blacklisted)',
            default => 'restored to active status',
        };

        return back()->with('success', "Customer #{$user->id} ({$user->first_name} {$user->last_name}) is now {$label}.");
    }

    /**
     * Soft-Delete User Account (Fintech Standard)
     */
    public function destroyUser(Request $request, int $id): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->isSuperAdmin()) {
            return back()->with('error', 'Only Super Administrators can delete user accounts.');
        }

        $user = User::findOrFail($id);

        // Revoke all authentication tokens immediately
        $user->tokens()->delete();

        // Audit trace before soft delete
        $user->update([
            'status' => 'suspended',
            'status_reason' => 'Account deleted by Admin: ' . $admin->name,
            'status_updated_by' => $admin->name,
            'status_updated_at' => now(),
        ]);

        // Soft delete to preserve foreign key ledger integrity
        $user->delete();

        return back()->with('success', "Customer #{$id} ({$user->first_name} {$user->last_name}) has been safely deleted.");
    }

    /**
     * KYC & Regulatory Compliance
     */
    public function kyc(Request $request): View
    {
        $admin = $this->getActiveAdmin($request);
        $openComplaints = Complaint::where('status', 'open')->count();

        $activeTab = $request->query('tab', 'pending');

        $query = User::with(['latestKycVerification'])->orderByDesc('id');

        switch ($activeTab) {
            case 'verified':
                $query->where('kyc_status', 'verified');
                break;
            case 'rejected':
                $query->where('kyc_status', 'rejected');
                break;
            case 'all':
                // Shows any user that submitted KYC or is in any KYC state
                $query->where(function ($q) {
                    $q->whereIn('kyc_status', ['pending', 'id_verified', 'verified', 'rejected'])
                      ->orWhereHas('kycVerifications');
                });
                break;
            case 'pending':
            default:
                $query->where(function ($q) {
                    $q->whereIn('kyc_status', ['pending', 'id_verified'])
                      ->orWhere(function ($sub) {
                          $sub->where('kyc_status', 'unverified')->whereHas('kycVerifications');
                      });
                });
                break;
        }

        $pendingUsers = $query->paginate(20)->withQueryString();

        // Status counts for tabs and KPI counters
        $pendingCount = User::where(function ($q) {
            $q->whereIn('kyc_status', ['pending', 'id_verified'])
              ->orWhere(function ($sub) {
                  $sub->where('kyc_status', 'unverified')->whereHas('kycVerifications');
              });
        })->count();

        $verifiedCount = User::where('kyc_status', 'verified')->count();
        $rejectedCount = User::where('kyc_status', 'rejected')->count();
        $totalSubmissions = KycVerification::count();

        $premblyConfigured = !empty(config('services.prembly.api_key', env('PREMBLY_API_KEY')));

        return view('admin.kyc', compact(
            'admin',
            'pendingUsers',
            'openComplaints',
            'activeTab',
            'pendingCount',
            'verifiedCount',
            'rejectedCount',
            'totalSubmissions',
            'premblyConfigured'
        ));
    }

    /**
     * Admin Manual Approval of a User KYC Submission
     */
    public function approveKyc(Request $request, int $id): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageKyc() && !$admin->isSuperAdmin()) {
            return back()->with('error', 'Unauthorized action for your role.');
        }

        $user = User::findOrFail($id);
        $result = app(\App\Services\Kyc\KycService::class)->manualApprove($user, $admin->name);

        return back()->with('success', $result['message']);
    }

    /**
     * Admin Manual Rejection of a User KYC Submission
     */
    public function rejectKyc(Request $request, int $id): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageKyc() && !$admin->isSuperAdmin()) {
            return back()->with('error', 'Unauthorized action for your role.');
        }

        $user = User::findOrFail($id);
        $reason = $request->input('reason', $request->input('rejection_reason'));
        $result = app(\App\Services\Kyc\KycService::class)->manualReject($user, $reason, $admin->name);

        return back()->with('success', $result['message']);
    }

    /**
     * Customer Disputes & Tickets
     */
    public function complaints(Request $request): View
    {
        $admin = $this->getActiveAdmin($request);
        $query = Complaint::orderByDesc('id');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $complaints = $query->paginate(20)->withQueryString();
        $openComplaints = Complaint::where('status', 'open')->count();

        return view('admin.complaints', compact('admin', 'complaints', 'openComplaints'));
    }

    /**
     * Update Customer Dispute Ticket
     */
    public function updateComplaint(Request $request, int $id): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageComplaints() && !$admin->isSuperAdmin()) {
            return back()->with('error', 'Unauthorized action for your role.');
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

        return back()->with('success', "Ticket #{$complaint->ticket_id} updated successfully.");
    }

    /**
     * Staff & RBAC Management
     */
    public function staff(Request $request): View
    {
        $admin = $this->getActiveAdmin($request);
        $staff = AdminUser::orderBy('id')->get();
        $openComplaints = Complaint::where('status', 'open')->count();

        return view('admin.staff', compact('admin', 'staff', 'openComplaints'));
    }

    /**
     * Provision New Admin Staff Account
     */
    public function storeStaff(Request $request): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->isSuperAdmin()) {
            return back()->with('error', 'Only Super Admin can invite team members.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:admin_users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:super_admin,compliance_officer,support_lead,financial_auditor',
        ]);

        AdminUser::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'status' => 'active',
        ]);

        return back()->with('success', "Admin staff member '{$validated['name']}' provisioned successfully.");
    }

    /**
     * Crypto Transactions Management, Refunds & Fee Settings Page
     */
    public function cryptoManagement(Request $request): View
    {
        $admin = $this->getActiveAdmin($request);
        $openComplaints = Complaint::where('status', 'open')->count();
        $activeTab = $request->query('tab', 'transactions');

        $cryptoService = app(CryptoService::class);
        $quidaxService = app(QuidaxService::class);

        $cryptoMetrics = $cryptoService->getAdminCryptoMetrics();
        $feeSettings = Schema::hasTable('crypto_fee_settings')
            ? CryptoFeeSetting::orderBy('id')->get()
            : collect();

        $query = CryptoTransaction::with(['user', 'withdrawal', 'exchangeOrder', 'refunds', 'auditLogs'])
            ->orderByDesc('id');

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('symbol') && $request->query('symbol') !== 'all') {
            $query->where('symbol', strtoupper($request->query('symbol')));
        }

        if ($request->filled('network') && $request->query('network') !== 'all') {
            $query->where('network', strtoupper($request->query('network')));
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', strtolower($request->query('status')));
        }

        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('quidax_reference', 'like', "%{$search}%")
                  ->orWhere('tx_hash', 'like', "%{$search}%")
                  ->orWhere('wallet_address', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('email', 'like', "%{$search}%")
                         ->orWhere('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        $cryptoTransactions = Schema::hasTable('crypto_transactions')
            ? $query->paginate(20)->withQueryString()
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);

        $recentRefunds = Schema::hasTable('crypto_refunds')
            ? CryptoRefund::with(['user', 'cryptoTransaction'])->orderByDesc('id')->limit(10)->get()
            : collect();

        $quidaxBaseUrl = (string) AppControl::getSetting('quidax_base_url', config('services.quidax.base_url', 'https://app.quidax.io/api/v1'));
        $quidaxSecretSet = $quidaxService->isConfigured();

        return view('admin.crypto', compact(
            'admin',
            'openComplaints',
            'activeTab',
            'cryptoMetrics',
            'feeSettings',
            'cryptoTransactions',
            'recentRefunds',
            'quidaxBaseUrl',
            'quidaxSecretSet'
        ));
    }

    /**
     * Update a Crypto Fee Setting from the Admin Web Portal
     */
    public function updateCryptoSetting(Request $request, string $serviceType): RedirectResponse|JsonResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            return back()->with('error', 'Only Super Admin can modify crypto fee configurations.');
        }

        $validated = $request->validate([
            'percentage_fee' => 'required|numeric|min:0|max:100',
            'fixed_fee_ngn' => 'required|numeric|min:0',
            'fixed_fee_crypto' => 'nullable|numeric|min:0',
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'required|numeric|min:0',
            'min_withdrawal_amount' => 'nullable|numeric|min:0',
            'max_withdrawal_amount' => 'nullable|numeric|min:0',
            'manual_approval_threshold' => 'nullable|numeric|min:0',
            'is_enabled' => 'nullable|boolean',
            'maintenance_mode' => 'nullable|boolean',
            'maintenance_message' => 'nullable|string|max:500',
        ]);

        $setting = CryptoFeeSetting::where('service_type', $serviceType)->firstOrFail();

        $setting->update([
            'percentage_fee' => $validated['percentage_fee'],
            'fixed_fee_ngn' => $validated['fixed_fee_ngn'],
            'fixed_fee_crypto' => $validated['fixed_fee_crypto'] ?? $setting->fixed_fee_crypto,
            'min_amount' => $validated['min_amount'],
            'max_amount' => $validated['max_amount'],
            'min_withdrawal_amount' => $validated['min_withdrawal_amount'] ?? $setting->min_withdrawal_amount,
            'max_withdrawal_amount' => $validated['max_withdrawal_amount'] ?? $setting->max_withdrawal_amount,
            'manual_approval_threshold' => $validated['manual_approval_threshold'] ?? $setting->manual_approval_threshold,
            'is_enabled' => $request->has('is_enabled') ? $request->boolean('is_enabled') : false,
            'maintenance_mode' => $request->has('maintenance_mode') ? $request->boolean('maintenance_mode') : false,
            'maintenance_message' => $validated['maintenance_message'] ?? $setting->maintenance_message,
            'updated_by' => $admin->name,
        ]);

        $feeKey = $serviceType === 'convert_to_naira' ? 'convert_crypto' : $serviceType;
        if (isset(FeeService::SERVICES[$feeKey])) {
            FeeService::updateSetting(
                $feeKey,
                (float) $setting->fixed_fee_ngn,
                (float) $setting->percentage_fee,
                (bool) $setting->is_enabled && !(bool) $setting->maintenance_mode,
                $admin->name
            );
        }

        app(CryptoService::class)->logAudit(
            null,
            null,
            'admin',
            $admin->id,
            $admin->name,
            'crypto_fee_setting_updated',
            null,
            null,
            "Admin {$admin->name} updated crypto fee settings for {$setting->label}.",
            $setting->toArray()
        );

        return redirect()->route('admin.crypto', ['tab' => 'settings'])
            ->with('success', "Crypto fee settings for '{$setting->label}' saved successfully.");
    }

    /**
     * Update Supported Coin & Network Configuration
     */
    public function updateCryptoCoin(Request $request): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            return back()->with('error', 'Only Super Admin can modify supported coins & networks.');
        }

        $validated = $request->validate([
            'symbol' => 'required|string|max:15',
            'coin_enabled' => 'nullable|boolean',
            'networks' => 'nullable|array',
        ]);

        $symbol = strtoupper($validated['symbol']);
        $coinEnabled = $request->boolean('coin_enabled');
        $postedNetworks = $request->input('networks', []);

        $settings = CryptoFeeSetting::all();
        foreach ($settings as $setting) {
            $coins = $setting->supported_coins ?? [];
            if (!isset($coins[$symbol])) {
                continue;
            }

            $coins[$symbol]['enabled'] = $coinEnabled;

            if (!empty($coins[$symbol]['networks']) && is_array($coins[$symbol]['networks'])) {
                foreach ($coins[$symbol]['networks'] as $netKey => &$netConfig) {
                    if (isset($postedNetworks[$netKey])) {
                        $netConfig['enabled'] = isset($postedNetworks[$netKey]['enabled']) && (bool) $postedNetworks[$netKey]['enabled'];
                        if (isset($postedNetworks[$netKey]['network_fee'])) {
                            $netConfig['network_fee'] = (string) $postedNetworks[$netKey]['network_fee'];
                        }
                        if (isset($postedNetworks[$netKey]['min_deposit'])) {
                            $netConfig['min_deposit'] = (float) $postedNetworks[$netKey]['min_deposit'];
                        }
                    }
                }
                unset($netConfig);
            }

            $setting->update([
                'supported_coins' => $coins,
                'updated_by' => $admin->name,
            ]);
        }

        return redirect()->route('admin.crypto', ['tab' => 'settings'])
            ->with('success', "{$symbol} asset & network configurations updated across all crypto services.");
    }

    /**
     * Update Quidax API Gateway Configuration
     */
    public function updateQuidaxConfig(Request $request): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls()) {
            return back()->with('error', 'Only Super Admin can update Quidax API credentials.');
        }

        $validated = $request->validate([
            'quidax_base_url' => 'required|url',
            'quidax_secret_key' => 'nullable|string',
            'quidax_webhook_secret' => 'nullable|string',
        ]);

        AppControl::setSetting('quidax_base_url', rtrim(trim($validated['quidax_base_url']), '/'), [
            'label' => 'Quidax API Base URL',
            'category' => 'crypto_settings',
            'updated_by' => $admin->name,
        ]);

        if (!empty($validated['quidax_secret_key'])) {
            AppControl::setSetting('quidax_secret_key', trim($validated['quidax_secret_key']), [
                'label' => 'Quidax API Secret Key',
                'category' => 'crypto_settings',
                'updated_by' => $admin->name,
            ]);
        }

        if (!empty($validated['quidax_webhook_secret'])) {
            AppControl::setSetting('quidax_webhook_secret', trim($validated['quidax_webhook_secret']), [
                'label' => 'Quidax Webhook Secret',
                'category' => 'crypto_settings',
                'updated_by' => $admin->name,
            ]);
        }

        return redirect()->route('admin.crypto', ['tab' => 'settings'])
            ->with('success', 'Quidax API Gateway configuration updated successfully.');
    }

    /**
     * Test Quidax API Connection (AJAX)
     */
    public function testQuidax(Request $request): JsonResponse
    {
        $this->getActiveAdmin($request);
        $result = app(QuidaxService::class)->testConnection();

        return response()->json($result);
    }

    /**
     * Reconcile Pending Crypto Transactions with Quidax
     */
    public function reconcileCrypto(Request $request): RedirectResponse
    {
        $this->getActiveAdmin($request);
        $summary = app(CryptoService::class)->reconcilePendingTransactions();

        return back()->with('success', "Quidax Reconciliation completed: {$summary['checked']} pending transactions checked, {$summary['updated']} updated.");
    }

    /**
     * Approve / Reject / Update Status of a Crypto Transaction
     */
    public function updateCryptoTransactionStatus(Request $request, int $id): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls() && !$admin->canManageKyc() && !$admin->isSuperAdmin()) {
            return back()->with('error', 'Unauthorized action for your role.');
        }

        $validated = $request->validate([
            'status' => 'required|string|in:processing,successful,failed,rejected',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $tx = app(CryptoService::class)->updateTransactionStatusByAdmin(
                $id,
                $validated['status'],
                $admin->id,
                $admin->name,
                $validated['reason'] ?? null
            );

            return back()->with('success', "Crypto transaction {$tx->reference} status updated to " . strtoupper($tx->status) . ".");
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Refund an Eligible Crypto or Naira Transaction
     */
    public function refundCryptoTransaction(Request $request, int $id): RedirectResponse
    {
        $admin = $this->getActiveAdmin($request);
        if (!$admin->canManageAppControls() && !$admin->canViewFinancials() && !$admin->isSuperAdmin()) {
            return back()->with('error', 'Unauthorized action for your role.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        try {
            $result = app(CryptoService::class)->refundTransaction(
                $id,
                $admin->id,
                $admin->name,
                $validated['reason']
            );

            return back()->with(
                'success',
                "Refund processed! Returned {$result['amount_refunded']} {$result['asset_refunded']} to customer's wallet (Refund Ref: {$result['refund']->refund_reference})."
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
