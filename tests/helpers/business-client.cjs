// Loopback-only synthetic integration client, not a production application client.
const assert = require("node:assert/strict");
const { randomUUID } = require("node:crypto");
const { promisify } = require("node:util");
const execFile = promisify(require("node:child_process").execFile);
class BusinessClient {
  constructor({
    base = "http://127.0.0.1:8000",
    fetch = globalThis.fetch,
    runCommand = execFile,
    wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
  } = {}) {
    assert(
      ["127.0.0.1", "localhost"].includes(new URL(base).hostname),
      "Synthetic business QA is loopback-only.",
    );
    this.base = base;
    this.fetch = fetch;
    this.runCommand = runCommand;
    this.wait = wait;
    this.cookies = new Map();
    this.csrf = null;
    this.user = null;
    this.testCredentials = null;
  }
  async request(path, method = "GET", body) {
    // Snapshot once so a rate-limit wait cannot alter an idempotency payload.
    const raw = body === undefined ? undefined : JSON.stringify(body);
    for (let attempt = 0; attempt < 2; attempt++) {
      let r;
      try {
        r = await this.fetch(this.base + "/api/" + path, {
          method,
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            Cookie: [...this.cookies].map(([k, v]) => k + "=" + v).join("; "),
            ...(this.csrf ? { "X-CSRF-TOKEN": this.csrf } : {}),
          },
          body: raw,
          signal: AbortSignal.timeout(30000),
        });
      } catch (error) {
        // Unknown transport outcomes must never replay a payment, charging or
        // diagnostic mutation. Report only the sanitized low-level error code:
        // request paths, credentials, headers and payloads stay out of CI logs.
        const rawCode = error?.cause?.code ?? error?.code;
        const code =
          typeof rawCode === "string" && /^[A-Z][A-Z0-9_]{1,40}$/.test(rawCode)
            ? rawCode
            : error?.name === "TimeoutError"
              ? "TIMEOUT"
              : "NETWORK_FAILURE";
        const verb = /^(GET|POST|PUT|PATCH|DELETE)$/.test(method)
          ? method
          : "REQUEST";
        throw new Error(
          "Synthetic loopback " +
            verb +
            " transport failed (" +
            code +
            "); outcome unknown, no request replay",
        );
      }
      for (const cookie of r.headers.getSetCookie()) {
        const kv = cookie.split(";")[0],
          n = kv.indexOf("=");
        this.cookies.set(kv.slice(0, n), kv.slice(n + 1));
      }
      const data = r.status === 204 ? null : await r.json();
      // Received 429 means the middleware rejected the request before execution.
      // Unknown transport errors/503s are deliberately not replayed.
      if (r.status === 429 && attempt === 0) {
        const seconds = Number(r.headers.get("retry-after") || 60);
        assert(
          Number.isFinite(seconds) && seconds >= 0 && seconds <= 60,
          "Invalid integration Retry-After bound.",
        );
        await this.wait((seconds + 1) * 1000);
        continue;
      }
      assert(
        r.ok,
        method +
          " " +
          path +
          " failed (" +
          r.status +
          "): " +
          (data?.message || "Request rejected"),
      );
      return data;
    }
  }
  async verifyEmail() {
    await this.request("email/verification-notification", "POST");
    const { stdout } = await this.runCommand("./scripts/php", [
      "-r",
      `
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
      Illuminate\\Support\\Facades\\URL::forceRootUrl($argv[2]);
      $user = App\\Models\\User::where("email", $argv[1])->firstOrFail();
      echo (new Illuminate\\Auth\\Notifications\\VerifyEmail)->toMail($user)->actionUrl;
    `,
      "--",
      this.user.email,
      this.base,
    ]);
    const url = new URL(stdout.trim());
    assert.equal(url.origin, new URL(this.base).origin);
    const response = await this.fetch(url, {
      headers: {
        Cookie: [...this.cookies].map(([k, v]) => k + "=" + v).join("; "),
      },
      signal: AbortSignal.timeout(30000),
    });
    assert.equal(response.status, 200, "Synthetic signed verification failed");
    for (const cookie of response.headers.getSetCookie()) {
      const pair = cookie.split(";")[0],
        index = pair.indexOf("=");
      this.cookies.set(pair.slice(0, index), pair.slice(index + 1));
    }
    assert(
      (await this.request("me")).email_verified_at,
      "Normal signed verification must precede Admin promotion",
    );
  }
  async changeRole(role) {
    assert(
      this.testCredentials,
      "Only accounts created by this synthetic client may change role",
    );
    if (role === "admin" && !(await this.request("me")).email_verified_at)
      await this.verifyEmail();
    try {
      await this.runCommand("./scripts/php", [
        "artisan",
        "platform:role",
        this.testCredentials.email,
        role,
      ]);
    } catch (error) {
      // Never expose CLI output containing synthetic credentials or database rows.
      const sqlstate = (
        String(error.stdout || "") + String(error.stderr || "")
      ).match(/SQLSTATE\[([A-Z0-9]{5})\]/);
      throw new Error(
        "Synthetic role provisioning failed (exit " +
          Number(error.code || 1) +
          "; " +
          (sqlstate ? "SQLSTATE " + sqlstate[1] : "SQLSTATE unavailable") +
          ")",
      );
    }
    // Role changes revoke the previous authorization epoch. Exercise normal login.
    this.csrf = (await this.request("csrf")).token;
    this.user = await this.request("login", "POST", this.testCredentials);
    this.csrf = (await this.request("csrf")).token;
    assert.equal(this.user.role, role);
    return this;
  }
  async register(role) {
    this.csrf = (await this.request("csrf")).token;
    const email = "protocol-" + role + "-" + randomUUID() + "@example.test";
    const password = randomUUID() + "aA9!";
    this.testCredentials = { email, password };
    this.user = await this.request("register", "POST", {
      name: "Protocol QA " + role,
      email,
      password,
    });
    this.csrf = (await this.request("csrf")).token;
    if (role !== "customer") await this.changeRole(role);
    return this;
  }
}
module.exports = { BusinessClient };
