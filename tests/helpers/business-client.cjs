// Loopback-only synthetic integration client, not a production application client.
const assert = require("node:assert/strict");
const { randomUUID } = require("node:crypto");
const { promisify } = require("node:util");
const execFile = promisify(require("node:child_process").execFile);
class BusinessClient {
  constructor({
    base = "http://127.0.0.1:8000",
    fetch = globalThis.fetch,
    wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
  } = {}) {
    assert(
      ["127.0.0.1", "localhost"].includes(new URL(base).hostname),
      "Synthetic business QA is loopback-only.",
    );
    this.base = base;
    this.fetch = fetch;
    this.wait = wait;
    this.cookies = new Map();
    this.csrf = null;
    this.user = null;
  }
  async request(path, method = "GET", body) {
    // Snapshot once so a rate-limit wait cannot alter an idempotency payload.
    const raw = body === undefined ? undefined : JSON.stringify(body);
    for (let attempt = 0; attempt < 2; attempt++) {
      const r = await this.fetch(this.base + "/api/" + path, {
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
  async register(role) {
    this.csrf = (await this.request("csrf")).token;
    const email = "protocol-" + role + "-" + randomUUID() + "@example.test";
    this.user = await this.request("register", "POST", {
      name: "Protocol QA " + role,
      email,
      password: randomUUID() + "aA9!",
    });
    this.csrf = (await this.request("csrf")).token;
    if (role !== "customer")
      await execFile("./scripts/php", [
        "artisan",
        "platform:role",
        email,
        role,
      ]);
    return this;
  }
}
module.exports = { BusinessClient };
