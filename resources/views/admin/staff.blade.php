@extends('admin.layout')

@section('title', 'Staff & RBAC Roles | Gutter Command')
@section('page_title', 'Staff & Role-Based Access Control (RBAC)')
@section('page_sub', 'Admin permissions matrix, team member provisioning, and segregation of duties')

@section('content')
<style>
  .matrix-table th, .matrix-table td {
    padding: 12px 20px;
    border-bottom: 1px solid var(--line-soft);
  }
</style>

<div class="card" style="margin-bottom: 24px; padding: 18px 24px; display: flex; align-items: center; justify-content: space-between;">
  <div>
    <h2 style="font-size: 16px; font-weight: 800; color: var(--navy-deep);">Administrative Staff Members</h2>
    <p style="font-size: 12.5px; color: var(--ink-soft); margin-top: 2px;">
      Manage privileged team accounts with segregated financial operational permissions.
    </p>
  </div>

  @if($admin->isSuperAdmin())
    <button
      type="button"
      onclick="openAddStaffModal()"
      style="display: flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; background-color: var(--navy); color: #FFFFFF; border: none; font-size: 13px; font-weight: 700; cursor: pointer;"
    >
      <span>+ Add Admin Staff</span>
    </button>
  @endif
</div>

<!-- Staff Table -->
<div class="card" style="margin-bottom: 28px;">
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Staff Name</th>
          <th>Official Email</th>
          <th>Assigned Role</th>
          <th>Status</th>
          <th>Last Login</th>
          <th>Created Date</th>
        </tr>
      </thead>
      <tbody>
        @foreach($staff as $s)
          @php
            $roles = [
              'super_admin' => ['label' => 'Super Admin', 'bg' => '#0D9488'],
              'compliance_officer' => ['label' => 'Compliance Officer', 'bg' => '#2563EB'],
              'support_lead' => ['label' => 'Support & Disputes', 'bg' => '#D97706'],
              'financial_auditor' => ['label' => 'Financial Auditor', 'bg' => '#7C3AED'],
            ];
            $b = $roles[$s->role] ?? ['label' => $s->role, 'bg' => '#64748B'];
          @endphp
          <tr>
            <td>
              <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 28px; height: 28px; border-radius: 8px; background-color: var(--chip); color: var(--navy); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px;">
                  {{ substr($s->name, 0, 1) }}
                </div>
                <span style="font-weight: 700;">{{ $s->name }}</span>
              </div>
            </td>
            <td style="color: var(--ink-mid);">{{ $s->email }}</td>
            <td>
              <span class="role-tag" style="background-color: {{ $b['bg'] }}; color: #FFFFFF;">
                {{ $b['label'] }}
              </span>
            </td>
            <td>
              <span style="color: var(--mint-dark); font-weight: 700; font-size: 11px;">● Active</span>
            </td>
            <td style="font-size: 12px; color: var(--ink-soft);">
              {{ $s->last_login_at ? $s->last_login_at->format('M d, Y h:i A') : 'Never' }}
            </td>
            <td style="font-size: 12px; color: var(--ink-soft);">
              {{ $s->created_at->format('M d, Y') }}
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

<!-- RBAC Permissions Matrix -->
<div class="card">
  <div class="card-header">
    <div>
      <h3 style="font-size: 15px; font-weight: 800; color: var(--navy-deep);">Segregation of Duties & RBAC Permissions Matrix</h3>
    </div>
  </div>

  <div class="table-container">
    <table class="matrix-table">
      <thead>
        <tr>
          <th>Permission / Capability</th>
          <th style="text-align: center;">Super Admin</th>
          <th style="text-align: center;">Compliance</th>
          <th style="text-align: center;">Support</th>
          <th style="text-align: center;">Auditor</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="font-weight: 600;">Toggle Live Mobile Feature Buttons (Airtime/Data/Crypto/Cards)</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
        </tr>
        <tr>
          <td style="font-weight: 600;">Enforce / Relax KYC Gateways for Transactions</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
        </tr>
        <tr>
          <td style="font-weight: 600;">Approve / Reject Customer Tier 2 BVN & NIN KYC</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
        </tr>
        <tr>
          <td style="font-weight: 600;">Resolve Customer In-App Help Tickets & Disputes</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
        </tr>
        <tr>
          <td style="font-weight: 600;">Override Wallet Balances & Issue Financial Refunds</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
          <td style="text-align: center; color: #CBD5E1;">✕</td>
        </tr>
        <tr>
          <td style="font-weight: 600;">Financial Ledger & Settlement Reporting (Read-Only)</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
          <td style="text-align: center; color: var(--mint-dark); font-weight: 800;">✓</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Admin Staff Modal -->
<div class="modal-overlay" id="addStaffModal" onclick="closeAddStaffModal()">
  <div class="modal-content" onclick="event.stopPropagation()">
    <div class="modal-header">
      <div>
        <h3 style="font-size: 16px; font-weight: 800; color: var(--navy-deep);">Provision Admin Staff</h3>
        <p style="font-size: 12px; color: var(--ink-soft); margin-top: 2px;">Grant specific financial operational permissions</p>
      </div>
      <button type="button" onclick="closeAddStaffModal()" style="background: none; border: none; font-size: 18px; cursor: pointer;">✕</button>
    </div>

    <form action="{{ route('admin.staff.store') }}" method="POST" class="modal-body">
      @csrf

      <div style="display: flex; flex-direction: column; gap: 6px;">
        <label style="font-size: 12px; font-weight: 700; color: var(--ink);">Full Name</label>
        <input type="text" name="name" required placeholder="e.g. John Compliance" class="modal-input">
      </div>

      <div style="display: flex; flex-direction: column; gap: 6px;">
        <label style="font-size: 12px; font-weight: 700; color: var(--ink);">Official Email</label>
        <input type="email" name="email" required placeholder="john@gutter.com" class="modal-input">
      </div>

      <div style="display: flex; flex-direction: column; gap: 6px;">
        <label style="font-size: 12px; font-weight: 700; color: var(--ink);">Temporary Password</label>
        <input type="password" name="password" required minlength="8" placeholder="At least 8 characters" class="modal-input">
      </div>

      <div style="display: flex; flex-direction: column; gap: 6px;">
        <label style="font-size: 12px; font-weight: 700; color: var(--ink);">Operational Role</label>
        <select name="role" class="modal-select">
          <option value="compliance_officer">🛡️ Compliance Officer (KYC & Documents)</option>
          <option value="support_lead">🎧 Support & Disputes Lead</option>
          <option value="financial_auditor">📊 Financial Auditor (Ledger & Reports)</option>
          <option value="super_admin">👑 Super Admin (Full Governance)</option>
        </select>
      </div>

      <div style="display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-top: 6px;">
        <button type="button" onclick="closeAddStaffModal()" style="padding: 10px 16px; background-color: var(--surface-alt); border: 1px solid var(--line); border-radius: 10px; font-size: 13px; font-weight: 700; color: var(--ink-soft); cursor: pointer;">
          Cancel
        </button>
        <button type="submit" style="padding: 10px 20px; background-color: var(--navy); color: #FFFFFF; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer;">
          Provision Staff Account
        </button>
      </div>
    </form>
  </div>
</div>
@endsection

@section('scripts')
<script>
  function openAddStaffModal() {
    document.getElementById('addStaffModal').classList.add('active');
  }
  function closeAddStaffModal() {
    document.getElementById('addStaffModal').classList.remove('active');
  }
</script>
@endsection
