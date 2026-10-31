<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Gutter Financial Command Center')</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='25' fill='%2312306E'/><circle cx='50' cy='50' r='24' fill='%2323D3A5'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
      --font-mono: 'JetBrains Mono', monospace;

      /* Gutter Brand Palette */
      --navy-deep: #0E2050;
      --navy: #12306E;
      --navy-light: #14367A;
      --navy-bright: #153A82;
      --navy-darker: #0A1738;
      --navy-darkest: #061026;

      --mint: #23D3A5;
      --mint-dark: #0FA97F;
      --mint-deeper: #0D9488;
      --mint-bg: #E8FBF4;
      --mint-on: #062B22;

      --page-bg: #F4F6FA;
      --surface: #FFFFFF;
      --surface-alt: #F8FAFC;
      --chip: #EEF2FB;

      --line: #E2E8F0;
      --line-soft: #EDF2F7;
      --line-active: #CBD5E1;

      --ink: #0E2050;
      --ink-mid: #475569;
      --ink-soft: #64748B;
      --ink-faint: #94A3B8;
      --ink-ghost: #CBD5E1;
      --white: #FFFFFF;

      --red: #E11D48;
      --red-bg: #FFE4E6;
      --amber: #D97706;
      --amber-bg: #FEF3C7;
      --blue: #2563EB;
      --blue-bg: #EFF6FF;
      --purple: #7C3AED;
      --purple-bg: #F5F3FF;

      --radius-sm: 8px;
      --radius-md: 12px;
      --radius-lg: 16px;
      --radius-xl: 20px;
      --radius-full: 9999px;

      --shadow-sm: 0 1px 2px 0 rgba(14, 32, 80, 0.05);
      --shadow-md: 0 4px 6px -1px rgba(14, 32, 80, 0.07);
      --shadow-lg: 0 10px 15px -3px rgba(14, 32, 80, 0.08);
      --shadow-xl: 0 20px 25px -5px rgba(14, 32, 80, 0.1);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: var(--font-sans);
      background-color: var(--page-bg);
      color: var(--ink);
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
      overflow-x: hidden;
    }

    /* Layout */
    .app-layout {
      display: flex;
      min-height: 100vh;
    }
    .sidebar {
      width: 280px;
      background-color: var(--navy-deep);
      color: #FFFFFF;
      display: flex;
      flex-direction: column;
      height: 100vh;
      position: sticky;
      top: 0;
      border-right: 1px solid rgba(255, 255, 255, 0.08);
      box-shadow: 4px 0 24px rgba(10, 23, 56, 0.35);
      z-index: 100;
      flex-shrink: 0;
    }
    .main-wrapper {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
      overflow-y: auto;
      height: 100vh;
    }
    .main-content {
      flex: 1;
      padding: 28px 32px 48px 32px;
      max-width: 1440px;
      width: 100%;
      margin: 0 auto;
    }

    /* Brand Header */
    .brand-group {
      padding: 24px 20px 18px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }
    .logo-box {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .logo-icon {
      width: 38px;
      height: 38px;
      border-radius: 12px;
      background-color: var(--mint);
      color: var(--navy-deep);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 20px;
      box-shadow: 0 4px 12px rgba(35, 211, 165, 0.3);
    }
    .brand-title {
      font-size: 17px;
      font-weight: 800;
      letter-spacing: -0.3px;
      color: #FFFFFF;
    }
    .brand-sub {
      color: var(--mint);
      font-weight: 700;
      font-size: 14px;
    }
    .brand-tag {
      font-size: 11px;
      color: #8A97B1;
      letter-spacing: 0.4px;
      margin-top: 1px;
    }

    /* Admin Profile in Sidebar */
    .profile-card {
      margin: 16px 16px 12px 16px;
      padding: 12px 14px;
      background-color: rgba(255, 255, 255, 0.05);
      border-radius: 14px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .avatar {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      background-color: #153A82;
      color: #FFFFFF;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 15px;
      border: 1px solid rgba(255, 255, 255, 0.15);
      flex-shrink: 0;
    }
    .admin-name {
      font-size: 13px;
      font-weight: 700;
      color: #FFFFFF;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .admin-email {
      font-size: 11px;
      color: #8A97B1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-bottom: 4px;
    }
    .role-tag {
      display: inline-block;
      font-size: 10px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 6px;
      letter-spacing: 0.3px;
      text-transform: uppercase;
    }

    /* Navigation */
    .nav-section {
      flex: 1;
      overflow-y: auto;
      padding: 10px 12px;
    }
    .nav-header {
      font-size: 10.5px;
      font-weight: 800;
      letter-spacing: 1.2px;
      color: #64748B;
      padding: 8px 10px;
    }
    .nav-list {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .nav-btn {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid transparent;
      background: none;
      cursor: pointer;
      text-decoration: none;
      color: #CBD5E1;
      transition: all 0.15s ease;
    }
    .nav-btn:hover {
      background-color: rgba(255, 255, 255, 0.05);
      color: #FFFFFF;
    }
    .nav-btn.active {
      background-color: rgba(35, 211, 165, 0.12);
      border-color: var(--mint);
      color: #FFFFFF;
    }
    .nav-btn.active .icon-wrap {
      color: var(--mint);
    }
    .icon-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
      color: #94A3B8;
      font-size: 16px;
    }
    .nav-label {
      font-size: 13px;
      font-weight: 600;
    }
    .nav-desc {
      font-size: 10.5px;
      color: #7C8AA6;
      margin-top: 1px;
    }
    .pill-badge {
      font-size: 10px;
      font-weight: 800;
      padding: 2px 8px;
      border-radius: 999px;
      letter-spacing: 0.4px;
    }

    /* Sidebar Footer */
    .system-box {
      margin: 12px 16px;
      padding: 12px 14px;
      background-color: rgba(6, 43, 34, 0.45);
      border-radius: 12px;
      border: 1px solid rgba(35, 211, 165, 0.25);
    }
    .status-pulse {
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background-color: var(--mint);
      box-shadow: 0 0 8px var(--mint);
      margin-right: 6px;
    }
    .status-text {
      font-size: 11.5px;
      font-weight: 700;
      color: #23D3A5;
    }
    .system-sub {
      font-size: 10.5px;
      color: #8A97B1;
      margin-top: 2px;
    }
    .sidebar-footer {
      padding: 14px 16px;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
    }
    .logout-btn {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px;
      background-color: rgba(225, 29, 72, 0.12);
      color: #F43F5E;
      border: 1px solid rgba(225, 29, 72, 0.25);
      border-radius: 10px;
      font-size: 12.5px;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
    }

    /* Executive Topbar */
    .header {
      height: 76px;
      background-color: var(--surface);
      border-bottom: 1px solid var(--line);
      padding: 0 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 90;
      box-shadow: var(--shadow-sm);
    }
    .header-left {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .header-title {
      font-size: 18px;
      font-weight: 800;
      color: var(--navy-deep);
      letter-spacing: -0.3px;
    }
    .header-sub {
      font-size: 12px;
      color: var(--ink-soft);
    }
    .header-right {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .role-switcher {
      display: flex;
      align-items: center;
      gap: 8px;
      background-color: var(--chip);
      padding: 4px 10px;
      border-radius: 10px;
      border: 1px solid var(--line);
    }
    .role-label {
      font-size: 11px;
      font-weight: 700;
      color: var(--ink-soft);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .role-select {
      background: transparent;
      border: none;
      font-size: 12.5px;
      font-weight: 700;
      color: var(--navy-deep);
      cursor: pointer;
      outline: none;
      font-family: inherit;
    }
    .live-indicator {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px 12px;
      background-color: var(--mint-bg);
      border-radius: 999px;
      border: 1px solid rgba(35, 211, 165, 0.3);
    }
    .live-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background-color: var(--mint-dark);
    }
    .live-text {
      font-size: 11px;
      font-weight: 700;
      color: var(--mint-on);
      letter-spacing: 0.2px;
    }

    /* Notification Bell & Dropdown */
    .notif-dropdown-wrap {
      position: relative;
      display: inline-block;
    }
    .notif-bell-btn {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      height: 40px;
      border-radius: 12px;
      background-color: var(--chip);
      border: 1px solid var(--line);
      color: var(--navy-deep);
      cursor: pointer;
      font-size: 17px;
      transition: all 0.15s ease;
    }
    .notif-bell-btn:hover {
      background-color: var(--surface);
      border-color: var(--navy);
      transform: translateY(-1px);
    }
    .notif-badge-count {
      position: absolute;
      top: -4px;
      right: -4px;
      background-color: var(--red);
      color: #FFFFFF;
      font-size: 10px;
      font-weight: 800;
      font-family: var(--font-mono);
      min-width: 18px;
      height: 18px;
      padding: 0 4px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid var(--surface);
      box-shadow: 0 2px 6px rgba(225, 29, 72, 0.4);
      animation: pulseAlert 2s infinite ease-in-out;
    }
    @keyframes pulseAlert {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.08); }
    }
    .notif-menu {
      display: none;
      position: absolute;
      right: 0;
      top: calc(100% + 8px);
      width: 350px;
      background-color: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-xl);
      z-index: 150;
      overflow: hidden;
      animation: fadeIn 0.18s ease-out;
    }
    .notif-menu.show {
      display: block;
    }
    .notif-header {
      padding: 14px 18px;
      background-color: var(--surface-alt);
      border-bottom: 1px solid var(--line);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .notif-list {
      display: flex;
      flex-direction: column;
      max-height: 380px;
      overflow-y: auto;
    }
    .notif-item {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      padding: 12px 16px;
      border-bottom: 1px solid var(--line-soft);
      text-decoration: none;
      color: inherit;
      transition: background-color 0.12s ease;
    }
    .notif-item:hover {
      background-color: var(--surface-alt);
    }
    .notif-item-icon {
      width: 32px;
      height: 32px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 15px;
      flex-shrink: 0;
    }
    .notif-footer {
      padding: 10px 18px;
      background-color: var(--surface-alt);
      border-top: 1px solid var(--line);
      text-align: center;
    }

    /* Common Card Styles */
    .card {
      background-color: var(--surface);
      border-radius: var(--radius-lg);
      border: 1px solid var(--line);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }
    .card-header {
      padding: 18px 24px;
      border-bottom: 1px solid var(--line);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    /* Alert Banner */
    .alert-banner {
      background-color: #FEF3C7;
      border: 1px solid #FCD34D;
      border-radius: var(--radius-md);
      padding: 12px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 20px;
    }
    .alert-success {
      background-color: #ECFDF5;
      border: 1px solid #A7F3D0;
      color: #065F46;
      border-radius: var(--radius-md);
      padding: 12px 18px;
      margin-bottom: 20px;
      font-weight: 700;
      font-size: 13px;
    }
    .alert-danger {
      background-color: #FFE4E6;
      border: 1px solid #FECDD3;
      color: #9F1239;
      border-radius: var(--radius-md);
      padding: 12px 18px;
      margin-bottom: 20px;
      font-weight: 700;
      font-size: 13px;
    }

    /* Tables */
    .table-container {
      overflow-x: auto;
      width: 100%;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }
    th {
      background-color: var(--surface-alt);
      border-bottom: 1px solid var(--line);
      padding: 13px 18px;
      font-size: 11px;
      font-weight: 700;
      color: var(--ink-soft);
      text-transform: uppercase;
      letter-spacing: 0.6px;
    }
    td {
      padding: 14px 18px;
      font-size: 13px;
      color: var(--ink);
      border-bottom: 1px solid var(--line-soft);
    }
    .mono {
      font-family: var(--font-mono);
      font-size: 12px;
      font-weight: 600;
      color: var(--navy);
      background-color: var(--chip);
      padding: 3px 8px;
      border-radius: 6px;
    }

    /* Badges */
    .badge {
      display: inline-block;
      font-size: 10px;
      font-weight: 800;
      padding: 3px 8px;
      border-radius: 999px;
      letter-spacing: 0.4px;
    }
    .badge-success { background-color: var(--mint-bg); color: var(--mint-dark); }
    .badge-danger { background-color: var(--red-bg); color: var(--red); }
    .badge-warning { background-color: var(--amber-bg); color: var(--amber); }
    .badge-info { background-color: var(--blue-bg); color: var(--blue); }

    /* Modals */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background-color: rgba(14, 32, 80, 0.45);
      backdrop-filter: blur(4px);
      z-index: 200;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .modal-overlay.active {
      display: flex;
    }
    .modal-content {
      background-color: var(--surface);
      border-radius: var(--radius-xl);
      max-width: 500px;
      width: 100%;
      box-shadow: var(--shadow-xl);
      overflow: hidden;
      animation: fadeIn 0.2s ease-out;
    }
    .modal-header {
      padding: 20px 24px;
      border-bottom: 1px solid var(--line);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .modal-body {
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Mobile Responsive Rules */
    .hamburger-btn {
      display: none;
      background: none;
      border: none;
      cursor: pointer;
      color: var(--navy-deep);
      padding: 6px;
      border-radius: 8px;
      align-items: center;
      justify-content: center;
    }
    .mobile-overlay {
      display: none;
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(14, 32, 80, 0.55);
      backdrop-filter: blur(4px);
      z-index: 250;
    }
    .mobile-overlay.active {
      display: block;
    }
    .mobile-close-btn {
      display: none;
      background: none;
      border: none;
      color: #94A3B8;
      font-size: 22px;
      cursor: pointer;
      padding: 4px 8px;
    }

    @media (max-width: 900px) {
      .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        bottom: 0;
        transform: translateX(-100%);
        transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 300;
      }
      .sidebar.mobile-open {
        transform: translateX(0);
      }
      .mobile-close-btn {
        display: block;
      }
      .hamburger-btn {
        display: flex;
      }
      .header {
        padding: 0 16px;
        height: 68px;
      }
      .header-title {
        font-size: 15px;
      }
      .header-sub {
        display: none;
      }
      .main-content {
        padding: 18px 14px 40px 14px;
      }
      .live-indicator {
        display: none;
      }
      .role-label {
        display: none;
      }
      .role-select {
        font-size: 11px;
      }
    }
  </style>
</head>
<body>
  <!-- Mobile Overlay -->
  <div class="mobile-overlay" id="mobileOverlay" onclick="toggleMobileSidebar()"></div>

  <div class="app-layout">
    <!-- Sidebar -->
    <aside class="sidebar" id="appSidebar">
      <div class="brand-group" style="display: flex; align-items: center; justify-content: space-between;">
        <div class="logo-box">
          <div class="logo-icon">G</div>
          <div>
            <div class="brand-title">Gutter <span class="brand-sub">Command</span></div>
            <div class="brand-tag">Financial Admin v2.4 (Blade)</div>
          </div>
        </div>
        <button type="button" class="mobile-close-btn" onclick="toggleMobileSidebar()" aria-label="Close Menu">✕</button>
      </div>

      <!-- Admin Profile Card -->
      <div class="profile-card">
        <div class="avatar">{{ substr($admin->name, 0, 1) }}</div>
        <div style="flex: 1; min-width: 0;">
          <div class="admin-name" title="{{ $admin->name }}">{{ $admin->name }}</div>
          <div class="admin-email" title="{{ $admin->email }}">{{ $admin->email }}</div>
          @php
            $roles = [
              'super_admin' => ['label' => 'Super Admin', 'bg' => '#0D9488'],
              'compliance_officer' => ['label' => 'Compliance Officer', 'bg' => '#2563EB'],
              'support_lead' => ['label' => 'Support & Disputes', 'bg' => '#D97706'],
              'financial_auditor' => ['label' => 'Financial Auditor', 'bg' => '#7C3AED'],
            ];
            $currentRole = $roles[$admin->role] ?? ['label' => $admin->role, 'bg' => '#64748B'];
          @endphp
          <div class="role-tag" style="background-color: {{ $currentRole['bg'] }}; color: #FFFFFF;">
            {{ $currentRole['label'] }}
          </div>
        </div>
      </div>

      <!-- Navigation Links -->
      <div class="nav-section">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px;">
          <div class="nav-header" style="padding: 0;">OPERATIONS & CONTROL</div>
          @if(isset($totalAdminNotifications) && $totalAdminNotifications > 0)
            <span class="pill-badge" style="background-color: #E11D48; color: #FFFFFF; font-size: 9.5px;">{{ $totalAdminNotifications }} Alerts</span>
          @endif
        </div>
        <nav class="nav-list">
          <a href="{{ route('admin.dashboard') }}" class="nav-btn {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
            <span class="icon-wrap">📊</span>
            <div style="flex: 1; text-align: left;">
              <div class="nav-label">Financial Overview</div>
              <div class="nav-desc">Cashflow, volume & metrics</div>
            </div>
          </a>

          <!-- System Notifications Menu Item -->
          <a href="javascript:void(0)" onclick="toggleNotificationMenu(event)" class="nav-btn" id="sidebarNotifBtn">
            <span class="icon-wrap">🔔</span>
            <div style="flex: 1; text-align: left;">
              <div class="nav-label">Notifications</div>
              <div class="nav-desc">Compliance & operational alerts</div>
            </div>
            @if(isset($totalAdminNotifications) && $totalAdminNotifications > 0)
              <span class="pill-badge" style="background-color: #E11D48; color: #FFFFFF;">{{ $totalAdminNotifications }} New</span>
            @else
              <span class="pill-badge" style="background-color: rgba(255, 255, 255, 0.1); color: #94A3B8;">0</span>
            @endif
          </a>

          @if($admin->canManageAppControls())
          <a href="{{ route('admin.app_controls') }}" class="nav-btn {{ request()->routeIs('admin.app_controls*') ? 'active' : '' }}">
            <span class="icon-wrap">⚙️</span>
            <div style="flex: 1; text-align: left;">
              <div class="nav-label">Settings & Controls</div>
              <div class="nav-desc">Fees, limits, features & gateways</div>
            </div>
            <span class="pill-badge" style="background-color: var(--mint); color: #062B22;">Fees & Switches</span>
          </a>
          @endif

          <a href="{{ route('admin.transactions') }}" class="nav-btn {{ request()->routeIs('admin.transactions') ? 'active' : '' }}">
            <span class="icon-wrap">💳</span>
            <div style="flex: 1; text-align: left;">
              <div class="nav-label">Transactions Ledger</div>
              <div class="nav-desc">All transfers, bills, crypto</div>
            </div>
          </a>

          @if($admin->canManageKyc() || $admin->role === 'support_lead')
          <a href="{{ route('admin.users') }}" class="nav-btn {{ request()->routeIs('admin.users') ? 'active' : '' }}">
            <span class="icon-wrap">👥</span>
            <div style="flex: 1; text-align: left;">
              <div class="nav-label">User Management</div>
              <div class="nav-desc">Freeze, suspend, block & edit</div>
            </div>
            @if(isset($pendingKyc) && $pendingKyc > 0)
              <span class="pill-badge" style="background-color: #D97706; color: #FFFFFF;">{{ $pendingKyc }} KYC</span>
            @endif
          </a>
          @endif

          @if($admin->canManageKyc())
          <a href="{{ route('admin.kyc') }}" class="nav-btn {{ request()->routeIs('admin.kyc') ? 'active' : '' }}">
            <span class="icon-wrap">🛡️</span>
            <div style="flex: 1; text-align: left;">
              <div class="nav-label">KYC & Compliance</div>
              <div class="nav-desc">BVN/NIN verification reviews</div>
            </div>
            @if(isset($pendingKyc) && $pendingKyc > 0)
              <span class="pill-badge" style="background-color: #D97706; color: #FFFFFF;">{{ $pendingKyc }} Pending</span>
            @endif
          </a>
          @endif

          @if($admin->canManageComplaints())
          <a href="{{ route('admin.complaints') }}" class="nav-btn {{ request()->routeIs('admin.complaints') ? 'active' : '' }}">
            <span class="icon-wrap">🎧</span>
            <div style="flex: 1; text-align: left;">
              <div class="nav-label">Disputes & Tickets</div>
              <div class="nav-desc">User complaint queue</div>
            </div>
            @if(isset($openComplaints) && $openComplaints > 0)
              <span class="pill-badge" style="background-color: #E11D48; color: #FFFFFF;">{{ $openComplaints }} Open</span>
            @endif
          </a>
          @endif

          @if($admin->isSuperAdmin())
          <a href="{{ route('admin.staff') }}" class="nav-btn {{ request()->routeIs('admin.staff') ? 'active' : '' }}">
            <span class="icon-wrap">⚙️</span>
            <div style="flex: 1; text-align: left;">
              <div class="nav-label">Staff & RBAC Roles</div>
              <div class="nav-desc">Admin permissions & team</div>
            </div>
          </a>
          @endif
        </nav>
      </div>

      <!-- Live Gateway Status Box -->
      <div class="system-box">
        <div style="display: flex; align-items: center;">
          <span class="status-pulse"></span>
          <span class="status-text">Live Gateway Connected</span>
        </div>
        <div class="system-sub">Bigisub & NIBSS rails nominal</div>
      </div>

      <!-- Logout Button -->
      <div class="sidebar-footer">
        <form action="{{ route('admin.logout') }}" method="POST">
          @csrf
          <button type="submit" class="logout-btn">
            <span>🚪</span>
            <span>Exit Admin Portal</span>
          </button>
        </form>
      </div>
    </aside>

    <!-- Main Content Area -->
    <div class="main-wrapper">
      <header class="header">
        <div style="display: flex; align-items: center; gap: 12px;">
          <button type="button" class="hamburger-btn" onclick="toggleMobileSidebar()" aria-label="Open menu">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <line x1="3" y1="12" x2="21" y2="12"></line>
              <line x1="3" y1="6" x2="21" y2="6"></line>
              <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
          </button>
          <div class="header-left">
            <h1 class="header-title">@yield('page_title', 'Administrative Command Center')</h1>
            <p class="header-sub">@yield('page_sub', 'Gutter Financial Operations & System Governance')</p>
          </div>
        </div>

        <div class="header-right">
          <!-- Role Switcher for previewing roles live -->
          <div class="role-switcher">
            <span class="role-label">Active Role:</span>
            <select class="role-select" onchange="window.location.href='?preview_role=' + this.value">
              <option value="super_admin" {{ $admin->role === 'super_admin' ? 'selected' : '' }}>👑 Super Admin (Full Control)</option>
              <option value="compliance_officer" {{ $admin->role === 'compliance_officer' ? 'selected' : '' }}>🛡️ Compliance Officer (KYC Focus)</option>
              <option value="support_lead" {{ $admin->role === 'support_lead' ? 'selected' : '' }}>🎧 Support Lead (Disputes & Tickets)</option>
              <option value="financial_auditor" {{ $admin->role === 'financial_auditor' ? 'selected' : '' }}>📊 Financial Auditor (Ledger & Reports)</option>
            </select>
          </div>

          <!-- Notification Center Bell -->
          <div class="notif-dropdown-wrap">
            <button type="button" class="notif-bell-btn" onclick="toggleNotificationMenu(event)" aria-label="Notifications" title="System Notifications">
              <span>🔔</span>
              @if(isset($totalAdminNotifications) && $totalAdminNotifications > 0)
                <span class="notif-badge-count">{{ $totalAdminNotifications }}</span>
              @endif
            </button>
            <div class="notif-menu" id="notifDropdown">
              <div class="notif-header">
                <div style="font-weight: 800; font-size: 13px; color: var(--navy-deep); display: flex; align-items: center; gap: 6px;">
                  <span>🔔 Notifications</span>
                  @if(isset($totalAdminNotifications) && $totalAdminNotifications > 0)
                    <span class="pill-badge" style="background-color: var(--red); color: #FFFFFF;">{{ $totalAdminNotifications }} Action Items</span>
                  @endif
                </div>
                <button type="button" onclick="closeNotificationMenu()" style="background: none; border: none; font-size: 16px; cursor: pointer; color: var(--ink-soft);">✕</button>
              </div>
              <div class="notif-list">
                @if(isset($pendingKyc) && $pendingKyc > 0)
                  <a href="{{ route('admin.kyc') }}" class="notif-item">
                    <div class="notif-item-icon" style="background-color: #FEF3C7; color: #D97706;">⏳</div>
                    <div style="flex: 1; min-width: 0;">
                      <div style="font-size: 12.5px; font-weight: 700; color: var(--navy-deep);">Pending KYC Approvals</div>
                      <div style="font-size: 11px; color: var(--ink-soft); margin-top: 1px;">{{ $pendingKyc }} customer verification documents awaiting review</div>
                    </div>
                    <span class="pill-badge" style="background-color: #FEF3C7; color: #B45309;">Review &rarr;</span>
                  </a>
                @endif

                @if(isset($openComplaints) && $openComplaints > 0)
                  <a href="{{ route('admin.complaints') }}" class="notif-item">
                    <div class="notif-item-icon" style="background-color: #FFE4E6; color: #E11D48;">🎧</div>
                    <div style="flex: 1; min-width: 0;">
                      <div style="font-size: 12.5px; font-weight: 700; color: var(--navy-deep);">Open Support Disputes</div>
                      <div style="font-size: 11px; color: var(--ink-soft); margin-top: 1px;">{{ $openComplaints }} user tickets awaiting admin investigation</div>
                    </div>
                    <span class="pill-badge" style="background-color: #FFE4E6; color: #E11D48;">Resolve &rarr;</span>
                  </a>
                @endif

                @if(isset($frozenCount) && $frozenCount > 0)
                  <a href="{{ route('admin.users', ['filter' => 'frozen']) }}" class="notif-item">
                    <div class="notif-item-icon" style="background-color: #E0F2FE; color: #0284C7;">❄️</div>
                    <div style="flex: 1; min-width: 0;">
                      <div style="font-size: 12.5px; font-weight: 700; color: var(--navy-deep);">Frozen Accounts (PND)</div>
                      <div style="font-size: 11px; color: var(--ink-soft); margin-top: 1px;">{{ $frozenCount }} accounts under Post-No-Debit restrictions</div>
                    </div>
                    <span class="pill-badge" style="background-color: #E0F2FE; color: #0369A1;">View &rarr;</span>
                  </a>
                @endif

                @if(isset($suspendedCount) && $suspendedCount > 0)
                  <a href="{{ route('admin.users', ['filter' => 'suspended']) }}" class="notif-item">
                    <div class="notif-item-icon" style="background-color: #FEE2E2; color: #DC2626;">🚫</div>
                    <div style="flex: 1; min-width: 0;">
                      <div style="font-size: 12.5px; font-weight: 700; color: var(--navy-deep);">Suspended / Blocked</div>
                      <div style="font-size: 11px; color: var(--ink-soft); margin-top: 1px;">{{ $suspendedCount }} accounts with active login locks</div>
                    </div>
                    <span class="pill-badge" style="background-color: #FEE2E2; color: #991B1B;">Audit &rarr;</span>
                  </a>
                @endif

                @if((!isset($totalAdminNotifications) || $totalAdminNotifications === 0) && (!isset($frozenCount) || $frozenCount === 0) && (!isset($suspendedCount) || $suspendedCount === 0))
                  <div style="padding: 32px 20px; text-align: center; color: var(--ink-soft);">
                    <div style="font-size: 26px; margin-bottom: 6px;">✨</div>
                    <div style="font-weight: 700; font-size: 13px; color: var(--navy);">All Queues Clear</div>
                    <p style="font-size: 11.5px; margin-top: 2px;">No pending KYC, complaints, or critical alerts.</p>
                  </div>
                @endif
              </div>
              <div class="notif-footer">
                <a href="{{ route('admin.dashboard') }}" style="font-size: 11.5px; font-weight: 700; color: var(--navy); text-decoration: none;">
                  Executive Health nominal &bull; Gutter Command v2.4
                </a>
              </div>
            </div>
          </div>

          <!-- Live Indicator -->
          <div class="live-indicator">
            <span class="live-dot"></span>
            <span class="live-text">Lagos Cluster 01</span>
          </div>
        </div>
      </header>

      <main class="main-content">
        @if(session('success'))
          <div class="alert-success">
            ✓ {{ session('success') }}
          </div>
        @endif

        @if(session('error'))
          <div class="alert-danger">
            ⚠️ {{ session('error') }}
          </div>
        @endif

        @yield('content')
      </main>
    </div>
  </div>

  <script>
    function toggleMobileSidebar() {
      const sb = document.getElementById('appSidebar');
      const ov = document.getElementById('mobileOverlay');
      if (sb && ov) {
        sb.classList.toggle('mobile-open');
        ov.classList.toggle('active');
      }
    }

    function toggleNotificationMenu(event) {
      if (event) event.stopPropagation();
      const menu = document.getElementById('notifDropdown');
      if (menu) {
        menu.classList.toggle('show');
      }
    }

    function closeNotificationMenu() {
      const menu = document.getElementById('notifDropdown');
      if (menu) {
        menu.classList.remove('show');
      }
    }

    document.addEventListener('click', function(e) {
      if (!e.target.closest('.notif-dropdown-wrap') && !e.target.closest('#sidebarNotifBtn')) {
        closeNotificationMenu();
      }
    });
  </script>

  @yield('scripts')
</body>
</html>
