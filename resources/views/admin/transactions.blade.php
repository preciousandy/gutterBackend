@extends('admin.layout')

@section('title', 'Financial Transactions Ledger | Gutter Command')
@section('page_title', 'Financial Transactions Ledger')
@section('page_sub', 'Audited log of bank transfers, airtime/data purchases, and crypto settlements')

@section('content')
<style>
  .filter-card {
    background-color: var(--surface);
    border-radius: var(--radius-lg);
    border: 1px solid var(--line);
    padding: 16px 20px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
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
    flex: 1 1 320px;
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
    gap: 10px;
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
  .export-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 10px;
    background-color: var(--surface);
    border: 1px solid var(--line);
    color: var(--ink);
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
  }
  .details-btn {
    padding: 6px 12px;
    border-radius: 8px;
    background-color: var(--chip);
    border: 1px solid var(--line);
    color: var(--navy);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
  }
  .pagination-wrap {
    padding: 18px 24px;
    display: flex;
    justify-content: flex-end;
  }
</style>

<!-- Filter Toolbar -->
<form action="{{ route('admin.transactions') }}" method="GET" class="filter-card">
  <div class="search-wrap">
    <span>🔍</span>
    <input
      type="text"
      name="search"
      placeholder="Search reference, customer name, phone, or email..."
      value="{{ request('search') }}"
      class="search-input"
    >
  </div>

  <div class="filter-group">
    <select name="type" class="select" onchange="this.form.submit()">
      <option value="all">All Channels</option>
      <option value="airtime" {{ request('type') === 'airtime' ? 'selected' : '' }}>Airtime Recharge</option>
      <option value="data" {{ request('type') === 'data' ? 'selected' : '' }}>Data Bundles</option>
      <option value="electricity" {{ request('type') === 'electricity' ? 'selected' : '' }}>Electricity Bills</option>
      <option value="cable" {{ request('type') === 'cable' ? 'selected' : '' }}>Cable TV</option>
      <option value="betting" {{ request('type') === 'betting' ? 'selected' : '' }}>Betting Funding</option>
      <option value="transfer" {{ request('type') === 'transfer' ? 'selected' : '' }}>Bank Transfers</option>
      <option value="crypto_deposit" {{ request('type') === 'crypto_deposit' ? 'selected' : '' }}>Crypto Deposits</option>
      <option value="crypto_swap" {{ request('type') === 'crypto_swap' ? 'selected' : '' }}>Crypto Swaps</option>
    </select>

    <select name="status" class="select" onchange="this.form.submit()">
      <option value="all">All Statuses</option>
      <option value="successful" {{ request('status') === 'successful' ? 'selected' : '' }}>Successful</option>
      <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
      <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
    </select>

    <button type="submit" class="filter-btn">
      <span>Filter</span>
    </button>

    <button type="button" class="export-btn" onclick="exportTableToCSV('gutter_transactions.csv')">
      <span>📥 Export CSV</span>
    </button>
  </div>
</form>

<!-- Ledger Table -->
<div class="card">
  <div class="table-container">
    <table id="transactionsTable">
      <thead>
        <tr>
          <th>Reference</th>
          <th>Customer</th>
          <th>Channel</th>
          <th>Amount</th>
          <th>Fee</th>
          <th>Status</th>
          <th>Timestamp</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        @forelse($transactions as $tx)
          <tr>
            <td><span class="mono">{{ $tx->reference }}</span></td>
            <td>
              <div style="font-weight: 700; font-size: 13.5px;">
                {{ $tx->user ? $tx->user->first_name . ' ' . $tx->user->last_name : 'Unknown User' }}
              </div>
              <div style="font-size: 11px; color: var(--ink-soft);">
                {{ $tx->user?->email ?? $tx->user?->phone }}
              </div>
            </td>
            <td>
              <span style="font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 5px; background-color: var(--chip); color: var(--navy);">
                {{ strtoupper(str_replace('_', ' ', $tx->type)) }}
              </span>
            </td>
            <td style="font-weight: 700; font-family: var(--font-mono);">
              ₦{{ number_format($tx->amount, 2) }}
            </td>
            <td style="font-size: 12px; color: var(--ink-soft);">
              {{ $tx->fee > 0 ? '₦' . number_format($tx->fee, 2) : 'Free' }}
            </td>
            <td>
              <span class="badge {{ $tx->status === 'successful' ? 'badge-success' : ($tx->status === 'failed' ? 'badge-danger' : 'badge-warning') }}">
                {{ strtoupper($tx->status) }}
              </span>
            </td>
            <td style="font-size: 12px; color: var(--ink-soft);">
              {{ $tx->created_at->format('M d, Y h:i A') }}
            </td>
            <td>
              <button
                type="button"
                class="details-btn"
                onclick="showTxModal({{ json_encode($tx) }}, {{ json_encode($tx->user) }})"
              >
                Details
              </button>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="8" style="padding: 50px; text-align: center; color: var(--ink-soft);">
              No transactions matching the criteria found on the ledger.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($transactions->hasPages())
    <div class="pagination-wrap">
      {{ $transactions->links() }}
    </div>
  @endif
</div>

<!-- Transaction Receipt Audit Modal -->
<div class="modal-overlay" id="txModal" onclick="closeTxModal()">
  <div class="modal-content" onclick="event.stopPropagation()">
    <div class="modal-header">
      <div>
        <h3 style="font-size: 16px; font-weight: 800; color: var(--navy-deep);">Transaction Audit Record</h3>
        <p style="font-size: 12px; color: var(--ink-soft); margin-top: 2px;">Detailed settlement payload and customer ledger</p>
      </div>
      <button type="button" onclick="closeTxModal()" style="background: none; border: none; font-size: 18px; cursor: pointer;">✕</button>
    </div>

    <div class="modal-body">
      <div style="background-color: var(--surface-alt); border: 1px dashed var(--line-active); border-radius: var(--radius-lg); padding: 20px;">
        <div style="text-align: center; padding-bottom: 12px;">
          <div style="font-size: 11px; color: var(--ink-soft); font-weight: 700; text-transform: uppercase;">Settlement Amount</div>
          <div id="modalAmount" style="font-size: 26px; font-weight: 800; color: var(--navy-deep); font-family: var(--font-mono); margin-top: 4px;">₦0.00</div>
          <span id="modalStatusBadge" class="badge" style="margin-top: 6px;">SUCCESSFUL</span>
        </div>

        <div style="border-top: 1px dashed var(--line-active); margin: 12px 0 16px 0;"></div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
          <div>
            <div style="font-size: 10.5px; color: var(--ink-soft); text-transform: uppercase; font-weight: 700;">Reference</div>
            <div id="modalRef" class="mono" style="margin-top: 2px;">REF-123</div>
          </div>
          <div>
            <div style="font-size: 10.5px; color: var(--ink-soft); text-transform: uppercase; font-weight: 700;">Channel</div>
            <div id="modalType" style="font-weight: 600; font-size: 13px; margin-top: 2px;">TRANSFER</div>
          </div>
          <div>
            <div style="font-size: 10.5px; color: var(--ink-soft); text-transform: uppercase; font-weight: 700;">Customer</div>
            <div id="modalUser" style="font-weight: 600; font-size: 13px; margin-top: 2px;">John Doe</div>
          </div>
          <div>
            <div style="font-size: 10.5px; color: var(--ink-soft); text-transform: uppercase; font-weight: 700;">Customer Phone</div>
            <div id="modalPhone" style="font-weight: 600; font-size: 13px; margin-top: 2px;">08012345678</div>
          </div>
          <div style="grid-column: span 2;">
            <div style="font-size: 10.5px; color: var(--ink-soft); text-transform: uppercase; font-weight: 700;">Narration / Description</div>
            <div id="modalDesc" style="font-size: 13px; color: var(--ink); margin-top: 2px;">Transaction description</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@section('scripts')
<script>
  function showTxModal(tx, user) {
    document.getElementById('modalAmount').innerText = '₦' + parseFloat(tx.amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
    document.getElementById('modalRef').innerText = tx.reference;
    document.getElementById('modalType').innerText = (tx.type || '').toUpperCase().replace('_', ' ');
    document.getElementById('modalUser').innerText = user ? (user.first_name + ' ' + user.last_name) : 'Unknown User';
    document.getElementById('modalPhone').innerText = user?.phone || 'N/A';
    document.getElementById('modalDesc').innerText = tx.description || 'None';

    const badge = document.getElementById('modalStatusBadge');
    badge.innerText = (tx.status || '').toUpperCase();
    badge.className = 'badge ' + (tx.status === 'successful' ? 'badge-success' : (tx.status === 'failed' ? 'badge-danger' : 'badge-warning'));

    document.getElementById('txModal').classList.add('active');
  }

  function closeTxModal() {
    document.getElementById('txModal').classList.remove('active');
  }

  function exportTableToCSV(filename) {
    let csv = [];
    const rows = document.querySelectorAll("#transactionsTable tr");
    for (let i = 0; i < rows.length; i++) {
      let row = [], cols = rows[i].querySelectorAll("td, th");
      for (let j = 0; j < cols.length - 1; j++) {
        row.push('"' + cols[j].innerText.replace(/"/g, '""').trim() + '"');
      }
      csv.push(row.join(","));
    }
    const csvFile = new Blob([csv.join("\n")], { type: "text/csv" });
    const downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
  }
</script>
@endsection
