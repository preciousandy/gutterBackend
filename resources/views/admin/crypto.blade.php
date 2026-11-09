@extends('admin.layout')

@section('title', 'Crypto & Quidax Command | Gutter Command')
@section('page_title', 'Crypto Wallets, Quidax Exchange & Refund Governance')
@section('page_sub', 'Manage crypto deposits, withdrawals, NGN conversions, fee rules, Quidax webhooks, and audited customer refunds')

@section('content')
<style>
  .crypto-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(215px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
  }
  .crypto-kpi-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 16px 18px;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .crypto-kpi-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .crypto-kpi-label {
    font-size: 11px;
    font-weight: 800;
    color: var(--ink-soft);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .crypto-kpi-val {
    font-size: 21px;
    font-weight: 800;
    color: var(--navy-deep);
    font-family: var(--font-mono);
  }
  .crypto-kpi-meta {
    font-size: 11.5px;
    color: var(--ink-soft);
  }
  .tabs-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--line);
    padding-bottom: 12px;
  }
  .tab-links {
    display: flex;
    gap: 8px;
  }
  .tab-pill {
    padding: 9px 18px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    color: var(--ink-mid);
    background-color: var(--surface);
    border: 1px solid var(--line);
    transition: all 0.15s ease;
  }
  .tab-pill.active {
    background-color: var(--navy-deep);
    color: #FFFFFF;
    border-color: var(--navy-deep);
  }
  .filter-bar {
    display: grid;
    grid-template-columns: 2fr repeat(4, 1fr) auto;
    gap: 10px;
    padding: 16px 20px;
    background-color: var(--surface-alt);
    border-bottom: 1px solid var(--line);
  }
  @media (max-width: 1100px) {
    .filter-bar {
      grid-template-columns: 1fr 1fr;
    }
  }
  .form-input, .form-select {
    width: 100%;
    padding: 9px 12px;
    border-radius: 9px;
    border: 1px solid var(--line-active);
    background-color: var(--surface);
    font-size: 12.5px;
    font-family: inherit;
    color: var(--ink);
  }
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 9px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid transparent;
    text-decoration: none;
    font-family: inherit;
    transition: all 0.15s ease;
  }
  .btn-navy { background-color: var(--navy-deep); color: #FFFFFF; }
  .btn-mint { background-color: var(--mint); color: var(--mint-on); }
  .btn-danger { background-color: var(--red-bg); color: var(--red); border-color: #FECDD3; }
  .btn-warning { background-color: var(--amber-bg); color: var(--amber); border-color: #FDE68A; }
  .btn-outline { background-color: var(--surface); color: var(--navy-deep); border-color: var(--line-active); }
  .fee-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(460px, 1fr));
    gap: 20px;
    margin-bottom: 28px;
  }
  @media (max-width: 768px) {
    .fee-grid { grid-template-columns: 1fr; }
  }
  .field-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .field-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--ink-soft);
    text-transform: uppercase;
  }
</style>

<!-- 8 Executive Crypto KPIs -->
<div class="crypto-kpi-grid">
  <div class="crypto-kpi-card">
    <div class="crypto-kpi-top">
      <span class="crypto-kpi-label">Total Crypto Deposits</span>
      <span>📥</span>
    </div>
    <div class="crypto-kpi-val">₦{{ number_format($cryptoMetrics['total_deposits_ngn'] ?? 0, 2) }}</div>
    <div class="crypto-kpi-meta">{{ number_format($cryptoMetrics['total_deposits_count'] ?? 0) }} confirmed blockchain deposits</div>
  </div>

  <div class="crypto-kpi-card">
    <div class="crypto-kpi-top">
      <span class="crypto-kpi-label">Total Crypto Withdrawals</span>
      <span>📤</span>
    </div>
    <div class="crypto-kpi-val">₦{{ number_format($cryptoMetrics['total_withdrawals_ngn'] ?? 0, 2) }}</div>
    <div class="crypto-kpi-meta">{{ number_format($cryptoMetrics['total_withdrawals_count'] ?? 0) }} completed crypto sends</div>
  </div>

  <div class="crypto-kpi-card">
    <div class="crypto-kpi-top">
      <span class="crypto-kpi-label">Total Crypto Purchases</span>
      <span>🛒</span>
    </div>
    <div class="crypto-kpi-val">₦{{ number_format($cryptoMetrics['total_purchases_ngn'] ?? 0, 2) }}</div>
    <div class="crypto-kpi-meta">{{ number_format($cryptoMetrics['total_purchases_count'] ?? 0) }} Naira-to-Crypto orders</div>
  </div>

  <div class="crypto-kpi-card">
    <div class="crypto-kpi-top">
      <span class="crypto-kpi-label">Crypto-to-Naira Swaps</span>
      <span>🔄</span>
    </div>
    <div class="crypto-kpi-val">₦{{ number_format($cryptoMetrics['total_conversions_ngn'] ?? 0, 2) }}</div>
    <div class="crypto-kpi-meta">{{ number_format($cryptoMetrics['total_conversions_count'] ?? 0) }} instant conversions</div>
  </div>

  <div class="crypto-kpi-card">
    <div class="crypto-kpi-top">
      <span class="crypto-kpi-label">Platform Fees Earned</span>
      <span>💎</span>
    </div>
    <div class="crypto-kpi-val" style="color: var(--mint-dark);">₦{{ number_format($cryptoMetrics['total_platform_fees_ngn'] ?? 0, 2) }}</div>
    <div class="crypto-kpi-meta">Net revenue from crypto operations</div>
  </div>

  <div class="crypto-kpi-card">
    <div class="crypto-kpi-top">
      <span class="crypto-kpi-label">Pending / Processing</span>
      <span>⏳</span>
    </div>
    <div class="crypto-kpi-val" style="color: var(--amber);">{{ number_format($cryptoMetrics['pending_transactions_count'] ?? 0) }}</div>
    <div class="crypto-kpi-meta">Awaiting confirmation or approval</div>
  </div>

  <div class="crypto-kpi-card">
    <div class="crypto-kpi-top">
      <span class="crypto-kpi-label">Failed / Rejected</span>
      <span>⚠️</span>
    </div>
    <div class="crypto-kpi-val" style="color: var(--red);">{{ number_format($cryptoMetrics['failed_transactions_count'] ?? 0) }}</div>
    <div class="crypto-kpi-meta">Eligible for admin refund review</div>
  </div>

  <div class="crypto-kpi-card">
    <div class="crypto-kpi-top">
      <span class="crypto-kpi-label">Refund Totals</span>
      <span>↩️</span>
    </div>
    <div class="crypto-kpi-val">₦{{ number_format($cryptoMetrics['refund_totals_ngn'] ?? 0, 2) }}</div>
    <div class="crypto-kpi-meta">{{ number_format($cryptoMetrics['refund_totals_count'] ?? 0) }} audited refunds completed</div>
  </div>
</div>

<!-- Navigation Tabs & Reconcile Action -->
<div class="tabs-bar">
  <div class="tab-links">
    <a href="{{ route('admin.crypto', ['tab' => 'transactions']) }}" class="tab-pill {{ $activeTab !== 'settings' ? 'active' : '' }}">
      📋 Crypto Transactions & Refund Center
    </a>
    <a href="{{ route('admin.crypto', ['tab' => 'settings']) }}" class="tab-pill {{ $activeTab === 'settings' ? 'active' : '' }}">
      ⚙️ Crypto Fee Settings, Coins & Quidax API
    </a>
  </div>

  <div style="display: flex; align-items: center; gap: 10px;">
    <form action="{{ route('admin.crypto.reconcile') }}" method="POST" style="margin: 0;">
      @csrf
      <button type="submit" class="btn btn-outline" title="Sync pending withdrawals and swaps with Quidax API">
        🔄 Reconcile Pending with Quidax
      </button>
    </form>
  </div>
</div>

@if($activeTab === 'settings')
  <!-- TAB 2: CRYPTO FEE MANAGEMENT, SUPPORTED COINS & QUIDAX GATEWAY -->
  <div style="margin-bottom: 18px;">
    <h2 style="font-size: 16px; font-weight: 800; color: var(--navy-deep);">Independent Crypto Fee & Limit Configuration</h2>
    <p style="font-size: 12.5px; color: var(--ink-soft);">
      Configure percentage fees, fixed fees, transaction limits, withdrawal thresholds, and maintenance switches independently for each crypto service. Historical transactions preserve their exact fee snapshot at the time of execution.
    </p>
  </div>

  <div class="fee-grid">
    @foreach($feeSettings as $fee)
      <div class="card">
        <div class="card-header" style="background-color: var(--surface-alt);">
          <div>
            <div style="display: flex; align-items: center; gap: 8px;">
              <h3 style="font-size: 14.5px; font-weight: 800; color: var(--navy-deep);">{{ $fee->label }}</h3>
              <span class="mono">{{ $fee->service_type }}</span>
            </div>
            <p style="font-size: 11.5px; color: var(--ink-soft); margin-top: 2px;">Last updated by: {{ $fee->updated_by ?? 'System' }}</p>
          </div>
          <div>
            @if($fee->maintenance_mode)
              <span class="badge badge-warning">MAINTENANCE</span>
            @elseif($fee->is_enabled)
              <span class="badge badge-success">ENABLED</span>
            @else
              <span class="badge badge-danger">DISABLED</span>
            @endif
          </div>
        </div>

        <form action="{{ route('admin.crypto.settings.update', $fee->service_type) }}" method="POST" style="padding: 20px;">
          @csrf
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 14px;">
            <div class="field-group">
              <label class="field-label">Percentage Fee (%)</label>
              <input type="number" step="0.0001" min="0" max="100" name="percentage_fee" value="{{ (float) $fee->percentage_fee }}" class="form-input" required>
            </div>
            <div class="field-group">
              <label class="field-label">Fixed Fee (₦ NGN)</label>
              <input type="number" step="0.01" min="0" name="fixed_fee_ngn" value="{{ (float) $fee->fixed_fee_ngn }}" class="form-input" required>
            </div>
            <div class="field-group">
              <label class="field-label">Fixed Fee (Crypto)</label>
              <input type="number" step="0.00000001" min="0" name="fixed_fee_crypto" value="{{ (float) $fee->fixed_fee_crypto }}" class="form-input">
            </div>
          </div>

          <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 14px;">
            <div class="field-group">
              <label class="field-label">Min Transaction Amount</label>
              <input type="number" step="0.00000001" min="0" name="min_amount" value="{{ (float) $fee->min_amount }}" class="form-input" required>
            </div>
            <div class="field-group">
              <label class="field-label">Max Transaction Amount</label>
              <input type="number" step="0.01" min="0" name="max_amount" value="{{ (float) $fee->max_amount }}" class="form-input" required>
            </div>
          </div>

          @if($fee->service_type === 'send_crypto')
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 14px; padding: 12px; background-color: var(--chip); border-radius: 10px;">
              <div class="field-group">
                <label class="field-label">Min Withdrawal</label>
                <input type="number" step="0.00000001" min="0" name="min_withdrawal_amount" value="{{ (float) $fee->min_withdrawal_amount }}" class="form-input">
              </div>
              <div class="field-group">
                <label class="field-label">Max Withdrawal</label>
                <input type="number" step="0.01" min="0" name="max_withdrawal_amount" value="{{ (float) $fee->max_withdrawal_amount }}" class="form-input">
              </div>
              <div class="field-group">
                <label class="field-label">Manual Approval Threshold</label>
                <input type="number" step="0.01" min="0" name="manual_approval_threshold" value="{{ (float) $fee->manual_approval_threshold }}" class="form-input">
              </div>
            </div>
          @endif

          <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 12px; padding: 10px 12px; background-color: var(--surface-alt); border-radius: 9px; border: 1px solid var(--line);">
            <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 700; cursor: pointer;">
              <input type="checkbox" name="is_enabled" value="1" {{ $fee->is_enabled ? 'checked' : '' }}>
              <span>Service Enabled</span>
            </label>

            <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 700; color: var(--amber); cursor: pointer;">
              <input type="checkbox" name="maintenance_mode" value="1" {{ $fee->maintenance_mode ? 'checked' : '' }}>
              <span>Maintenance Mode</span>
            </label>
          </div>

          <div class="field-group" style="margin-bottom: 16px;">
            <label class="field-label">Maintenance Banner Message</label>
            <input type="text" name="maintenance_message" value="{{ $fee->maintenance_message }}" placeholder="Displayed to users when maintenance mode is active..." class="form-input">
          </div>

          <button type="submit" class="btn btn-navy" style="width: 100%;">
            💾 Save {{ $fee->label }} Settings
          </button>
        </form>
      </div>
    @endforeach
  </div>

  <!-- Supported Coins & Networks Governance -->
  @php
    $masterCoinSetting = $feeSettings->first();
    $supportedCoins = $masterCoinSetting ? ($masterCoinSetting->supported_coins ?? []) : [];
  @endphp
  <div class="card" style="margin-bottom: 28px;">
    <div class="card-header">
      <div>
        <h3 style="font-size: 15px; font-weight: 800; color: var(--navy-deep);">Supported Cryptocurrencies & Blockchain Networks</h3>
        <p style="font-size: 12px; color: var(--ink-soft);">Enable or disable individual coins and blockchain networks (`TRC20`, `BEP20`, `ERC20`, `BTC`, `SOL`) and configure network withdrawal fees</p>
      </div>
    </div>

    <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px;">
      @foreach($supportedCoins as $symbol => $coinConfig)
        <form action="{{ route('admin.crypto.coins.update') }}" method="POST" style="border: 1px solid var(--line); border-radius: 12px; padding: 16px; background-color: var(--surface-alt);">
          @csrf
          <input type="hidden" name="symbol" value="{{ $symbol }}">
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--line);">
            <div style="display: flex; align-items: center; gap: 10px;">
              <span class="mono" style="font-size: 14px; font-weight: 800;">{{ $symbol }}</span>
              <span style="font-size: 13px; font-weight: 700; color: var(--navy-deep);">{{ $coinConfig['name'] ?? $symbol }}</span>
            </div>
            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
              <input type="checkbox" name="coin_enabled" value="1" {{ ($coinConfig['enabled'] ?? true) ? 'checked' : '' }}>
              <span>Asset Enabled</span>
            </label>
          </div>

          @foreach(($coinConfig['networks'] ?? []) as $netKey => $netData)
            <div style="background: var(--surface); border: 1px solid var(--line); border-radius: 9px; padding: 10px 12px; margin-bottom: 10px;">
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 12px; font-weight: 800; color: var(--navy);">{{ $netData['name'] ?? $netKey }} ({{ $netKey }})</span>
                <label style="display: flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 700;">
                  <input type="checkbox" name="networks[{{ $netKey }}][enabled]" value="1" {{ ($netData['enabled'] ?? true) ? 'checked' : '' }}>
                  <span>Active</span>
                </label>
              </div>
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                <div class="field-group">
                  <label class="field-label">Network Fee ({{ $symbol }})</label>
                  <input type="number" step="0.00000001" min="0" name="networks[{{ $netKey }}][network_fee]" value="{{ $netData['network_fee'] ?? 0 }}" class="form-input">
                </div>
                <div class="field-group">
                  <label class="field-label">Min Deposit ({{ $symbol }})</label>
                  <input type="number" step="0.00000001" min="0" name="networks[{{ $netKey }}][min_deposit]" value="{{ $netData['min_deposit'] ?? 0 }}" class="form-input">
                </div>
              </div>
            </div>
          @endforeach

          <button type="submit" class="btn btn-outline" style="width: 100%; margin-top: 4px;">
            Update {{ $symbol }} Networks
          </button>
        </form>
      @endforeach
    </div>
  </div>

  <!-- Quidax API Gateway Configuration -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 style="font-size: 15px; font-weight: 800; color: var(--navy-deep);">Quidax API Gateway & Webhook Configuration</h3>
        <p style="font-size: 12px; color: var(--ink-soft);">Official Quidax API v1/v3 integration credentials & webhook verification settings (`https://docs.quidax.io/`)</p>
      </div>
      <div style="display: flex; align-items: center; gap: 10px;">
        <span class="badge {{ $quidaxSecretSet ? 'badge-success' : 'badge-warning' }}">
          {{ $quidaxSecretSet ? 'LIVE CREDENTIALS CONFIGURED' : 'SANDBOX / FALLBACK MODE' }}
        </span>
        <button type="button" class="btn btn-mint" onclick="testQuidaxConnection()">
          ⚡ Test Quidax Connection
        </button>
      </div>
    </div>

    <form action="{{ route('admin.crypto.quidax.update') }}" method="POST" style="padding: 20px;">
      @csrf
      <div id="quidaxTestAlert" style="display: none; margin-bottom: 16px; padding: 12px 16px; border-radius: 10px; font-size: 12.5px; font-weight: 700;"></div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 16px;">
        <div class="field-group">
          <label class="field-label">Quidax API Base URL</label>
          <input type="url" name="quidax_base_url" value="{{ $quidaxBaseUrl }}" class="form-input" required>
        </div>
        <div class="field-group">
          <label class="field-label">Quidax Secret Key (Leave blank to keep existing)</label>
          <input type="password" name="quidax_secret_key" placeholder="qdx_sec_************************" class="form-input">
        </div>
        <div class="field-group">
          <label class="field-label">Quidax Webhook Secret (quidax-signature HMAC key)</label>
          <input type="password" name="quidax_webhook_secret" placeholder="whsec_************************" class="form-input">
        </div>
      </div>

      <div style="padding: 12px 16px; background-color: var(--chip); border-radius: 10px; margin-bottom: 16px; font-size: 12px; color: var(--navy-deep);">
        <strong>Webhook Endpoint URL:</strong>
        <code class="mono">{{ url('/api/v1/crypto/quidax/webhook') }}</code>
        <span style="margin-left: 10px; color: var(--ink-soft);">Configure this URL in your Quidax Dashboard &rarr; Developer Settings &rarr; Webhooks.</span>
      </div>

      <button type="submit" class="btn btn-navy">
        🔐 Save Quidax Gateway Settings
      </button>
    </form>
  </div>

@else
  <!-- TAB 1: CRYPTO TRANSACTIONS MANAGEMENT & REFUND CENTER -->
  <div class="card" style="margin-bottom: 28px;">
    <div class="card-header">
      <div>
        <h3 style="font-size: 15px; font-weight: 800; color: var(--navy-deep);">Crypto Transactions Ledger & Refund Governance</h3>
        <p style="font-size: 12px; color: var(--ink-soft);">Search by username, email, reference, Quidax ID, or wallet address. Inspect full API payloads, approve/reject requests, or issue audited refunds.</p>
      </div>
    </div>

    <!-- Search & Filter Form -->
    <form action="{{ route('admin.crypto') }}" method="GET" class="filter-bar">
      <input type="hidden" name="tab" value="transactions">
      <input
        type="text"
        name="search"
        value="{{ request('search') }}"
        placeholder="Search username, email, reference, Quidax ID, or wallet address..."
        class="form-input"
      >

      <select name="type" class="form-select">
        <option value="all">All Transaction Types</option>
        <option value="receive_crypto" {{ request('type') === 'receive_crypto' ? 'selected' : '' }}>1. Receive Crypto (Deposit)</option>
        <option value="send_crypto" {{ request('type') === 'send_crypto' ? 'selected' : '' }}>2. Send Crypto (Withdrawal)</option>
        <option value="convert_to_naira" {{ request('type') === 'convert_to_naira' ? 'selected' : '' }}>3. Convert Crypto to Naira</option>
        <option value="buy_crypto" {{ request('type') === 'buy_crypto' ? 'selected' : '' }}>4. Buy Crypto</option>
      </select>

      <select name="symbol" class="form-select">
        <option value="all">All Cryptocurrencies</option>
        @foreach(['USDT', 'BTC', 'ETH', 'SOL', 'BNB'] as $sym)
          <option value="{{ $sym }}" {{ strtoupper((string) request('symbol')) === $sym ? 'selected' : '' }}>{{ $sym }}</option>
        @endforeach
      </select>

      <select name="network" class="form-select">
        <option value="all">All Networks</option>
        @foreach(['TRC20', 'BEP20', 'ERC20', 'BTC', 'SOL', 'INTERNAL'] as $net)
          <option value="{{ $net }}" {{ strtoupper((string) request('network')) === $net ? 'selected' : '' }}>{{ $net }}</option>
        @endforeach
      </select>

      <select name="status" class="form-select">
        <option value="all">All Statuses</option>
        @foreach(['pending', 'processing', 'successful', 'failed', 'rejected', 'refunded'] as $st)
          <option value="{{ $st }}" {{ strtolower((string) request('status')) === $st ? 'selected' : '' }}>{{ ucfirst($st) }}</option>
        @endforeach
      </select>

      <div style="display: flex; gap: 6px;">
        <button type="submit" class="btn btn-navy">Filter</button>
        <a href="{{ route('admin.crypto') }}" class="btn btn-outline">Reset</a>
      </div>
    </form>

    <!-- Transactions Table -->
    <div class="table-container">
      <table>
        <thead>
          <tr>
            <th>Reference / Quidax ID</th>
            <th>Customer</th>
            <th>Type</th>
            <th>Asset & Network</th>
            <th>Crypto & NGN Value</th>
            <th>Rate & Fees</th>
            <th>Status</th>
            <th>Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($cryptoTransactions as $ctx)
            @php
              $statusBadge = match($ctx->status) {
                'successful' => 'badge-success',
                'failed', 'rejected' => 'badge-danger',
                'refunded' => 'badge-info',
                default => 'badge-warning',
              };
              $typeLabel = match($ctx->transaction_type) {
                'receive_crypto' => 'Receive Crypto',
                'send_crypto' => 'Send Crypto',
                'convert_to_naira' => 'Convert to NGN',
                'buy_crypto' => 'Buy Crypto',
                default => $ctx->transaction_type,
              };
              $isRefundable = $ctx->isEligibleForRefund();
              $refundAsset = $ctx->transaction_type === 'buy_crypto' ? 'NGN' : $ctx->symbol;
              $refundAmount = $ctx->transaction_type === 'buy_crypto'
                ? '₦' . number_format((float) $ctx->fiat_amount, 2)
                : rtrim(rtrim(number_format((float) ($ctx->transaction_type === 'send_crypto' ? $ctx->net_crypto_amount : $ctx->crypto_amount), 8, '.', ''), '0'), '.') . ' ' . $ctx->symbol;
            @endphp
            <tr>
              <td>
                <div><span class="mono">{{ $ctx->reference }}</span></div>
                @if($ctx->quidax_reference)
                  <div style="font-size: 11px; color: var(--ink-soft); margin-top: 2px;">QDX: {{ $ctx->quidax_reference }}</div>
                @endif
                @if($ctx->wallet_address)
                  <div style="font-size: 10.5px; color: var(--ink-soft); margin-top: 2px;" title="{{ $ctx->wallet_address }}">
                    Addr: {{ \Illuminate\Support\Str::limit($ctx->wallet_address, 18) }}
                  </div>
                @endif
              </td>
              <td>
                <div style="font-weight: 700; font-size: 13px;">
                  {{ $ctx->user ? ($ctx->user->first_name . ' ' . $ctx->user->last_name) : 'User #' . $ctx->user_id }}
                </div>
                <div style="font-size: 11px; color: var(--ink-soft);">{{ $ctx->user?->email }}</div>
              </td>
              <td>
                <span style="font-size: 11px; font-weight: 800; padding: 3px 8px; border-radius: 6px; background-color: var(--chip); color: var(--navy);">
                  {{ $typeLabel }}
                </span>
              </td>
              <td>
                <div style="font-weight: 800; color: var(--navy-deep);">{{ $ctx->symbol }}</div>
                @if($ctx->network)
                  <span style="font-size: 10.5px; color: var(--ink-soft); font-weight: 700;">Net: {{ $ctx->network }}</span>
                @endif
              </td>
              <td style="font-family: var(--font-mono);">
                <div style="font-weight: 700;">{{ rtrim(rtrim(number_format((float) $ctx->crypto_amount, 8, '.', ''), '0'), '.') }} {{ $ctx->symbol }}</div>
                <div style="font-size: 11.5px; color: var(--ink-soft);">₦{{ number_format((float) $ctx->fiat_amount, 2) }}</div>
              </td>
              <td style="font-family: var(--font-mono); font-size: 11.5px;">
                <div>Rate: ₦{{ number_format((float) $ctx->exchange_rate, 2) }}</div>
                <div style="color: var(--ink-soft);">
                  Fee: ₦{{ number_format((float) $ctx->platform_fee_ngn, 2) }}
                  @if((float) $ctx->network_fee_crypto > 0)
                    (+{{ rtrim(rtrim(number_format((float) $ctx->network_fee_crypto, 6, '.', ''), '0'), '.') }} {{ $ctx->symbol }} net)
                  @endif
                </div>
              </td>
              <td>
                <span class="badge {{ $statusBadge }}">{{ strtoupper($ctx->status) }}</span>
              </td>
              <td style="font-size: 11.5px; color: var(--ink-soft);">
                <div>{{ $ctx->created_at?->format('M d, Y') }}</div>
                <div>{{ $ctx->created_at?->format('h:i A') }}</div>
              </td>
              <td>
                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                  <button
                    type="button"
                    class="btn btn-outline"
                    onclick='openTxDetailsModal(@json($ctx))'
                  >
                    🔍 Details
                  </button>

                  @if(in_array($ctx->status, ['pending', 'processing']))
                    <form action="{{ route('admin.crypto.tx.status', $ctx->id) }}" method="POST" style="margin: 0;">
                      @csrf
                      <input type="hidden" name="status" value="successful">
                      <input type="hidden" name="reason" value="Manually approved by Admin">
                      <button type="submit" class="btn btn-mint" title="Approve & Mark Successful">✓ Approve</button>
                    </form>

                    <form action="{{ route('admin.crypto.tx.status', $ctx->id) }}" method="POST" style="margin: 0;">
                      @csrf
                      <input type="hidden" name="status" value="rejected">
                      <input type="hidden" name="reason" value="Rejected by Admin during compliance review">
                      <button type="submit" class="btn btn-warning" title="Reject Request">✕ Reject</button>
                    </form>
                  @endif

                  @if($isRefundable)
                    <button
                      type="button"
                      class="btn btn-danger"
                      onclick="openRefundModal({{ $ctx->id }}, '{{ $ctx->reference }}', '{{ $typeLabel }}', '{{ $refundAmount }}', '{{ addslashes($ctx->user ? $ctx->user->first_name . ' ' . $ctx->user->last_name : 'User #' . $ctx->user_id) }}', '{{ $ctx->status }}')"
                    >
                      ↩️ Refund
                    </button>
                  @endif
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="9" style="padding: 40px; text-align: center; color: var(--ink-soft);">
                No crypto transactions matched your filter criteria.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    @if(method_exists($cryptoTransactions, 'links'))
      <div style="padding: 14px 20px; border-top: 1px solid var(--line);">
        {{ $cryptoTransactions->links() }}
      </div>
    @endif
  </div>

  <!-- Recent Refunds Audit Trail -->
  <div class="card">
    <div class="card-header">
      <div>
        <h3 style="font-size: 15px; font-weight: 800; color: var(--navy-deep);">Completed Crypto & Naira Refund Audit Trail</h3>
        <p style="font-size: 12px; color: var(--ink-soft);">Every admin-initiated refund is permanently logged with administrator identity, reason, before/after balances, and unique reference</p>
      </div>
    </div>
    <div class="table-container">
      <table>
        <thead>
          <tr>
            <th>Refund Reference</th>
            <th>Original Reference</th>
            <th>Customer</th>
            <th>Asset & Amount Refunded</th>
            <th>Balance Before &rarr; After</th>
            <th>Initiated By Admin</th>
            <th>Reason</th>
            <th>Timestamp</th>
          </tr>
        </thead>
        <tbody>
          @forelse($recentRefunds as $rf)
            <tr>
              <td><span class="mono">{{ $rf->refund_reference }}</span></td>
              <td><span class="mono">{{ $rf->cryptoTransaction?->reference ?? ('#' . $rf->crypto_transaction_id) }}</span></td>
              <td>
                <div style="font-weight: 700;">{{ $rf->user ? ($rf->user->first_name . ' ' . $rf->user->last_name) : ('User #' . $rf->user_id) }}</div>
                <div style="font-size: 11px; color: var(--ink-soft);">{{ $rf->user?->email }}</div>
              </td>
              <td style="font-family: var(--font-mono); font-weight: 800; color: var(--mint-dark);">
                @if($rf->asset_type === 'NGN' || $rf->asset_type === 'fiat')
                  ₦{{ number_format((float) $rf->refunded_amount, 2) }} (NGN)
                @else
                  {{ rtrim(rtrim(number_format((float) $rf->refunded_amount, 8, '.', ''), '0'), '.') }} {{ $rf->currency }}
                @endif
              </td>
              <td style="font-family: var(--font-mono); font-size: 11.5px;">
                {{ number_format((float) $rf->balance_before, 4) }} &rarr; <strong>{{ number_format((float) $rf->balance_after, 4) }}</strong>
              </td>
              <td>
                <span class="badge badge-info">{{ $rf->admin_name }}</span>
              </td>
              <td style="font-size: 12px; max-width: 260px;">{{ $rf->reason }}</td>
              <td style="font-size: 11.5px; color: var(--ink-soft);">{{ ($rf->completed_at ?? $rf->created_at)?->format('M d, Y h:i A') }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="8" style="padding: 32px; text-align: center; color: var(--ink-soft);">
                No crypto refunds have been issued yet.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endif

<!-- MODAL 1: TRANSACTION DETAILS & AUDIT TRAIL -->
<div class="modal-overlay" id="txDetailsModal">
  <div class="modal-content" style="max-width: 680px; max-height: 90vh; overflow-y: auto;">
    <div class="modal-header">
      <div>
        <h3 style="font-size: 16px; font-weight: 800; color: var(--navy-deep);">Crypto Transaction Inspection</h3>
        <p style="font-size: 11.5px; color: var(--ink-soft);" id="detailModalSub"></p>
      </div>
      <button type="button" onclick="closeModal('txDetailsModal')" style="background: none; border: none; font-size: 18px; cursor: pointer;">✕</button>
    </div>
    <div class="modal-body" id="txDetailsBody"></div>
  </div>
</div>

<!-- MODAL 2: ADMIN REFUND CONFIRMATION -->
<div class="modal-overlay" id="refundModal">
  <div class="modal-content" style="max-width: 520px;">
    <div class="modal-header" style="background-color: #FFF1F2;">
      <div>
        <h3 style="font-size: 16px; font-weight: 800; color: #9F1239;">↩️ Confirm Audited Customer Refund</h3>
        <p style="font-size: 11.5px; color: #BE123C;">Funds will be credited back using row-level database locking</p>
      </div>
      <button type="button" onclick="closeModal('refundModal')" style="background: none; border: none; font-size: 18px; cursor: pointer;">✕</button>
    </div>
    <form id="refundForm" method="POST" class="modal-body">
      @csrf
      <div style="background-color: var(--surface-alt); border: 1px solid var(--line); border-radius: 12px; padding: 14px; font-size: 12.5px; display: flex; flex-direction: column; gap: 8px;">
        <div style="display: flex; justify-content: space-between;">
          <span style="color: var(--ink-soft);">Original Reference:</span>
          <strong class="mono" id="refundRefDisplay"></strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span style="color: var(--ink-soft);">Customer:</span>
          <strong id="refundCustomerDisplay"></strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span style="color: var(--ink-soft);">Transaction Type / Status:</span>
          <strong id="refundTypeDisplay"></strong>
        </div>
        <div style="display: flex; justify-content: space-between; padding-top: 6px; border-top: 1px dashed var(--line);">
          <span style="color: var(--ink-soft); font-weight: 700;">Exact Refundable Amount:</span>
          <strong style="font-size: 15px; color: var(--mint-dark); font-family: var(--font-mono);" id="refundAmountDisplay"></strong>
        </div>
      </div>

      <div class="field-group">
        <label class="field-label">Mandatory Refund Reason (Recorded in Audit Log & User Receipt)</label>
        <textarea
          name="reason"
          rows="3"
          required
          minlength="5"
          placeholder="Explain why this transaction is being refunded (e.g., Failed external withdrawal on Quidax, rejected compliance check)..."
          class="form-input"
        ></textarea>
      </div>

      <div style="font-size: 11.5px; color: var(--ink-soft); background-color: #FEF3C7; color: #92400E; padding: 10px 12px; border-radius: 8px;">
        <strong>Safety Policy:</strong> Completed blockchain withdrawals cannot be auto-refunded. The backend will verify that this transaction has not already been refunded and that funds are still held before crediting the user.
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('refundModal')">Cancel</button>
        <button type="submit" class="btn btn-navy" style="background-color: #E11D48;">Confirm & Execute Refund</button>
      </div>
    </form>
  </div>
</div>
@endsection

@section('scripts')
<script>
  function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
  }

  function openRefundModal(id, ref, typeLabel, amountLabel, customer, status) {
    const form = document.getElementById('refundForm');
    form.action = '/admin/crypto/transactions/' + id + '/refund';
    document.getElementById('refundRefDisplay').textContent = ref;
    document.getElementById('refundCustomerDisplay').textContent = customer;
    document.getElementById('refundTypeDisplay').textContent = typeLabel + ' (' + status.toUpperCase() + ')';
    document.getElementById('refundAmountDisplay').textContent = amountLabel;
    document.getElementById('refundModal').classList.add('active');
  }

  function openTxDetailsModal(tx) {
    document.getElementById('detailModalSub').textContent = 'Reference: ' + tx.reference + ' • Status: ' + (tx.status || '').toUpperCase();
    const feeSnap = tx.fee_config_snapshot ? JSON.stringify(tx.fee_config_snapshot, null, 2) : 'None';
    const qdxResp = tx.quidax_response ? JSON.stringify(tx.quidax_response, null, 2) : 'None';

    let auditsHtml = '';
    if (tx.audit_logs && tx.audit_logs.length > 0) {
      auditsHtml = tx.audit_logs.map(a => `
        <div style="padding: 8px 10px; border-bottom: 1px solid var(--line-soft); font-size: 11.5px;">
          <div style="display: flex; justify-content: space-between; font-weight: 700;">
            <span>${a.action} (${a.actor_name || a.actor_type})</span>
            <span style="color: var(--ink-soft);">${a.previous_status || '-'} &rarr; ${a.new_status || '-'}</span>
          </div>
          <div style="color: var(--ink-soft); margin-top: 2px;">${a.description || ''}</div>
        </div>
      `).join('');
    } else {
      auditsHtml = '<div style="padding: 10px; font-size: 12px; color: var(--ink-soft);">No audit log entries found.</div>';
    }

    document.getElementById('txDetailsBody').innerHTML = `
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 12.5px; background: var(--surface-alt); padding: 14px; border-radius: 10px; border: 1px solid var(--line);">
        <div><strong>Transaction Type:</strong> ${tx.transaction_type}</div>
        <div><strong>Asset / Network:</strong> ${tx.symbol} (${tx.network || 'N/A'})</div>
        <div><strong>Crypto Amount:</strong> ${tx.crypto_amount} ${tx.symbol}</div>
        <div><strong>Net Crypto Amount:</strong> ${tx.net_crypto_amount} ${tx.symbol}</div>
        <div><strong>Gross Fiat (NGN):</strong> ₦${Number(tx.fiat_amount || 0).toLocaleString()}</div>
        <div><strong>Net Fiat (NGN):</strong> ₦${Number(tx.net_fiat_amount || 0).toLocaleString()}</div>
        <div><strong>Exchange Rate:</strong> ₦${Number(tx.exchange_rate || 0).toLocaleString()}</div>
        <div><strong>Platform Fee:</strong> ₦${Number(tx.platform_fee_ngn || 0).toLocaleString()} (${tx.platform_fee_crypto} ${tx.symbol})</div>
        <div><strong>Network Fee:</strong> ${tx.network_fee_crypto} ${tx.symbol}</div>
        <div><strong>Quidax Reference:</strong> ${tx.quidax_reference || 'N/A'}</div>
        <div style="grid-column: span 2;"><strong>Wallet Address:</strong> <span class="mono">${tx.wallet_address || 'N/A'}</span></div>
        <div style="grid-column: span 2;"><strong>Blockchain TX Hash:</strong> <span class="mono">${tx.tx_hash || 'N/A'}</span></div>
      </div>

      <div>
        <div class="field-label" style="margin-bottom: 4px;">Audit Trail</div>
        <div style="border: 1px solid var(--line); border-radius: 8px; max-height: 150px; overflow-y: auto;">
          ${auditsHtml}
        </div>
      </div>

      <div>
        <div class="field-label" style="margin-bottom: 4px;">Historical Fee Configuration Snapshot</div>
        <pre style="background: #0E2050; color: #E2E8F0; padding: 10px; border-radius: 8px; font-size: 11px; overflow-x: auto; max-height: 140px;">${feeSnap}</pre>
      </div>

      <div>
        <div class="field-label" style="margin-bottom: 4px;">Quidax API Response Payload</div>
        <pre style="background: #0E2050; color: #23D3A5; padding: 10px; border-radius: 8px; font-size: 11px; overflow-x: auto; max-height: 140px;">${qdxResp}</pre>
      </div>
    `;
    document.getElementById('txDetailsModal').classList.add('active');
  }

  async function testQuidaxConnection() {
    const alertBox = document.getElementById('quidaxTestAlert');
    alertBox.style.display = 'block';
    alertBox.style.backgroundColor = '#EFF6FF';
    alertBox.style.color = '#1D4ED8';
    alertBox.textContent = 'Testing live connection to Quidax API...';

    try {
      const res = await fetch('{{ route("admin.crypto.quidax.test") }}', {
        headers: { 'Accept': 'application/json' }
      });
      const data = await res.json();
      if (data.success) {
        alertBox.style.backgroundColor = '#ECFDF5';
        alertBox.style.color = '#065F46';
        alertBox.textContent = '✓ ' + data.message;
      } else {
        alertBox.style.backgroundColor = '#FEF3C7';
        alertBox.style.color = '#92400E';
        alertBox.textContent = '⚠️ ' + data.message;
      }
    } catch (err) {
      alertBox.style.backgroundColor = '#FFE4E6';
      alertBox.style.color = '#9F1239';
      alertBox.textContent = '✕ Failed to reach Quidax test endpoint.';
    }
  }
</script>
@endsection
