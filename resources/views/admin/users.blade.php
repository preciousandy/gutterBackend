@extends('admin.layout')

@section('title', 'User Management & Security Controls | Gutter Command')
@section('page_title', 'User Management & Account Controls')
@section('page_sub', 'Manage customers, audit logs, freeze (PND), suspend, block, and access tiers')

@section('content')
<style>
  /* KPI Cards Grid */
  .kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
  }
  .kpi-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 16px 18px;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.2s ease;
    text-decoration: none;
    color: inherit;
    position: relative;
    overflow: hidden;
  }
  .kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--line-active);
  }
  .kpi-card.active-tab {
    border-color: var(--navy);
    background: linear-gradient(180deg, #FFFFFF 0%, var(--chip) 100%);
    box-shadow: 0 0 0 2px var(--navy);
  }
  .kpi-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
  }
  .kpi-title {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--ink-soft);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .kpi-icon {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
  }
  .kpi-value {
    font-size: 24px;
    font-weight: 800;
    font-family: var(--font-mono);
    color: var(--navy-deep);
    line-height: 1.1;
  }
  .kpi-desc {
    font-size: 11px;
    color: var(--ink-soft);
    margin-top: 4px;
  }

  /* Filter Tabs Bar */
  .filter-tabs-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 4px;
    margin-bottom: 16px;
  }
  .tab-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    background: var(--surface);
    border: 1px solid var(--line);
    color: var(--ink-mid);
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.15s ease;
  }
  .tab-chip:hover {
    border-color: var(--navy);
    color: var(--navy);
  }
  .tab-chip.active {
    background: var(--navy);
    color: #FFFFFF;
    border-color: var(--navy);
  }
  .tab-chip .badge-count {
    background: rgba(0, 0, 0, 0.08);
    padding: 1px 7px;
    border-radius: 10px;
    font-size: 10.5px;
    font-family: var(--font-mono);
  }
  .tab-chip.active .badge-count {
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
  }

  /* Filter Card */
  .filter-card {
    background-color: var(--surface);
    border-radius: var(--radius-lg);
    border: 1px solid var(--line);
    padding: 14px 18px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 20px;
  }
  .search-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    background-color: var(--surface-alt);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 8px 14px;
    flex: 1 1 300px;
  }
  .search-input {
    border: none;
    background: transparent;
    outline: none;
    font-size: 13px;
    color: var(--ink);
    width: 100%;
    font-family: inherit;
  }
  .filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .select {
    background-color: var(--surface-alt);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 8px 12px;
    font-size: 12.5px;
    color: var(--ink);
    outline: none;
    font-family: inherit;
  }
  .filter-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 10px;
    background-color: var(--navy);
    color: #FFFFFF;
    border: none;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
  }
  .reset-btn {
    padding: 8px 12px;
    border-radius: 10px;
    background-color: var(--chip);
    color: var(--ink-mid);
    border: 1px solid var(--line);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
  }

  /* Table styling */
  .user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background-color: #EEF2FB;
    color: var(--navy);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 13px;
    border: 1px solid var(--line);
    flex-shrink: 0;
  }
  .user-cell {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  /* Status Badges */
  .status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 800;
    padding: 4px 9px;
    border-radius: 8px;
    letter-spacing: 0.3px;
    text-transform: uppercase;
  }
  .status-active {
    background-color: #E8FBF4;
    color: #0FA97F;
    border: 1px solid rgba(35, 211, 165, 0.4);
  }
  .status-frozen {
    background-color: #F0FDF4;
    color: #0369A1;
    background: #E0F2FE;
    border: 1px solid rgba(56, 189, 248, 0.4);
  }
  .status-suspended {
    background-color: #FFE4E6;
    color: #E11D48;
    border: 1px solid rgba(225, 29, 72, 0.3);
  }
  .status-blocked {
    background-color: #1F2937;
    color: #F87171;
    border: 1px solid #374151;
  }

  /* Action Dropdown Menu */
  .action-dropdown-wrap {
    position: relative;
    display: inline-block;
  }
  .action-trigger-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    background-color: var(--surface-alt);
    border: 1px solid var(--line);
    color: var(--navy);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .action-trigger-btn:hover {
    background-color: var(--chip);
    border-color: var(--navy);
  }
  .action-menu {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    background-color: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    box-shadow: var(--shadow-xl);
    width: 200px;
    z-index: 100;
    padding: 6px;
    animation: fadeIn 0.15s ease-out;
  }
  .action-menu.show {
    display: block;
  }
  .action-menu-item {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 10px;
    width: 100%;
    border-radius: 8px;
    border: none;
    background: none;
    color: var(--ink);
    font-size: 12.5px;
    font-weight: 600;
    text-align: left;
    cursor: pointer;
    transition: background-color 0.1s ease;
    font-family: inherit;
    text-decoration: none;
  }
  .action-menu-item:hover {
    background-color: var(--surface-alt);
  }
  .action-menu-item.danger {
    color: var(--red);
  }
  .action-menu-item.danger:hover {
    background-color: var(--red-bg);
  }
  .action-menu-item.warning {
    color: #D97706;
  }
  .action-menu-item.warning:hover {
    background-color: #FEF3C7;
  }
  .action-menu-divider {
    height: 1px;
    background-color: var(--line-soft);
    margin: 4px 0;
  }

  /* Modal Form Controls */
  .field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 14px;
  }
  .label {
    font-size: 12px;
    font-weight: 700;
    color: var(--ink);
  }
  .modal-select, .modal-input, .modal-textarea {
    background-color: var(--surface-alt);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 13px;
    color: var(--ink);
    outline: none;
    font-family: inherit;
    width: 100%;
  }
  .modal-textarea {
    resize: vertical;
    min-height: 70px;
  }
  .info-alert {
    padding: 12px 14px;
    border-radius: 10px;
    font-size: 12.5px;
    line-height: 1.4;
    display: flex;
    gap: 10px;
    align-items: flex-start;
  }
  .info-alert.freeze {
    background-color: #E0F2FE;
    border: 1px solid #BAE6FD;
    color: #0369A1;
  }
  .info-alert.suspend {
    background-color: #FFE4E6;
    border: 1px solid #FECDD3;
    color: #9F1239;
  }
  .info-alert.block {
    background-color: #F3F4F6;
    border: 1px solid #E5E7EB;
    color: #1F2937;
  }
  .info-alert.reactivate {
    background-color: #E8FBF4;
    border: 1px solid #A7F3D0;
    color: #065F46;
  }
</style>

<!-- 1. KPI Metrics Grid (Counts requested by Admin) -->
<div class="kpi-grid">
  <!-- Active Users -->
  <a href="{{ route('admin.users', ['filter' => 'active']) }}" class="kpi-card {{ ($activeFilter ?? '') === 'active' ? 'active-tab' : '' }}">
    <div class="kpi-top">
      <span class="kpi-title">Active Users</span>
      <div class="kpi-icon" style="background-color: #E8FBF4; color: #0FA97F;">🟢</div>
    </div>
    <div class="kpi-value">{{ number_format($activeUsers ?? 0) }}</div>
    <div class="kpi-desc">Can log in & perform transactions</div>
  </a>

  <!-- Logged In Today -->
  <a href="{{ route('admin.users', ['filter' => 'today']) }}" class="kpi-card {{ ($activeFilter ?? '') === 'today' ? 'active-tab' : '' }}">
    <div class="kpi-top">
      <span class="kpi-title">Logged In Today</span>
      <div class="kpi-icon" style="background-color: #EFF6FF; color: #2563EB;">⚡</div>
    </div>
    <div class="kpi-value">{{ number_format($loggedInToday ?? 0) }}</div>
    <div class="kpi-desc">Active logins within last 24h</div>
  </a>

  <!-- Pending KYC -->
  <a href="{{ route('admin.users', ['filter' => 'pending_kyc']) }}" class="kpi-card {{ ($activeFilter ?? '') === 'pending_kyc' ? 'active-tab' : '' }}">
    <div class="kpi-top">
      <span class="kpi-title">Pending KYC</span>
      <div class="kpi-icon" style="background-color: #FEF3C7; color: #D97706;">⏳</div>
    </div>
    <div class="kpi-value">{{ number_format($pendingKyc ?? 0) }}</div>
    <div class="kpi-desc">Awaiting tier limit review</div>
  </a>

  <!-- Frozen Users (Post-No-Debit) -->
  <a href="{{ route('admin.users', ['filter' => 'frozen']) }}" class="kpi-card {{ ($activeFilter ?? '') === 'frozen' ? 'active-tab' : '' }}">
    <div class="kpi-top">
      <span class="kpi-title">Frozen Users</span>
      <div class="kpi-icon" style="background-color: #E0F2FE; color: #0284C7;">❄️</div>
    </div>
    <div class="kpi-value">{{ number_format($frozenUsers ?? 0) }}</div>
    <div class="kpi-desc">Can login; debits & transactions blocked</div>
  </a>

  <!-- Suspended Users (Login blocked) -->
  <a href="{{ route('admin.users', ['filter' => 'suspended']) }}" class="kpi-card {{ ($activeFilter ?? '') === 'suspended' ? 'active-tab' : '' }}">
    <div class="kpi-top">
      <span class="kpi-title">Suspended / Blocked</span>
      <div class="kpi-icon" style="background-color: #FFE4E6; color: #E11D48;">🚫</div>
    </div>
    <div class="kpi-value">{{ number_format($suspendedUsers ?? 0) }}</div>
    <div class="kpi-desc">Login blocked & sessions killed</div>
  </a>

  <!-- Total Accounts -->
  <a href="{{ route('admin.users', ['filter' => 'all']) }}" class="kpi-card {{ ($activeFilter ?? '') === 'all' && !request('status') && !request('kyc_status') ? 'active-tab' : '' }}">
    <div class="kpi-top">
      <span class="kpi-title">Total Customers</span>
      <div class="kpi-icon" style="background-color: #EEF2FB; color: var(--navy);">👥</div>
    </div>
    <div class="kpi-value">{{ number_format($totalUsers ?? 0) }}</div>
    <div class="kpi-desc">All registered accounts in ledger</div>
  </a>
</div>

<!-- 2. Quick Filter Tabs -->
<div class="filter-tabs-bar">
  <a href="{{ route('admin.users') }}" class="tab-chip {{ (!request('filter') || request('filter') === 'all') && !request('status') ? 'active' : '' }}">
    <span>All Customers</span>
    <span class="badge-count">{{ $totalUsers ?? 0 }}</span>
  </a>
  <a href="{{ route('admin.users', ['filter' => 'active']) }}" class="tab-chip {{ request('filter') === 'active' ? 'active' : '' }}">
    <span>🟢 Active</span>
    <span class="badge-count">{{ $activeUsers ?? 0 }}</span>
  </a>
  <a href="{{ route('admin.users', ['filter' => 'today']) }}" class="tab-chip {{ request('filter') === 'today' ? 'active' : '' }}">
    <span>⚡ Logged In Today</span>
    <span class="badge-count">{{ $loggedInToday ?? 0 }}</span>
  </a>
  <a href="{{ route('admin.users', ['filter' => 'pending_kyc']) }}" class="tab-chip {{ request('filter') === 'pending_kyc' ? 'active' : '' }}">
    <span>⏳ Pending KYC</span>
    <span class="badge-count">{{ $pendingKyc ?? 0 }}</span>
  </a>
  <a href="{{ route('admin.users', ['filter' => 'frozen']) }}" class="tab-chip {{ request('filter') === 'frozen' ? 'active' : '' }}">
    <span>❄️ Frozen (PND)</span>
    <span class="badge-count">{{ $frozenUsers ?? 0 }}</span>
  </a>
  <a href="{{ route('admin.users', ['filter' => 'suspended']) }}" class="tab-chip {{ request('filter') === 'suspended' ? 'active' : '' }}">
    <span>🚫 Suspended / Blocked</span>
    <span class="badge-count">{{ $suspendedUsers ?? 0 }}</span>
  </a>
</div>

<!-- 3. Search and Granular Filtering Form -->
<form action="{{ route('admin.users') }}" method="GET" class="filter-card">
  @if(request('filter'))
    <input type="hidden" name="filter" value="{{ request('filter') }}">
  @endif

  <div class="search-wrap">
    <span>🔍</span>
    <input
      type="text"
      name="search"
      placeholder="Search customers by name, phone, or email..."
      value="{{ request('search') }}"
      class="search-input"
    >
  </div>

  <div class="filter-group">
    <select name="status" class="select" onchange="this.form.submit()">
      <option value="all">All Account Statuses</option>
      <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
      <option value="frozen" {{ request('status') === 'frozen' ? 'selected' : '' }}>Frozen (PND)</option>
      <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
      <option value="blocked" {{ request('status') === 'blocked' ? 'selected' : '' }}>Blocked</option>
    </select>

    <select name="kyc_status" class="select" onchange="this.form.submit()">
      <option value="all">All KYC Statuses</option>
      <option value="verified" {{ request('kyc_status') === 'verified' ? 'selected' : '' }}>Verified</option>
      <option value="pending" {{ request('kyc_status') === 'pending' ? 'selected' : '' }}>Pending Review</option>
      <option value="unverified" {{ request('kyc_status') === 'unverified' ? 'selected' : '' }}>Unverified</option>
      <option value="rejected" {{ request('kyc_status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
    </select>

    <select name="tier" class="select" onchange="this.form.submit()">
      <option value="all">All Account Tiers</option>
      <option value="1" {{ request('tier') == 1 ? 'selected' : '' }}>Tier 1 (₦50K / day)</option>
      <option value="2" {{ request('tier') == 2 ? 'selected' : '' }}>Tier 2 (₦5M / day)</option>
      <option value="3" {{ request('tier') == 3 ? 'selected' : '' }}>Tier 3 (₦25M / day)</option>
    </select>

    <button type="submit" class="filter-btn">
      <span>Apply</span>
    </button>

    @if(request()->hasAny(['search', 'status', 'kyc_status', 'tier', 'filter']))
      <a href="{{ route('admin.users') }}" class="reset-btn" title="Reset all filters">Reset</a>
    @endif
  </div>
</form>

<!-- 4. Customers Table -->
<div class="card">
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Customer</th>
          <th>Account Status</th>
          <th>KYC & Tier</th>
          <th>Wallet Balance</th>
          <th>Phone & Last Login</th>
          <th>Virtual Account</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse($users as $user)
          <tr>
            <!-- Customer info -->
            <td>
              <div class="user-cell">
                <div class="user-avatar">
                  {{ strtoupper(substr($user->first_name ?? 'U', 0, 1)) }}{{ strtoupper(substr($user->last_name ?? '', 0, 1)) }}
                </div>
                <div>
                  <div style="font-weight: 700; font-size: 13.5px; display: flex; align-items: center; gap: 6px;">
                    <span>{{ $user->first_name }} {{ $user->last_name }}</span>
                    <span style="font-size: 10px; color: var(--ink-soft); font-family: var(--font-mono); background: var(--surface-alt); padding: 1px 5px; border-radius: 4px; border: 1px solid var(--line);">#{{ $user->id }}</span>
                  </div>
                  <div style="font-size: 11.5px; color: var(--ink-soft);">{{ $user->email }}</div>
                </div>
              </div>
            </td>

            <!-- Account Status (Active, Frozen, Suspended, Blocked) -->
            <td>
              @php
                $status = $user->status ?? 'active';
              @endphp
              @if($status === 'active')
                <span class="status-badge status-active">
                  <span style="font-size: 8px;">●</span> Active
                </span>
              @elseif($status === 'frozen')
                <div>
                  <span class="status-badge status-frozen" title="Post-No-Debit: Can login, debits blocked">
                    ❄️ Frozen (PND)
                  </span>
                  @if($user->status_reason)
                    <div style="font-size: 10.5px; color: #0369A1; margin-top: 3px; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $user->status_reason }}">
                      {{ $user->status_reason }}
                    </div>
                  @endif
                </div>
              @elseif($status === 'suspended')
                <div>
                  <span class="status-badge status-suspended" title="Login blocked">
                    🚫 Suspended
                  </span>
                  @if($user->status_reason)
                    <div style="font-size: 10.5px; color: #E11D48; margin-top: 3px; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $user->status_reason }}">
                      {{ $user->status_reason }}
                    </div>
                  @endif
                </div>
              @elseif($status === 'blocked')
                <div>
                  <span class="status-badge status-blocked" title="Blacklisted">
                    ⛔ Blocked
                  </span>
                  @if($user->status_reason)
                    <div style="font-size: 10.5px; color: var(--ink-soft); margin-top: 3px; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $user->status_reason }}">
                      {{ $user->status_reason }}
                    </div>
                  @endif
                </div>
              @endif
            </td>

            <!-- KYC & Tier -->
            <td>
              <div style="display: flex; flex-direction: column; gap: 4px;">
                <span class="badge {{ $user->kyc_status === 'verified' ? 'badge-success' : ($user->kyc_status === 'pending' ? 'badge-warning' : 'badge-info') }}">
                  {{ strtoupper($user->kyc_status ?? 'UNVERIFIED') }}
                </span>
                <span style="font-size: 11px; font-weight: 700; color: {{ $user->tier === 3 ? '#7E22CE' : ($user->tier === 2 ? '#1D4ED8' : 'var(--ink-mid)') }};">
                  Tier {{ $user->tier ?? 1 }} Limit
                </span>
              </div>
            </td>

            <!-- Balance -->
            <td style="font-weight: 800; font-family: var(--font-mono); font-size: 14px; color: var(--navy-deep);">
              ₦{{ number_format($user->wallet_balance, 2) }}
            </td>

            <!-- Phone & Last Login -->
            <td>
              <div style="font-family: var(--font-mono); font-size: 12px; font-weight: 600;">{{ $user->phone ?? 'No Phone' }}</div>
              <div style="font-size: 11px; color: var(--ink-soft); margin-top: 2px;">
                @if($user->last_login_at)
                  <span title="{{ $user->last_login_at->format('Y-m-d H:i:s') }}">
                    ⚡ {{ $user->last_login_at->diffForHumans() }}
                  </span>
                @else
                  <span style="color: var(--ink-faint);">Never logged in</span>
                @endif
              </div>
            </td>

            <!-- Virtual Account -->
            <td>
              <div style="font-size: 12.5px; font-weight: 600;">{{ $user->bank_name ?? 'Wema Bank' }}</div>
              <div style="font-size: 11px; color: var(--ink-soft); font-family: var(--font-mono);">{{ $user->account_number ?? 'Pending' }}</div>
            </td>

            <!-- Action Menu Dropdown -->
            <td>
              @if($admin->canManageKyc() || $admin->isSuperAdmin())
                <div class="action-dropdown-wrap">
                  <button type="button" class="action-trigger-btn" onclick="toggleActionMenu(event, 'menu-{{ $user->id }}')">
                    <span>Manage</span>
                    <span style="font-size: 9px;">▼</span>
                  </button>
                  <div class="action-menu" id="menu-{{ $user->id }}">
                    <!-- 1. Edit User Details -->
                    <button
                      type="button"
                      class="action-menu-item"
                      onclick="openEditUserModal({{ json_encode($user) }})"
                    >
                      <span>✏️</span> Edit Profile & Balance
                    </button>

                    <div class="action-menu-divider"></div>

                    <!-- 2. Freeze / Unfreeze -->
                    @if($user->status === 'frozen')
                      <button
                        type="button"
                        class="action-menu-item"
                        onclick="openStatusModal({{ $user->id }}, '{{ addslashes($user->first_name . ' ' . $user->last_name) }}', 'unfreeze')"
                      >
                        <span>☀️</span> Unfreeze Account
                      </button>
                    @else
                      <button
                        type="button"
                        class="action-menu-item warning"
                        onclick="openStatusModal({{ $user->id }}, '{{ addslashes($user->first_name . ' ' . $user->last_name) }}', 'freeze')"
                      >
                        <span>❄️</span> Freeze Account (PND)
                      </button>
                    @endif

                    <!-- 3. Suspend / Unsuspend -->
                    @if($user->status === 'suspended')
                      <button
                        type="button"
                        class="action-menu-item"
                        onclick="openStatusModal({{ $user->id }}, '{{ addslashes($user->first_name . ' ' . $user->last_name) }}', 'unsuspend')"
                      >
                        <span>▶️</span> Reactivate (Unsuspend)
                      </button>
                    @else
                      <button
                        type="button"
                        class="action-menu-item danger"
                        onclick="openStatusModal({{ $user->id }}, '{{ addslashes($user->first_name . ' ' . $user->last_name) }}', 'suspend')"
                      >
                        <span>⏸️</span> Suspend Account
                      </button>
                    @endif

                    <!-- 4. Block -->
                    @if($user->status !== 'blocked')
                      <button
                        type="button"
                        class="action-menu-item danger"
                        onclick="openStatusModal({{ $user->id }}, '{{ addslashes($user->first_name . ' ' . $user->last_name) }}', 'block')"
                      >
                        <span>🚫</span> Block (Blacklist)
                      </button>
                    @else
                      <button
                        type="button"
                        class="action-menu-item"
                        onclick="openStatusModal({{ $user->id }}, '{{ addslashes($user->first_name . ' ' . $user->last_name) }}', 'active')"
                      >
                        <span>🔓</span> Unblock Account
                      </button>
                    @endif

                    <!-- 5. Delete (Super Admin only) -->
                    @if($admin->isSuperAdmin())
                      <div class="action-menu-divider"></div>
                      <button
                        type="button"
                        class="action-menu-item danger"
                        onclick="openDeleteModal({{ $user->id }}, '{{ addslashes($user->first_name . ' ' . $user->last_name) }}')"
                      >
                        <span>🗑️</span> Delete Account
                      </button>
                    @endif
                  </div>
                </div>
              @else
                <span style="font-size: 11px; color: var(--ink-faint);">Read-Only</span>
              @endif
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" style="padding: 50px; text-align: center; color: var(--ink-soft);">
              <div style="font-size: 28px; margin-bottom: 8px;">👥</div>
              <div style="font-weight: 700; font-size: 14px; color: var(--navy);">No customers found matching this criteria</div>
              <p style="font-size: 12px; margin-top: 4px;">Try loosening your filters or resetting the search query.</p>
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($users->hasPages())
    <div style="padding: 18px 24px; display: flex; justify-content: flex-end;">
      {{ $users->links() }}
    </div>
  @endif
</div>

<!-- ================= MODALS ================= -->

<!-- 1. Edit Customer Profile Modal -->
<div class="modal-overlay" id="editModal" onclick="closeEditModal()">
  <div class="modal-content" onclick="event.stopPropagation()">
    <div class="modal-header">
      <div>
        <h3 style="font-size: 16px; font-weight: 800; color: var(--navy-deep);">Edit Customer Profile</h3>
        <p id="editModalSub" style="font-size: 12px; color: var(--ink-soft); margin-top: 2px;">Customer details</p>
      </div>
      <button type="button" onclick="closeEditModal()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--ink-soft);">✕</button>
    </div>

    <form id="editUserForm" action="" method="POST" class="modal-body">
      @csrf
      @method('PUT')

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
        <div class="field-group">
          <label class="label">First Name</label>
          <input type="text" name="first_name" id="editFirstName" class="modal-input" required>
        </div>
        <div class="field-group">
          <label class="label">Last Name</label>
          <input type="text" name="last_name" id="editLastName" class="modal-input" required>
        </div>
      </div>

      <div class="field-group">
        <label class="label">Phone Number</label>
        <input type="text" name="phone" id="editPhone" class="modal-input">
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
        <div class="field-group">
          <label class="label">Account Tier</label>
          <select name="tier" id="editTier" class="modal-select">
            <option value="1">Tier 1 (₦50K / day)</option>
            <option value="2">Tier 2 (₦5M / day)</option>
            <option value="3">Tier 3 (₦25M / day)</option>
          </select>
        </div>
        <div class="field-group">
          <label class="label">KYC Verification</label>
          <select name="kyc_status" id="editKyc" class="modal-select">
            <option value="unverified">Unverified</option>
            <option value="pending">Pending Review</option>
            <option value="verified">Verified</option>
            <option value="rejected">Rejected</option>
          </select>
        </div>
      </div>

      <div class="field-group">
        <label class="label">Account Status</label>
        <select name="status" id="editStatus" class="modal-select">
          <option value="active">Active (Normal access)</option>
          <option value="frozen">Frozen (PND - Post-No-Debit: Login ok, no debit)</option>
          <option value="suspended">Suspended (Login blocked)</option>
          <option value="blocked">Blocked (Blacklisted)</option>
        </select>
      </div>

      @if($admin->isSuperAdmin())
        <div class="field-group">
          <label class="label">Wallet Balance Override (₦)</label>
          <input type="number" step="0.01" name="wallet_balance" id="editBalance" class="modal-input">
          <span style="font-size: 11px; color: var(--ink-soft);">Super admin ledger adjustment override.</span>
        </div>
      @endif

      <div style="display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-top: 10px;">
        <button type="button" onclick="closeEditModal()" style="padding: 10px 16px; background-color: var(--surface-alt); border: 1px solid var(--line); border-radius: 10px; font-size: 13px; font-weight: 700; color: var(--ink-soft); cursor: pointer;">
          Cancel
        </button>
        <button type="submit" style="padding: 10px 20px; background-color: var(--navy); color: #FFFFFF; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer;">
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- 2. Status Action Modal (Freeze / Suspend / Block / Unfreeze / Reactivate) -->
<div class="modal-overlay" id="statusModal" onclick="closeStatusModal()">
  <div class="modal-content" onclick="event.stopPropagation()">
    <div class="modal-header">
      <div>
        <h3 id="statusModalTitle" style="font-size: 16px; font-weight: 800; color: var(--navy-deep);">Update Account Status</h3>
        <p id="statusModalSub" style="font-size: 12px; color: var(--ink-soft); margin-top: 2px;">Customer status change</p>
      </div>
      <button type="button" onclick="closeStatusModal()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--ink-soft);">✕</button>
    </div>

    <form id="statusUserForm" action="" method="POST" class="modal-body">
      @csrf
      @method('PUT')
      <input type="hidden" name="action" id="statusActionInput" value="">

      <!-- Dynamic Info Explaining Action Consequences -->
      <div id="statusAlertBox" class="info-alert freeze">
        <span id="statusAlertIcon" style="font-size: 18px;">ℹ️</span>
        <div id="statusAlertText" style="font-size: 12px;"></div>
      </div>

      <div class="field-group">
        <label class="label">Reason for Action (Recorded in Audit Logs)</label>
        <textarea
          name="reason"
          id="statusReason"
          class="modal-textarea"
          placeholder="E.g. Suspicious transfer activity detected, AML compliance check, or customer request..."
          required
        ></textarea>
      </div>

      <div style="display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-top: 10px;">
        <button type="button" onclick="closeStatusModal()" style="padding: 10px 16px; background-color: var(--surface-alt); border: 1px solid var(--line); border-radius: 10px; font-size: 13px; font-weight: 700; color: var(--ink-soft); cursor: pointer;">
          Cancel
        </button>
        <button type="submit" id="statusSubmitBtn" style="padding: 10px 20px; background-color: var(--navy); color: #FFFFFF; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer;">
          Confirm Action
        </button>
      </div>
    </form>
  </div>
</div>

<!-- 3. Soft Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal" onclick="closeDeleteModal()">
  <div class="modal-content" onclick="event.stopPropagation()">
    <div class="modal-header">
      <div>
        <h3 style="font-size: 16px; font-weight: 800; color: var(--red);">Delete User Account</h3>
        <p id="deleteModalSub" style="font-size: 12px; color: var(--ink-soft); margin-top: 2px;">Customer account removal</p>
      </div>
      <button type="button" onclick="closeDeleteModal()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--ink-soft);">✕</button>
    </div>

    <form id="deleteUserForm" action="" method="POST" class="modal-body">
      @csrf
      @method('DELETE')

      <div class="info-alert suspend">
        <span style="font-size: 18px;">⚠️</span>
        <div style="font-size: 12.5px;">
          <strong>Fintech Compliance Notice:</strong><br>
          This will immediately revoke all authentication tokens and lock the account. Per financial regulations, transaction ledgers and audit records are preserved via Soft Deletes.
        </div>
      </div>

      <p style="font-size: 13px; color: var(--ink);">
        Are you sure you want to delete <strong id="deleteUserName">this customer</strong>? This action cannot be undone.
      </p>

      <div style="display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-top: 10px;">
        <button type="button" onclick="closeDeleteModal()" style="padding: 10px 16px; background-color: var(--surface-alt); border: 1px solid var(--line); border-radius: 10px; font-size: 13px; font-weight: 700; color: var(--ink-soft); cursor: pointer;">
          Cancel
        </button>
        <button type="submit" style="padding: 10px 20px; background-color: var(--red); color: #FFFFFF; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer;">
          Yes, Delete Account
        </button>
      </div>
    </form>
  </div>
</div>
@endsection

@section('scripts')
<script>
  // Close action menus when clicking outside
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.action-dropdown-wrap')) {
      document.querySelectorAll('.action-menu.show').forEach(function(menu) {
        menu.classList.remove('show');
      });
    }
  });

  function toggleActionMenu(event, menuId) {
    event.stopPropagation();
    const targetMenu = document.getElementById(menuId);
    document.querySelectorAll('.action-menu.show').forEach(function(menu) {
      if (menu !== targetMenu) menu.classList.remove('show');
    });
    if (targetMenu) {
      targetMenu.classList.toggle('show');
    }
  }

  // 1. Edit User Modal
  function openEditUserModal(user) {
    document.querySelectorAll('.action-menu.show').forEach(m => m.classList.remove('show'));
    document.getElementById('editModalSub').innerText = (user.first_name || '') + ' ' + (user.last_name || '') + ' (' + user.email + ')';
    document.getElementById('editFirstName').value = user.first_name || '';
    document.getElementById('editLastName').value = user.last_name || '';
    document.getElementById('editPhone').value = user.phone || '';
    document.getElementById('editTier').value = user.tier || 1;
    document.getElementById('editKyc').value = user.kyc_status || 'unverified';
    document.getElementById('editStatus').value = user.status || 'active';
    if (document.getElementById('editBalance')) {
      document.getElementById('editBalance').value = user.wallet_balance || 0;
    }
    document.getElementById('editUserForm').action = '/admin/users/' + user.id;
    document.getElementById('editModal').classList.add('active');
  }

  function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
  }

  // 2. Status Modal (Freeze, Unfreeze, Suspend, Unsuspend, Block)
  function openStatusModal(userId, userName, action) {
    document.querySelectorAll('.action-menu.show').forEach(m => m.classList.remove('show'));
    const form = document.getElementById('statusUserForm');
    form.action = '/admin/users/' + userId + '/status';
    document.getElementById('statusActionInput').value = action;
    document.getElementById('statusModalSub').innerText = 'Target: ' + userName + ' (ID #' + userId + ')';
    document.getElementById('statusReason').value = '';

    const titleEl = document.getElementById('statusModalTitle');
    const alertBox = document.getElementById('statusAlertBox');
    const alertIcon = document.getElementById('statusAlertIcon');
    const alertText = document.getElementById('statusAlertText');
    const submitBtn = document.getElementById('statusSubmitBtn');

    if (action === 'freeze') {
      titleEl.innerText = 'Freeze Account (Post-No-Debit)';
      titleEl.style.color = '#0369A1';
      alertBox.className = 'info-alert freeze';
      alertIcon.innerText = '❄️';
      alertText.innerHTML = '<strong>Post-No-Debit (PND) Restriction:</strong><br>The customer will still be able to log in to view their balance and transactions, but <strong>all outgoing actions (transfers, airtime, data, crypto swaps/sends) will be blocked</strong>.';
      submitBtn.style.backgroundColor = '#0284C7';
      submitBtn.innerText = 'Freeze Account';
    } else if (action === 'unfreeze') {
      titleEl.innerText = 'Unfreeze Account';
      titleEl.style.color = '#065F46';
      alertBox.className = 'info-alert reactivate';
      alertIcon.innerText = '☀️';
      alertText.innerHTML = '<strong>Remove Debit Restriction:</strong><br>This will unfreeze the customer account and restore full access to send transfers, pay bills, and trade crypto.';
      submitBtn.style.backgroundColor = '#0FA97F';
      submitBtn.innerText = 'Restore Active Status';
    } else if (action === 'suspend') {
      titleEl.innerText = 'Suspend Customer Account';
      titleEl.style.color = '#E11D48';
      alertBox.className = 'info-alert suspend';
      alertIcon.innerText = '⏸️';
      alertText.innerHTML = '<strong>Total Login Lockout:</strong><br>The user will be immediately logged out of all active mobile sessions. <strong>They will NOT be able to log into the app</strong> until reactivated.';
      submitBtn.style.backgroundColor = '#E11D48';
      submitBtn.innerText = 'Suspend Account';
    } else if (action === 'unsuspend' || action === 'active') {
      titleEl.innerText = 'Reactivate Customer Account';
      titleEl.style.color = '#065F46';
      alertBox.className = 'info-alert reactivate';
      alertIcon.innerText = '▶️';
      alertText.innerHTML = '<strong>Reactivate Access:</strong><br>The customer will be allowed to log into the mobile app and perform normal financial operations.';
      submitBtn.style.backgroundColor = '#0FA97F';
      submitBtn.innerText = 'Reactivate Account';
    } else if (action === 'block') {
      titleEl.innerText = 'Block & Blacklist Customer';
      titleEl.style.color = '#111827';
      alertBox.className = 'info-alert block';
      alertIcon.innerText = '🚫';
      alertText.innerHTML = '<strong>Severe Blacklist:</strong><br>Account will be permanently marked as blocked. Active tokens are immediately revoked and all access is terminated.';
      submitBtn.style.backgroundColor = '#1F2937';
      submitBtn.innerText = 'Block Customer';
    }

    document.getElementById('statusModal').classList.add('active');
  }

  function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('active');
  }

  // 3. Delete Modal
  function openDeleteModal(userId, userName) {
    document.querySelectorAll('.action-menu.show').forEach(m => m.classList.remove('show'));
    document.getElementById('deleteUserForm').action = '/admin/users/' + userId;
    document.getElementById('deleteUserName').innerText = userName + ' (ID #' + userId + ')';
    document.getElementById('deleteModal').classList.add('active');
  }

  function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
  }
</script>
@endsection
