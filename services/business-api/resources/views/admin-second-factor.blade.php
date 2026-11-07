<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Administrator verification · Crea-Biz EV</title>
  <link rel="stylesheet" href="/platform.css">
  <script src="/admin-second-factor.js" defer></script>
</head>
<body>
<main id="workspace" style="max-width:42rem;margin:2rem auto;padding:1rem">
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
    <button id="factor-signout" type="button">Sign out / تسجيل الخروج</button>
  </section>
</main>
</body>
</html>
