@extends('admin.layout')

@section('title', 'KYC & Regulatory Compliance | Gutter Command')
@section('page_title', 'KYC & Regulatory Compliance')
@section('page_sub', 'Identity verification reviews, live Prembly IdentityPass integration, and manual compliance approval')

@section('content')
<!-- KPI Header Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 24px;">
  <!-- Prembly Provider Status -->
  <div class="card" style="padding: 18px; border-left: 4px solid var(--navy);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <div>
        <div style="font-size: 11.5px; font-weight: 700; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.5px;">Identity Provider</div>
        <div style="font-size: 18px; font-weight: 800; color: var(--navy-deep); margin-top: 4px;">Prembly Live</div>
        <div style="font-size: 11.5px; color: var(--ink-mid); margin-top: 2px;">IdentityPass API Gateway</div>
      </div>
      <span class="badge {{ $premblyConfigured ? 'badge-success' : 'badge-warning' }}" style="font-size: 10.5px; padding: 4px 8px;">
        {{ $premblyConfigured ? '● LIVE CONNECTED' : 'NOT CONFIGURED' }}
      </span>
    </div>
    <div style="margin-top: 12px; font-size: 11px; color: var(--ink-soft); display: flex; gap: 8px; flex-wrap: wrap;">
      <span style="background: #F1F5F9; padding: 2px 6px; border-radius: 4px;">URL: api.prembly.com</span>
      <span style="background: #F1F5F9; padding: 2px 6px; border-radius: 4px;">App ID: live_pk_3cf9...</span>
    </div>
  </div>

  <!-- Pending Approvals -->
  <div class="card" style="padding: 18px; border-left: 4px solid #F59E0B;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <div>
        <div style="font-size: 11.5px; font-weight: 700; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.5px;">Pending Approval</div>
        <div style="font-size: 26px; font-weight: 900; color: #B45309; margin-top: 2px;">{{ number_format($pendingCount) }}</div>
        <div style="font-size: 11.5px; color: var(--ink-mid);">Submissions requiring review</div>
      </div>
      <div style="width: 36px; height: 36px; border-radius: 10px; background: #FEF3C7; display: flex; align-items: center; justify-content: center; color: #B45309; font-size: 16px;">
        ⏳
      </div>
    </div>
  </div>

  <!-- Verified Tier 2 -->
  <div class="card" style="padding: 18px; border-left: 4px solid var(--mint);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <div>
        <div style="font-size: 11.5px; font-weight: 700; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.5px;">Verified Tier 2</div>
        <div style="font-size: 26px; font-weight: 900; color: var(--mint-dark); margin-top: 2px;">{{ number_format($verifiedCount) }}</div>
        <div style="font-size: 11.5px; color: var(--ink-mid);">Unlocked ₦5M/day limit</div>
      </div>
      <div style="width: 36px; height: 36px; border-radius: 10px; background: var(--mint-bg); display: flex; align-items: center; justify-content: center; color: var(--mint-dark); font-size: 16px;">
        ✓
      </div>
    </div>
  </div>

  <!-- Rejected Records -->
  <div class="card" style="padding: 18px; border-left: 4px solid var(--red);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <div>
        <div style="font-size: 11.5px; font-weight: 700; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.5px;">Rejected Submissions</div>
        <div style="font-size: 26px; font-weight: 900; color: var(--red); margin-top: 2px;">{{ number_format($rejectedCount) }}</div>
        <div style="font-size: 11.5px; color: var(--ink-mid);">Declined compliance requests</div>
      </div>
      <div style="width: 36px; height: 36px; border-radius: 10px; background: var(--red-bg); display: flex; align-items: center; justify-content: center; color: var(--red); font-size: 16px;">
        ✕
      </div>
    </div>
  </div>
</div>

<!-- Main Table Card with Tabs -->
<div class="card" style="margin-bottom: 24px;">
  <!-- Tab Filter Navigation -->
  <div style="display: flex; border-bottom: 1px solid var(--line); padding: 0 16px; background: #FAFAFA; border-top-left-radius: 14px; border-top-right-radius: 14px;">
    <a href="{{ route('admin.kyc', ['tab' => 'pending']) }}"
       style="padding: 14px 18px; font-size: 13px; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 8px; border-bottom: 2px solid {{ $activeTab === 'pending' ? 'var(--navy)' : 'transparent' }}; color: {{ $activeTab === 'pending' ? 'var(--navy)' : 'var(--ink-soft)' }};">
      Pending Review
      <span style="font-size: 11px; padding: 2px 7px; border-radius: 10px; background: {{ $activeTab === 'pending' ? 'var(--navy)' : '#E2E8F0' }}; color: {{ $activeTab === 'pending' ? '#FFF' : 'var(--ink-soft)' }};">
        {{ $pendingCount }}
      </span>
    </a>

    <a href="{{ route('admin.kyc', ['tab' => 'verified']) }}"
       style="padding: 14px 18px; font-size: 13px; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 8px; border-bottom: 2px solid {{ $activeTab === 'verified' ? 'var(--navy)' : 'transparent' }}; color: {{ $activeTab === 'verified' ? 'var(--navy)' : 'var(--ink-soft)' }};">
      Verified Tier 2
      <span style="font-size: 11px; padding: 2px 7px; border-radius: 10px; background: {{ $activeTab === 'verified' ? 'var(--navy)' : '#E2E8F0' }}; color: {{ $activeTab === 'verified' ? '#FFF' : 'var(--ink-soft)' }};">
        {{ $verifiedCount }}
      </span>
    </a>

    <a href="{{ route('admin.kyc', ['tab' => 'rejected']) }}"
       style="padding: 14px 18px; font-size: 13px; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 8px; border-bottom: 2px solid {{ $activeTab === 'rejected' ? 'var(--navy)' : 'transparent' }}; color: {{ $activeTab === 'rejected' ? 'var(--navy)' : 'var(--ink-soft)' }};">
      Rejected
      <span style="font-size: 11px; padding: 2px 7px; border-radius: 10px; background: {{ $activeTab === 'rejected' ? 'var(--navy)' : '#E2E8F0' }}; color: {{ $activeTab === 'rejected' ? '#FFF' : 'var(--ink-soft)' }};">
        {{ $rejectedCount }}
      </span>
    </a>

    <a href="{{ route('admin.kyc', ['tab' => 'all']) }}"
       style="padding: 14px 18px; font-size: 13px; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 8px; border-bottom: 2px solid {{ $activeTab === 'all' ? 'var(--navy)' : 'transparent' }}; color: {{ $activeTab === 'all' ? 'var(--navy)' : 'var(--ink-soft)' }};">
      All Submissions
    </a>
  </div>

  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Customer</th>
          <th>Identity Document</th>
          <th>Evidence / Photo</th>
          <th>Provider / Engine</th>
          <th>KYC Status & Tier</th>
          <th>Date Submitted</th>
          <th style="text-align: right;">Compliance Action</th>
        </tr>
      </thead>
      <tbody>
        @forelse($pendingUsers as $user)
          @php
            $ver = $user->latestKycVerification;
            $docType = strtoupper($ver?->document_type ?? 'NIN / BVN');
            $idData = is_array($ver?->id_data) ? $ver->id_data : [];
            $docImage = $idData['document_image'] ?? null;
            $faceData = is_array($ver?->face_data) ? $ver->face_data : [];
            $selfieImage = $faceData['selfie_image'] ?? null;
            $isVerified = $user->kyc_status === 'verified';
            $isRejected = $user->kyc_status === 'rejected';
          @endphp
          <tr>
            <td>
              <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 36px; height: 36px; border-radius: 10px; background: #EEF2F6; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12.5px; color: var(--navy);">
                  {{ strtoupper(substr($user->first_name ?? 'U', 0, 1) . substr($user->last_name ?? 'N', 0, 1)) }}
                </div>
                <div>
                  <div style="font-weight: 800; font-size: 13.5px; color: var(--navy-deep);">{{ $user->first_name }} {{ $user->last_name }}</div>
                  <div style="font-size: 11px; color: var(--ink-soft);">{{ $user->email }} • {{ $user->phone }}</div>
                  <div style="font-size: 10.5px; color: var(--ink-muted); margin-top: 1px;">User ID: #{{ $user->id }}</div>
                </div>
              </div>
            </td>

            <td>
              <div>
                <span style="font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 5px; background-color: #EFF6FF; color: #1D4ED8;">
                  {{ $docType }}
                </span>
                @if($ver && $ver->masked_document_number)
                  <div class="mono" style="font-size: 11.5px; margin-top: 4px; color: var(--navy-deep); font-weight: 600;">
                    {{ $ver->masked_document_number }}
                  </div>
                @else
                  <div style="font-size: 11px; color: var(--ink-muted); margin-top: 4px;">Pending Number</div>
                @endif
              </div>
            </td>

            <td>
              <div style="display: flex; gap: 6px; align-items: center;">
                @if($docImage)
                  <button type="button" onclick="previewImage('{{ $docImage }}', 'Document Image - {{ $user->first_name }} {{ $user->last_name }}')"
                          style="padding: 4px 8px; border-radius: 6px; background: #F1F5F9; border: 1px solid var(--line); font-size: 11px; font-weight: 700; cursor: pointer; color: var(--navy);">
                    📄 View ID
                  </button>
                @endif

                @if($selfieImage)
                  <button type="button" onclick="previewImage('{{ $selfieImage }}', 'Biometric Selfie - {{ $user->first_name }} {{ $user->last_name }}')"
                          style="padding: 4px 8px; border-radius: 6px; background: #F0FDF4; border: 1px solid #BBF7D0; font-size: 11px; font-weight: 700; cursor: pointer; color: #166534;">
                    👤 Selfie
                  </button>
                @endif

                @if(!$docImage && !$selfieImage)
                  <span style="font-size: 11px; color: var(--ink-muted);">None attached</span>
                @endif
              </div>
            </td>

            <td>
              @php
                $providerName = $ver?->provider ?? 'prembly';
                $isManualQueue = str_contains(strtolower($providerName), 'manual');
              @endphp
              <span class="badge {{ $isManualQueue ? 'badge-warning' : 'badge-info' }}" style="font-size: 10px;">
                {{ strtoupper($providerName) }}
              </span>
            </td>

            <td>
              <div style="display: flex; flex-direction: column; gap: 3px;">
                <span class="badge {{ $isVerified ? 'badge-success' : ($isRejected ? 'badge-danger' : 'badge-warning') }}" style="font-size: 10.5px; align-self: flex-start;">
                  {{ strtoupper($user->kyc_status ?? 'PENDING') }}
                </span>
                <span style="font-size: 10.5px; font-weight: 700; color: var(--ink-mid);">
                  Tier {{ $user->tier ?? 1 }} ({{ $user->tier >= 2 ? '₦5M/day' : '₦50k/day' }})
                </span>
              </div>
            </td>

            <td style="font-size: 11.5px; color: var(--ink-soft);">
              {{ ($ver?->created_at ?? $user->created_at)->format('M d, Y') }}
              <div style="font-size: 10.5px; color: var(--ink-muted);">{{ ($ver?->created_at ?? $user->created_at)->format('H:i A') }}</div>
            </td>

            <td style="text-align: right;">
              <div style="display: flex; gap: 8px; justify-content: flex-end; align-items: center;">
                @if(!$isVerified)
                  <!-- Approve Button -->
                  <form action="{{ route('admin.kyc.approve', $user->id) }}" method="POST" onsubmit="return confirm('Approve KYC verification for {{ $user->first_name }} {{ $user->last_name }} and upgrade them to Tier 2 (₦5,000,000/day limit)?');">
                    @csrf
                    <button type="submit" style="padding: 6px 12px; border-radius: 8px; background-color: var(--mint-bg); border: 1px solid rgba(35, 211, 165, 0.4); color: var(--mint-dark); font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.15s ease;">
                      ✓ Approve Tier 2
                    </button>
                  </form>

                  <!-- Reject Button -->
                  <button type="button" onclick="openRejectModal({{ $user->id }}, '{{ $user->first_name }} {{ $user->last_name }}')"
                          style="padding: 6px 12px; border-radius: 8px; background-color: var(--red-bg); border: 1px solid #FECDD3; color: var(--red); font-size: 12px; font-weight: 700; cursor: pointer;">
                    ✕ Reject
                  </button>
                @else
                  <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 700; color: var(--mint-dark); background: var(--mint-bg); padding: 4px 10px; border-radius: 8px;">
                    ✓ Approved Tier 2
                  </span>
                  <!-- Option to re-review / reject if suspicious -->
                  <button type="button" onclick="openRejectModal({{ $user->id }}, '{{ $user->first_name }} {{ $user->last_name }}')"
                          style="padding: 4px 8px; border-radius: 6px; background-color: #F8FAFC; border: 1px solid var(--line); color: var(--red); font-size: 11px; font-weight: 600; cursor: pointer;">
                    Revoke
                  </button>
                @endif
              </div>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" style="padding: 48px; text-align: center; color: var(--ink-soft);">
              <div style="font-size: 24px; margin-bottom: 8px;">📋</div>
              <div style="font-weight: 700; font-size: 14px; color: var(--navy-deep);">No KYC records in this category</div>
              <div style="font-size: 12px; color: var(--ink-soft); margin-top: 4px;">Users who submit their NIN, BVN, or ID for Tier 2 upgrades will appear here for automated Prembly checks or manual approval.</div>
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($pendingUsers->hasPages())
    <div style="padding: 16px; border-top: 1px solid var(--line);">
      {{ $pendingUsers->links() }}
    </div>
  @endif
</div>

<!-- Modal: Image Preview -->
<div id="imagePreviewModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
  <div style="background: white; border-radius: 16px; max-width: 600px; width: 100%; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
    <div style="padding: 16px 20px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
      <h4 id="imageModalTitle" style="margin: 0; font-size: 14px; font-weight: 800; color: var(--navy-deep);">Document Evidence</h4>
      <button type="button" onclick="closeImageModal()" style="border: none; background: none; font-size: 18px; cursor: pointer; color: var(--ink-soft);">✕</button>
    </div>
    <div style="padding: 20px; text-align: center; max-height: 70vh; overflow-y: auto;">
      <img id="imageModalSrc" src="" alt="Evidence" style="max-width: 100%; max-height: 60vh; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
    </div>
  </div>
</div>

<!-- Modal: Rejection Reason -->
<div id="rejectModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
  <div style="background: white; border-radius: 16px; max-width: 480px; width: 100%; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
    <form id="rejectForm" action="" method="POST">
      @csrf
      <div style="padding: 18px 22px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
        <h4 style="margin: 0; font-size: 14.5px; font-weight: 800; color: var(--red);">Reject KYC Verification</h4>
        <button type="button" onclick="closeRejectModal()" style="border: none; background: none; font-size: 18px; cursor: pointer; color: var(--ink-soft);">✕</button>
      </div>

      <div style="padding: 22px;">
        <p style="font-size: 12.5px; color: var(--ink-soft); margin-bottom: 16px;">
          You are rejecting the identity verification for <strong id="rejectUserName" style="color: var(--navy-deep);"></strong>. This reason will be securely communicated to the user.
        </p>

        <label style="display: block; font-size: 12px; font-weight: 700; color: var(--navy-deep); margin-bottom: 6px;">
          Reason for Rejection
        </label>
        <select id="rejectReasonPreset" onchange="applyReasonPreset(this.value)" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--line); margin-bottom: 10px; font-size: 12.5px;">
          <option value="">Select common reason or type below...</option>
          <option value="Blurry or unreadable document photo. Please take a clear photo in good lighting.">Blurry or unreadable document</option>
          <option value="Name on provided document does not match the registered account name.">Name mismatch with account</option>
          <option value="Document number could not be verified by identity authorities.">Invalid document number</option>
          <option value="Expired government document. Please provide a currently valid document.">Expired document</option>
          <option value="Selfie face check does not match the photo on the identity document.">Face mismatch</option>
        </select>

        <textarea name="reason" id="rejectReasonText" rows="3" required placeholder="Describe the reason for rejection..."
                  style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--line); font-size: 12.5px; resize: vertical;"></textarea>
      </div>

      <div style="padding: 14px 22px; background: #F8FAFC; border-top: 1px solid var(--line); display: flex; justify-content: flex-end; gap: 10px;">
        <button type="button" onclick="closeRejectModal()" style="padding: 8px 16px; border-radius: 8px; border: 1px solid var(--line); background: white; font-weight: 600; font-size: 12px; cursor: pointer;">
          Cancel
        </button>
        <button type="submit" style="padding: 8px 16px; border-radius: 8px; border: none; background: var(--red); color: white; font-weight: 700; font-size: 12px; cursor: pointer;">
          Confirm Rejection
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function previewImage(src, title) {
  document.getElementById('imageModalSrc').src = src;
  document.getElementById('imageModalTitle').innerText = title;
  document.getElementById('imagePreviewModal').style.display = 'flex';
}

function closeImageModal() {
  document.getElementById('imagePreviewModal').style.display = 'none';
}

function openRejectModal(userId, userName) {
  document.getElementById('rejectForm').action = '/admin/kyc/' + userId + '/reject';
  document.getElementById('rejectUserName').innerText = userName;
  document.getElementById('rejectReasonText').value = '';
  document.getElementById('rejectReasonPreset').value = '';
  document.getElementById('rejectModal').style.display = 'flex';
}

function closeRejectModal() {
  document.getElementById('rejectModal').style.display = 'none';
}

function applyReasonPreset(val) {
  if (val) {
    document.getElementById('rejectReasonText').value = val;
  }
}
</script>
@endsection
