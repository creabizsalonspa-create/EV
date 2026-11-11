const { test } = require("node:test");
const assert = require("node:assert/strict");
const { BusinessClient } = require("./helpers/business-client.cjs");
function fixture({ fail = false } = {}) {
  const calls = [];
  let role = "customer";
  const c = new BusinessClient({
    runCommand: async (_cmd, args) => {
      calls.push("grant:" + args.at(-1));
      if (fail)
        throw Object.assign(new Error("PRIVATE CLI OUTPUT"), { code: 2 });
      role = args.at(-1);
      return { stdout: "" };
    },
  });
  c.testCredentials = {
    email: "synthetic@example.invalid",
    password: "private-password",
  };
  c.user = { email: c.testCredentials.email };
  c.verifyEmail = async () => calls.push("verify-signed-email");
  c.request = async (path, method, body) => {
    calls.push(path);
    if (path === "me") return { email_verified_at: null };
    if (path === "csrf") return { token: "fresh-csrf" };
    assert.equal(path, "login");
    assert.equal(method, "POST");
    assert.deepEqual(body, c.testCredentials);
    return { role };
  };
  return { c, calls };
}
test("Admin fixtures verify normally before grant then explicitly authenticate the new epoch", async () => {
  const { c, calls } = fixture();
  await c.changeRole("admin");
  assert.deepEqual(calls, [
    "me",
    "verify-signed-email",
    "grant:admin",
    "csrf",
    "login",
    "csrf",
  ]);
  assert.equal(c.user.role, "admin");
});
test("vendor fixture also signs in after revoking its prior authorization epoch", async () => {
  const { c, calls } = fixture();
  await c.changeRole("vendor");
  assert.deepEqual(calls, ["grant:vendor", "csrf", "login", "csrf"]);
});
test("failed promotion does not sign in or disclose CLI output", async () => {
  const { c, calls } = fixture({ fail: true });
  await assert.rejects(c.changeRole("admin"), {
    message:
      "Synthetic role provisioning failed (exit 2; SQLSTATE unavailable)",
  });
  assert(!calls.includes("login"));
});
test("imported accounts cannot be promoted by the synthetic client", async () => {
  const { c, calls } = fixture();
  c.testCredentials = null;
  await assert.rejects(c.changeRole("admin"), /Only accounts created/);
  assert.deepEqual(calls, []);
});
