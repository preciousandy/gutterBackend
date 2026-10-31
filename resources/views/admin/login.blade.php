<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Gutter | Financial Administration & Operations Command Center</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='25' fill='%2312306E'/><circle cx='50' cy='50' r='24' fill='%2323D3A5'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      --font-mono: 'JetBrains Mono', monospace;
      
      --navy-deep: #0E2050;
      --navy: #12306E;
      --navy-light: #14367A;
      --navy-darker: #08122E;
      
      --mint: #23D3A5;
      --mint-dark: #0FA97F;
      --mint-bg: #E8FBF4;
      --mint-on: #062B22;

      --surface: #FFFFFF;
      --surface-alt: #F8FAFC;
      --chip: #EEF2FB;
      
      --line: #E2E8F0;
      --line-focus: #23D3A5;
      
      --ink: #0E2050;
      --ink-mid: #475569;
      --ink-soft: #64748B;
      --ink-faint: #94A3B8;

      --red: #E11D48;
      --red-bg: #FFE4E6;
      --amber: #D97706;
      --blue: #2563EB;
      --purple: #7C3AED;
      
      --radius-sm: 8px;
      --radius-md: 12px;
      --radius-lg: 16px;
      --radius-xl: 22px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      -webkit-tap-highlight-color: transparent;
    }

    body {
      font-family: var(--font-sans);
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: #0E2050;
      background-image: 
        radial-gradient(circle at 50% 10%, rgba(35, 211, 165, 0.12) 0%, transparent 45%),
        radial-gradient(circle at 20% 80%, rgba(20, 54, 122, 0.4) 0%, transparent 50%),
        radial-gradient(circle at 80% 30%, rgba(14, 32, 80, 0.8) 0%, #08122E 100%);
      padding: 32px 16px;
      color: var(--ink);
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
    }

    /* Container wrapper with constrained max-width */
    .content-wrap {
      width: 100%;
      max-width: 480px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 22px;
    }

    /* Brand Header */
    .brand-header {
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 100%;
    }

    .logo-badge {
      width: 56px;
      height: 56px;
      border-radius: 18px;
      background: linear-gradient(135deg, #23D3A5 0%, #0FA97F 100%);
      color: var(--navy-deep);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 30px;
      font-weight: 800;
      box-shadow: 0 10px 25px rgba(35, 211, 165, 0.35);
      margin-bottom: 14px;
      border: 2px solid rgba(255, 255, 255, 0.25);
    }

    .title {
      font-size: 26px;
      font-weight: 800;
      color: #FFFFFF;
      letter-spacing: -0.5px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .title-highlight {
      color: var(--mint);
    }

    .subtitle {
      font-size: 13px;
      color: #94A3B8;
      margin-top: 6px;
      max-width: 380px;
      text-align: center;
      line-height: 1.45;
    }

    /* Card styling */
    .login-card {
      width: 100%;
      background-color: var(--surface);
      border-radius: var(--radius-xl);
      box-shadow: 
        0 25px 60px -15px rgba(0, 0, 0, 0.5),
        0 0 0 1px rgba(255, 255, 255, 0.1);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      transition: transform 0.2s ease;
    }

    .form-section {
      padding: 32px 32px 24px 32px;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    .card-title {
      font-size: 17px;
      font-weight: 800;
      color: var(--navy-deep);
      text-align: center;
      letter-spacing: -0.2px;
    }

    .error-box {
      background-color: var(--red-bg);
      border: 1px solid #FECDD3;
      color: var(--red);
      padding: 11px 14px;
      border-radius: 12px;
      font-size: 13px;
      font-weight: 600;
      text-align: center;
    }

    .input-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .label {
      font-size: 12.5px;
      font-weight: 700;
      color: var(--ink);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .input-wrap {
      display: flex;
      align-items: center;
      gap: 12px;
      border: 1.5px solid var(--line);
      border-radius: 14px;
      padding: 12px 14px;
      background-color: var(--surface-alt);
      transition: all 0.2s ease;
    }

    .input-wrap:focus-within {
      border-color: var(--mint-dark);
      background-color: #FFFFFF;
      box-shadow: 0 0 0 3px rgba(35, 211, 165, 0.2);
    }

    .input-icon {
      color: var(--ink-soft);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .input {
      border: none;
      background: transparent;
      outline: none;
      font-size: 14px;
      color: var(--ink);
      width: 100%;
      font-family: inherit;
      font-weight: 500;
    }

    .input::placeholder {
      color: var(--ink-faint);
    }

    .submit-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      padding: 14px 20px;
      background: linear-gradient(135deg, #14367A 0%, #0E2050 100%);
      color: #FFFFFF;
      border: none;
      border-radius: 14px;
      font-size: 14.5px;
      font-weight: 700;
      cursor: pointer;
      margin-top: 6px;
      box-shadow: 0 6px 16px rgba(18, 48, 110, 0.35);
      transition: all 0.18s ease;
      min-height: 48px;
    }

    .submit-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 20px rgba(18, 48, 110, 0.45);
    }

    .submit-btn:active {
      transform: translateY(0);
    }

    /* Demo Quick Role Selector */
    .demo-section {
      border-top: 1px solid var(--line);
      background-color: #F8FAFC;
      padding: 22px 24px;
    }

    .demo-header {
      font-size: 12px;
      font-weight: 700;
      color: var(--navy-deep);
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .demo-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    .demo-btn {
      border: 1.5px solid var(--line);
      border-radius: 12px;
      padding: 10px 12px;
      text-align: left;
      cursor: pointer;
      background-color: var(--surface);
      font-family: inherit;
      transition: all 0.15s ease;
      display: flex;
      flex-direction: column;
      gap: 3px;
    }

    .demo-btn:hover {
      border-color: var(--navy);
      background-color: var(--chip);
      transform: translateY(-1px);
    }

    .demo-btn.active {
      border-color: var(--mint-dark);
      background-color: var(--mint-bg);
    }

    .demo-btn-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 4px;
    }

    .demo-role-name {
      font-size: 12px;
      font-weight: 800;
      color: var(--navy-deep);
    }

    .demo-badge {
      font-size: 9px;
      font-weight: 800;
      padding: 2px 6px;
      border-radius: 6px;
      letter-spacing: 0.3px;
      text-transform: uppercase;
    }

    .demo-desc {
      font-size: 10px;
      color: var(--ink-soft);
      line-height: 1.35;
    }

    .footer-note {
      font-size: 11.5px;
      color: #7C8AA6;
      text-align: center;
      letter-spacing: 0.2px;
    }

    /* Mobile Responsive Media Queries */
    @media (max-width: 520px) {
      body {
        padding: 20px 12px;
      }
      .content-wrap {
        gap: 16px;
      }
      .logo-badge {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        font-size: 24px;
        margin-bottom: 10px;
      }
      .title {
        font-size: 22px;
      }
      .subtitle {
        font-size: 12px;
      }
      .form-section {
        padding: 24px 20px 20px 20px;
        gap: 16px;
      }
      .demo-section {
        padding: 18px 18px;
      }
      .demo-grid {
        grid-template-columns: 1fr;
        gap: 8px;
      }
      .demo-btn {
        padding: 10px 12px;
      }
      .demo-btn-top {
        margin-bottom: 2px;
      }
      .input-wrap {
        padding: 10px 12px;
      }
    }
  </style>
</head>
<body>
  <div class="content-wrap">
    <!-- Brand Header -->
    <div class="brand-header">
      <div class="logo-badge">G</div>
      <h1 class="title">Gutter <span class="title-highlight">Command</span></h1>
      <p class="subtitle">Financial Administration, Operational Governance & Real-Time App Controls</p>
    </div>

    <!-- Login Card -->
    <div class="login-card">
      <form action="{{ route('admin.login.submit') }}" method="POST" class="form-section">
        @csrf
        <div class="card-title">Sign in to Administrative Console</div>

        @if(session('error'))
          <div class="error-box">
            {{ session('error') }}
          </div>
        @endif

        <!-- Email Field -->
        <div class="input-group">
          <label class="label" for="emailInput">Admin Email Address</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="20" height="16" x="2" y="4" rx="2"/>
                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
              </svg>
            </span>
            <input
              type="email"
              name="email"
              id="emailInput"
              required
              value="{{ old('email', 'admin@gutter.com') }}"
              placeholder="admin@gutter.com"
              class="input"
              autocomplete="email"
            >
          </div>
        </div>

        <!-- Password Field -->
        <div class="input-group">
          <label class="label" for="passwordInput">Password</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
            </span>
            <input
              type="password"
              name="password"
              id="passwordInput"
              required
              value="Admin@123456"
              placeholder="••••••••••••"
              class="input"
              autocomplete="current-password"
            >
          </div>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="submit-btn">
          <span>Enter Admin Console</span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14"/>
            <path d="m12 5 7 7-7 7"/>
          </svg>
        </button>
      </form>

      <!-- Quick Demo Role Selectors -->
      <div class="demo-section">
        <div class="demo-header">
          <span>✨ Click to test specific roles & permissions:</span>
        </div>

        <div class="demo-grid">
          <!-- Super Admin -->
          <button type="button" class="demo-btn active" id="btn-admin" onclick="fillCreds('admin@gutter.com', 'Admin@123456', 'btn-admin')">
            <div class="demo-btn-top">
              <span class="demo-role-name">Super Admin</span>
              <span class="demo-badge" style="background-color: var(--mint-bg); color: var(--mint-dark);">Full Controls</span>
            </div>
            <div class="demo-desc">Controls all app switches, overrides, KYC, complaints & staff.</div>
          </button>

          <!-- Compliance -->
          <button type="button" class="demo-btn" id="btn-compliance" onclick="fillCreds('compliance@gutter.com', 'Compliance@123456', 'btn-compliance')">
            <div class="demo-btn-top">
              <span class="demo-role-name">Compliance</span>
              <span class="demo-badge" style="background-color: #EFF6FF; color: #2563EB;">KYC & Tiers</span>
            </div>
            <div class="demo-desc">Reviews Tier 2 BVN/NIN, approves/rejects KYC, flags accounts.</div>
          </button>

          <!-- Support Lead -->
          <button type="button" class="demo-btn" id="btn-support" onclick="fillCreds('support@gutter.com', 'Support@123456', 'btn-support')">
            <div class="demo-btn-top">
              <span class="demo-role-name">Support Lead</span>
              <span class="demo-badge" style="background-color: #FEF3C7; color: #D97706;">Help Tickets</span>
            </div>
            <div class="demo-desc">Investigates customer dispute tickets, writes resolution notes.</div>
          </button>

          <!-- Auditor -->
          <button type="button" class="demo-btn" id="btn-auditor" onclick="fillCreds('auditor@gutter.com', 'Auditor@123456', 'btn-auditor')">
            <div class="demo-btn-top">
              <span class="demo-role-name">Auditor</span>
              <span class="demo-badge" style="background-color: #F3E8FF; color: #7C3AED;">Read-Only</span>
            </div>
            <div class="demo-desc">Monitors wallet floats, daily settlement totals, exports reports.</div>
          </button>
        </div>
      </div>
    </div>

    <!-- Security Footer Note -->
    <div class="footer-note">
      Gutter Financial Technologies Ltd • 256-bit Encrypted Admin Session
    </div>
  </div>

  <script>
    function fillCreds(email, pass, btnId) {
      document.getElementById('emailInput').value = email;
      document.getElementById('passwordInput').value = pass;
      
      document.querySelectorAll('.demo-btn').forEach(function(b) {
        b.classList.remove('active');
      });
      if (btnId) {
        document.getElementById(btnId).classList.add('active');
      }
    }
  </script>
</body>
</html>
