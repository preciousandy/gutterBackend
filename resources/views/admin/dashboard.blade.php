@extends('admin.layout')

@section('title', 'Financial Overview | Gutter Command')
@section('page_title', 'Executive Financial Overview')
@section('page_sub', 'Real-time liquidity, transaction volumes, settlement rails, and revenue KPIs')

@section('content')
<style>
  .kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 18px;
    margin-bottom: 24px;
  }
  .kpi-card {
    background-color: var(--surface);
    border-radius: var(--radius-lg);
    border: 1px solid var(--line);
    padding: 20px 22px;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .kpi-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .kpi-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--ink-soft);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .kpi-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
  }
  .kpi-value {
    font-size: 24px;
    font-weight: 800;
    color: var(--navy-deep);
    letter-spacing: -0.5px;
  }
  .kpi-sub {
    font-size: 11.5px;
    color: var(--ink-soft);
  }

  .channels-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
  }
  .channel-card {
    background-color: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    padding: 16px 18px;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .channel-top {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .channel-icon {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
  }
  .channel-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-deep);
  }
  .channel-provider {
    font-size: 11px;
    color: var(--ink-soft);
  }
  .channel-amount {
    font-size: 20px;
    font-weight: 800;
    color: var(--ink);
    margin: 4px 0;
    font-family: var(--font-mono);
  }
  .channel-status {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    color: var(--mint-dark);
  }
  .online-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background-color: var(--mint);
  }

  /* User Status KPI Cards Grid */
  .status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
  }
  .status-kpi-card {
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
  .status-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--navy);
  }
  .status-kpi-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
  }
  .status-kpi-title {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--ink-soft);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .status-kpi-icon {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
  }
  .status-kpi-value {
    font-size: 24px;
    font-weight: 800;
    font-family: var(--font-mono);
    color: var(--navy-deep);
    line-height: 1.1;
  }
  .status-kpi-desc {
    font-size: 11px;
    color: var(--ink-soft);
    margin-top: 4px;
  }
</style>

@if($openComplaints > 0)
  <div class="alert-banner">
    <div style="display: flex; align-items: center; gap: 10px;">
      <span style="font-size: 18px;">⚠️</span>
      <div>
        <strong style="color: #92400E; font-size: 13px;">Attention Required: </strong>
        <span style="color: #B45309; font-size: 13px;">There are {{ $openComplaints }} unresolved customer dispute tickets in the queue.</span>
      </div>
    </div>
    @if($admin->canManageComplaints())
      <a href="{{ route('admin.complaints') }}" style="background-color: #92400E; color: #FFFFFF; padding: 6px 12px; border-radius: 8px; text-decoration: none; font-size: 12px; font-weight: 700;">
        Review Tickets &rarr;
      </a>
    @endif
  </div>
@endif

<!-- KPI Cards Grid -->
<div class="kpi-grid">
  <!-- Card 1: Total Customer Float -->
  <div class="kpi-card">
    <div class="kpi-header">
      <span class="kpi-label">Total Customer Float</span>
      <div class="kpi-icon" style="background-color: #EFF6FF; color: #2563EB;">💰</div>
    </div>
    <div class="kpi-value">₦{{ number_format($totalWalletPool, 2) }}</div>
    <div class="kpi-sub">Aggregate ledger balance across all users</div>
  </div>

  <!-- Card 2: Today's Volume -->
  <div class="kpi-card">
    <div class="kpi-header">
      <span class="kpi-label">Today's Volume</span>
      <div class="kpi-icon" style="background-color: var(--mint-bg); color: var(--mint-dark);">📈</div>
    </div>
    <div class="kpi-value">₦{{ number_format($todayVolume, 2) }}</div>
    <div>
      <span class="badge badge-success">All-time: ₦{{ number_format($totalVolume, 2) }}</span>
    </div>
  </div>

  <!-- Card 3: Registered Customers -->
  <div class="kpi-card">
    <div class="kpi-header">
      <span class="kpi-label">Registered Customers</span>
      <div class="kpi-icon" style="background-color: #F5F3FF; color: #7C3AED;">👥</div>
    </div>
    <div class="kpi-value">{{ number_format($totalUsers) }}</div>
    <div class="kpi-sub">
      <strong style="color: var(--mint-dark);">{{ $verifiedUsers }}</strong> verified ({{ $pendingKyc }} pending review)
    </div>
  </div>

  <!-- Card 4: Success Rate -->
  <div class="kpi-card">
    <div class="kpi-header">
      <span class="kpi-label">Gateway Success Rate</span>
      <div class="kpi-icon" style="background-color: #ECFDF5; color: #059669;">✅</div>
    </div>
    <div class="kpi-value">{{ $successRate }}%</div>
    <div class="kpi-sub">{{ $successfulTransactionsCount }} settled / {{ $failedTransactionsCount }} failed</div>
  </div>
</div>

<!-- User Account Governance & Security Status Cards Grid -->
<div style="display: flex; align-items: center; justify-content: space-between; margin: 28px 0 14px 0;">
  <div>
    <h2 style="font-size: 16px; font-weight: 800; color: var(--navy-deep);">User Account Governance & Status</h2>
    <p style="font-size: 12px; color: var(--ink-soft);">Real-time customer status breakdown, login activity, and security controls</p>
  </div>
  @if($admin->canManageKyc() || $admin->role === 'support_lead')
    <a href="{{ route('admin.users') }}" style="display: flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px; background-color: var(--surface); border: 1px solid var(--line); color: var(--navy); font-size: 12.5px; font-weight: 700; text-decoration: none;">
      <span>👥 Manage All Users &rarr;</span>
    </a>
  @endif
</div>

<div class="status-grid">
  <!-- Active Users -->
  <a href="{{ route('admin.users', ['filter' => 'active']) }}" class="status-kpi-card" title="Click to view Active Users">
    <div class="status-kpi-top">
      <span class="status-kpi-title">Active Users</span>
      <div class="status-kpi-icon" style="background-color: #E8FBF4; color: #0FA97F;">🟢</div>
    </div>
    <div class="status-kpi-value">{{ number_format($activeUsers ?? 0) }}</div>
    <div class="status-kpi-desc">Can log in & perform transactions</div>
  </a>

  <!-- Logged In Today -->
  <a href="{{ route('admin.users', ['filter' => 'today']) }}" class="status-kpi-card" title="Click to view Logged In Today">
    <div class="status-kpi-top">
      <span class="status-kpi-title">Logged In Today</span>
      <div class="status-kpi-icon" style="background-color: #EFF6FF; color: #2563EB;">⚡</div>
    </div>
    <div class="status-kpi-value">{{ number_format($loggedInToday ?? 0) }}</div>
    <div class="status-kpi-desc">Active logins within last 24h</div>
  </a>

  <!-- Pending KYC -->
  <a href="{{ route('admin.users', ['filter' => 'pending_kyc']) }}" class="status-kpi-card" title="Click to view Pending KYC Users">
    <div class="status-kpi-top">
      <span class="status-kpi-title">Pending KYC</span>
      <div class="status-kpi-icon" style="background-color: #FEF3C7; color: #D97706;">⏳</div>
    </div>
    <div class="status-kpi-value">{{ number_format($pendingKyc ?? 0) }}</div>
    <div class="status-kpi-desc">Awaiting tier limit review</div>
  </a>

  <!-- Frozen Users (Post-No-Debit) -->
  <a href="{{ route('admin.users', ['filter' => 'frozen']) }}" class="status-kpi-card" title="Click to view Frozen Users">
    <div class="status-kpi-top">
      <span class="status-kpi-title">Frozen Users</span>
      <div class="status-kpi-icon" style="background-color: #E0F2FE; color: #0284C7;">❄️</div>
    </div>
    <div class="status-kpi-value">{{ number_format($frozenUsers ?? 0) }}</div>
    <div class="status-kpi-desc">Can login; debits & transactions blocked</div>
  </a>

  <!-- Suspended / Blocked -->
  <a href="{{ route('admin.users', ['filter' => 'suspended']) }}" class="status-kpi-card" title="Click to view Suspended & Blocked Users">
    <div class="status-kpi-top">
      <span class="status-kpi-title">Suspended / Blocked</span>
      <div class="status-kpi-icon" style="background-color: #FFE4E6; color: #E11D48;">🚫</div>
    </div>
    <div class="status-kpi-value">{{ number_format($suspendedUsers ?? 0) }}</div>
    <div class="status-kpi-desc">Login blocked & sessions killed</div>
  </a>

  <!-- Total Accounts -->
  <a href="{{ route('admin.users', ['filter' => 'all']) }}" class="status-kpi-card" title="Click to view All Registered Customers">
    <div class="status-kpi-top">
      <span class="status-kpi-title">Total Customers</span>
      <div class="status-kpi-icon" style="background-color: #EEF2FB; color: var(--navy);">👥</div>
    </div>
    <div class="status-kpi-value">{{ number_format($totalUsers ?? 0) }}</div>
    <div class="status-kpi-desc">All registered accounts in ledger</div>
  </a>
</div>

<!-- Financial Channels Breakdown -->
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
  <div>
    <h2 style="font-size: 16px; font-weight: 800; color: var(--navy-deep);">Financial Rails Breakdown</h2>
    <p style="font-size: 12px; color: var(--ink-soft);">Real-time settlement distribution across payment channels</p>
  </div>
  @if($admin->canManageAppControls())
    <a href="{{ route('admin.app_controls') }}" style="display: flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px; background-color: var(--surface); border: 1px solid var(--line); color: var(--navy); font-size: 12.5px; font-weight: 700; text-decoration: none;">
      <span>🎛️ Configure Channels</span>
    </a>
  @endif
</div>

<div class="channels-grid">
  <!-- Channel 1: Airtime & Data -->
  <div class="channel-card">
    <div class="channel-top">
      <div class="channel-icon" style="background-color: #FEF3C7;">📱</div>
      <div>
        <div class="channel-name">Airtime & Mobile Data</div>
        <div class="channel-provider">Provider: {{ ($vasProvider ?? 'bigisub') === 'bigisub' ? 'Bigisub.ng Live Engine' : 'VTpass Live Engine' }}</div>
      </div>
    </div>
    <div class="channel-amount">₦{{ number_format($billsVolume, 2) }}</div>
    <div class="channel-status">
      <span class="online-dot"></span> Active Live
    </div>
  </div>

  <!-- Channel 2: Bank Transfers -->
  <div class="channel-card">
    <div class="channel-top">
      <div class="channel-icon" style="background-color: #EFF6FF;">🏦</div>
      <div>
        <div class="channel-name">NIBSS Bank Transfers</div>
        <div class="channel-provider">Provider: Direct Instant Rails</div>
      </div>
    </div>
    <div class="channel-amount">₦{{ number_format($transfersVolume, 2) }}</div>
    <div class="channel-status">
      <span class="online-dot"></span> Active Live
    </div>
  </div>

  <!-- Channel 3: Crypto Settlements -->
  <div class="channel-card">
    <div class="channel-top">
      <div class="channel-icon" style="background-color: #FDF4FF;">₿</div>
      <div>
        <div class="channel-name">Crypto Deposits & Swaps</div>
        <div class="channel-provider">Provider: Quidax Blockchain Vault</div>
      </div>
    </div>
    <div class="channel-amount">₦{{ number_format($cryptoVolume, 2) }}</div>
    <div class="channel-status">
      <span class="online-dot"></span> Active Live
    </div>
  </div>
</div>

<!-- Recent Transactions Table Card -->
<div class="card">
  <div class="card-header">
    <div>
      <h3 style="font-size: 15px; font-weight: 800; color: var(--navy-deep);">Recent Operations & Transactions</h3>
      <p style="font-size: 12px; color: var(--ink-soft);">Live audit stream from mobile app users</p>
    </div>
    <a href="{{ route('admin.transactions') }}" style="color: var(--navy); font-size: 12.5px; font-weight: 700; text-decoration: none;">
      View Full Ledger &rarr;
    </a>
  </div>

  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Reference</th>
          <th>Customer</th>
          <th>Channel / Service</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Time</th>
        </tr>
      </thead>
      <tbody>
        @forelse($recentTransactions as $tx)
          <tr>
            <td><span class="mono">{{ $tx->reference }}</span></td>
            <td>
              <div style="font-weight: 700; font-size: 13px;">
                {{ $tx->user ? $tx->user->first_name . ' ' . $tx->user->last_name : 'Unknown User' }}
              </div>
              <div style="font-size: 11px; color: var(--ink-soft);">{{ $tx->user?->email ?? $tx->user?->phone }}</div>
            </td>
            <td>
              <span style="font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 5px; background-color: var(--chip); color: var(--navy);">
                {{ strtoupper(str_replace('_', ' ', $tx->type)) }}
              </span>
            </td>
            <td style="font-weight: 700; font-family: var(--font-mono);">
              ₦{{ number_format($tx->amount, 2) }}
            </td>
            <td>
              <span class="badge {{ $tx->status === 'successful' ? 'badge-success' : ($tx->status === 'failed' ? 'badge-danger' : 'badge-warning') }}">
                {{ strtoupper($tx->status) }}
              </span>
            </td>
            <td style="font-size: 12px; color: var(--ink-soft);">
              {{ $tx->created_at->format('h:i A') }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="6" style="padding: 40px; text-align: center; color: var(--ink-soft);">
              No transactions recorded yet.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
