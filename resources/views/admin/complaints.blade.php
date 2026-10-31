@extends('admin.layout')

@section('title', 'Disputes & Support Tickets | Gutter Command')
@section('page_title', 'Customer Disputes & Support Tickets')
@section('page_sub', 'Live complaint and resolution queue filed from the Gutter mobile Help Center')

@section('content')
<div class="card" style="margin-bottom: 20px;">
  <form action="{{ route('admin.complaints') }}" method="GET" style="padding: 14px 20px; display: flex; align-items: center; justify-content: space-between;">
    <div style="font-weight: 700; font-size: 13.5px; color: var(--navy-deep);">
      Ticket Queue Status Filter:
    </div>

    <div>
      <select name="status" class="role-select" onchange="this.form.submit()" style="background-color: var(--chip); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--line);">
        <option value="all">All Ticket Statuses</option>
        <option value="open" {{ request('status') === 'open' ? 'selected' : '' }}>🔴 Open (Pending Action)</option>
        <option value="in_progress" {{ request('status') === 'in_progress' ? 'selected' : '' }}>🟡 In Progress</option>
        <option value="resolved" {{ request('status') === 'resolved' ? 'selected' : '' }}>🟢 Resolved</option>
        <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>⚪ Rejected / Closed</option>
      </select>
    </div>
  </form>
</div>

<div class="card">
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Ticket ID</th>
          <th>Customer</th>
          <th>Issue Category</th>
          <th>Reference</th>
          <th>Message Preview</th>
          <th>Status</th>
          <th>Submitted</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        @forelse($complaints as $c)
          <tr>
            <td>
              <span class="mono">#{{ $c->ticket_id }}</span>
            </td>
            <td>
              <div style="font-weight: 700; font-size: 13px;">{{ $c->user_name ?? 'Customer' }}</div>
              <div style="font-size: 11px; color: var(--ink-soft);">{{ $c->user_email ?? 'N/A' }}</div>
            </td>
            <td>
              <span style="font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 5px; background-color: var(--chip); color: var(--navy);">
                {{ strtoupper(str_replace('_', ' ', $c->category)) }}
              </span>
            </td>
            <td>
              @if($c->reference)
                <span class="mono">{{ $c->reference }}</span>
              @else
                <span style="color: var(--ink-ghost); font-size: 11px;">None</span>
              @endif
            </td>
            <td style="max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 12.5px; color: var(--ink-mid);">
              {{ $c->message }}
            </td>
            <td>
              <span class="badge {{ $c->status === 'resolved' ? 'badge-success' : ($c->status === 'open' ? 'badge-danger' : ($c->status === 'in_progress' ? 'badge-warning' : 'badge-info')) }}">
                {{ strtoupper($c->status) }}
              </span>
            </td>
            <td style="font-size: 12px; color: var(--ink-soft);">
              {{ $c->created_at->format('M d, h:i A') }}
            </td>
            <td>
              <button
                type="button"
                class="details-btn"
                onclick="openComplaintModal({{ json_encode($c) }})"
              >
                Resolve
              </button>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="8" style="padding: 50px; text-align: center; color: var(--ink-soft);">
              No customer complaints found matching this filter.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($complaints->hasPages())
    <div style="padding: 18px 24px; display: flex; justify-content: flex-end;">
      {{ $complaints->links() }}
    </div>
  @endif
</div>

<!-- Ticket Modal -->
<div class="modal-overlay" id="complaintModal" onclick="closeComplaintModal()">
  <div class="modal-content" onclick="event.stopPropagation()">
    <div class="modal-header">
      <div>
        <h3 id="modalTicketTitle" style="font-size: 16px; font-weight: 800; color: var(--navy-deep);">Ticket Details</h3>
        <p id="modalTicketSub" style="font-size: 11.5px; color: var(--ink-soft); margin-top: 2px;">Customer complaint</p>
      </div>
      <button type="button" onclick="closeComplaintModal()" style="background: none; border: none; font-size: 18px; cursor: pointer;">✕</button>
    </div>

    <form id="complaintForm" action="" method="POST" class="modal-body">
      @csrf
      @method('PUT')

      <div id="modalRefBox" style="background-color: var(--surface-alt); border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between;">
        <span style="font-size: 11px; color: var(--ink-soft); font-weight: 600;">Transaction Reference:</span>
        <code id="modalRefCode" class="mono">N/A</code>
      </div>

      <div>
        <div style="font-size: 12px; font-weight: 700; color: var(--ink); margin-bottom: 4px;">Customer's Complaint</div>
        <div id="modalCustomerMsg" style="background-color: #F8FAFC; border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; font-size: 13px; color: var(--ink); line-height: 1.5;"></div>
      </div>

      <div>
        <label style="font-size: 12px; font-weight: 700; color: var(--ink); display: block; margin-bottom: 4px;">Ticket Status</label>
        <select name="status" id="modalStatusSelect" class="modal-select" style="width: 100%;">
          <option value="open">Open (Awaiting review)</option>
          <option value="in_progress">In Progress (Investigating with telco/bank)</option>
          <option value="resolved">Resolved (Refunded or confirmed delivered)</option>
          <option value="rejected">Rejected / Invalid dispute</option>
        </select>
      </div>

      <div>
        <label style="font-size: 12px; font-weight: 700; color: var(--ink); display: block; margin-bottom: 4px;">Internal Resolution Notes / Reply</label>
        <textarea
          name="admin_notes"
          id="modalNotesText"
          rows="3"
          placeholder="e.g. Requeried VTpass, airtime delivered to 080... or Wallet re-credited ₦1,500."
          class="modal-input"
          style="width: 100%; resize: vertical;"
        ></textarea>
      </div>

      <div style="display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-top: 6px;">
        <button type="button" onclick="closeComplaintModal()" style="padding: 10px 16px; background-color: var(--surface-alt); border: 1px solid var(--line); border-radius: 10px; font-size: 13px; font-weight: 700; color: var(--ink-soft); cursor: pointer;">
          Cancel
        </button>
        <button type="submit" style="padding: 10px 20px; background-color: var(--navy); color: #FFFFFF; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer;">
          Update Ticket Resolution
        </button>
      </div>
    </form>
  </div>
</div>
@endsection

@section('scripts')
<script>
  function openComplaintModal(c) {
    document.getElementById('modalTicketTitle').innerText = 'Ticket #' + c.ticket_id;
    document.getElementById('modalTicketSub').innerText = 'Submitted by ' + (c.user_name || 'User') + ' (' + (c.user_email || 'N/A') + ')';
    document.getElementById('modalCustomerMsg').innerText = c.message || 'No description provided.';
    document.getElementById('modalStatusSelect').value = c.status || 'open';
    document.getElementById('modalNotesText').value = c.admin_notes || '';

    if (c.reference) {
      document.getElementById('modalRefBox').style.display = 'flex';
      document.getElementById('modalRefCode').innerText = c.reference;
    } else {
      document.getElementById('modalRefBox').style.display = 'none';
    }

    document.getElementById('complaintForm').action = '/admin/complaints/' + c.id;
    document.getElementById('complaintModal').classList.add('active');
  }

  function closeComplaintModal() {
    document.getElementById('complaintModal').classList.remove('active');
  }
</script>
@endsection
