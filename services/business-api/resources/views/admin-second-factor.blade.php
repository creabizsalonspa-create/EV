<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Administrator verification · Crea-Biz EV</title>
  <link rel="stylesheet" href="/platform.css">
  <link rel="stylesheet" href="/admin-second-factor.css">
  <script src="/admin-second-factor.js" defer></script>
</head>
<body>
<main id="workspace" class="admin-factor-wrap">
  <section aria-labelledby="factor-title">
    <h1 id="factor-title">Administrator verification / التحقق من هوية المشرف</h1>
    <p>Required for administrator operations. Use a six-digit code from an authenticator app; this is not physical charger or payment authorization.</p>
    <p id="factor-status" role="status" aria-live="polite">Checking your administrator session…</p>
    <form id="factor-begin" hidden>
      <h2>Enroll an authenticator / إعداد تطبيق المصادقة</h2>
      <label>Confirm account password / تأكيد كلمة المرور
        <input name="password" type="password" autocomplete="current-password" required maxlength="128">
      </label>
      <button type="submit">Create private authenticator secret</button>
    </form>
    <section id="factor-secret" hidden aria-label="Authenticator setup secret">
      <p>Store this key privately in your authenticator app. It is shown only during enrollment. Do not share or screenshot it.</p>
      <p><strong>Manual setup key:</strong> <code id="factor-setup-key"></code></p>
      <form id="factor-confirm">
        <label>Six-digit code / رمز مكوّن من ٦ أرقام
          <input name="code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required>
        </label>
        <button type="submit">Confirm authenticator enrollment</button>
      </form>
    </section>
    <form id="factor-verify" hidden>
      <h2>Verify your authenticator / تحقق من تطبيق المصادقة</h2>
      <label>Six-digit code / رمز مكوّن من ٦ أرقام
        <input name="code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required>
      </label>
      <button type="submit">Verify and open Admin workspace</button>
    </form>
    <form id="factor-rotate" hidden>
      <h2>Replace authenticator backup codes / استبدال رموز الاسترداد</h2>
      <p>Only use this while you still have your authenticator. All old recovery codes become invalid immediately; your authenticator device stays enrolled.</p>
      <label>Confirm account password / تأكيد كلمة المرور
        <input name="password" type="password" autocomplete="current-password" maxlength="128" required>
      </label>
      <label>New six-digit authenticator code / رمز تحقق جديد
        <input name="code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required>
      </label>
      <button type="submit">Replace all eight backup codes</button>
    </form>
    <section id="factor-recovery-codes" hidden aria-label="Private recovery codes">
      <h2>Save these eight recovery codes now / احتفظ برموز الاسترداد</h2>
      <p>These single-use codes are displayed only once. Save them in a secure password manager, separate from your authenticator device.</p>
      <pre id="factor-recovery-list" aria-label="Your one-time recovery codes"></pre>
      <button type="button" id="factor-codes-saved">I have securely saved these codes</button>
    </section>
    <form id="factor-recover" hidden>
      <h2>Use a saved recovery code / استخدام رمز الاسترداد</h2>
      <label>20-character recovery code / رمز الاسترداد
        <input name="recovery_code" type="text" pattern="[a-fA-F0-9]{20}" minlength="20" maxlength="20" autocomplete="off" spellcheck="false" required>
      </label>
      <button type="submit">Use recovery code</button>
    </form>
    <form id="factor-reset" hidden>
      <h2>Replace your lost authenticator</h2>
      <p>A recovery code has been used. Confirm your account password to reset and enroll a replacement authenticator. Old codes will be invalidated.</p>
      <label>Account password / كلمة المرور
        <input name="password" type="password" autocomplete="current-password" maxlength="128" required>
      </label>
      <button type="submit">Reset authenticator and enroll a new device</button>
    </form>
    <button id="factor-signout" type="button">Sign out / تسجيل الخروج</button>
  </section>
</main>
</body>
</html>
