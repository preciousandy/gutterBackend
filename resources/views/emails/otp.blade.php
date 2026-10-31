<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gutter Verification Code</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6fb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; color: #1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f4f6fb; padding: 40px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" style="max-width: 520px; background-color: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 30px rgba(18, 48, 110, 0.08); border: 1px solid #e2e8f0;">
          <!-- Header with Navy Gradient -->
          <tr>
            <td style="background: linear-gradient(135deg, #0e2050 0%, #12306e 100%); padding: 36px 32px; text-align: center;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center">
                    <div style="display: inline-block; width: 44px; height: 44px; line-height: 44px; border-radius: 14px; background: rgba(35, 211, 165, 0.15); border: 1px solid rgba(35, 211, 165, 0.4); text-align: center; color: #23d3a5; font-size: 24px; font-weight: 800;">
                      G
                    </div>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding-top: 14px;">
                    <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #ffffff; letter-spacing: -0.5px;">Gutter</h1>
                    <p style="margin: 4px 0 0; font-size: 13px; color: rgba(255, 255, 255, 0.65);">Smart banking. Better future.</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Main Content -->
          <tr>
            <td style="padding: 36px 32px 28px;">
              <h2 style="margin: 0 0 12px; font-size: 20px; font-weight: 700; color: #0e2050; letter-spacing: -0.3px;">
                Verify your email address
              </h2>
              <p style="margin: 0 0 24px; font-size: 14px; line-height: 22px; color: #475569;">
                Hi {{ $name }}, welcome to Gutter! Use the 6-digit verification code below to complete your registration.
              </p>

              <!-- OTP Code Display Card -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin: 24px 0;">
                <tr>
                  <td align="center">
                    <div style="display: inline-block; background-color: #f1f5f9; border: 2px dashed #cbd5e1; border-radius: 16px; padding: 18px 36px;">
                      <span style="font-family: 'SF Mono', Monaco, Consolas, 'Courier New', monospace; font-size: 34px; font-weight: 800; letter-spacing: 8px; color: #12306e;">
                        {{ $code }}
                      </span>
                    </div>
                  </td>
                </tr>
              </table>

              <!-- Expiry Alert -->
              <div style="background-color: #f0fdf4; border-left: 4px solid #23d3a5; padding: 12px 16px; border-radius: 8px; margin-bottom: 24px;">
                <p style="margin: 0; font-size: 13px; line-height: 19px; color: #166534;">
                  <strong>Note:</strong> This code will expire in <strong>{{ $expiresInMinutes }} minutes</strong>. For your security, never share this code with anyone.
                </p>
              </div>

              <p style="margin: 0; font-size: 13px; line-height: 20px; color: #64748b;">
                If you didn't create an account with Gutter, you can safely ignore this email.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding: 24px 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center;">
              <p style="margin: 0; font-size: 12px; color: #94a3b8; line-height: 18px;">
                &copy; {{ date('Y') }} Gutter Technologies Ltd. All rights reserved.<br>
                Secure digital banking & payments.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
