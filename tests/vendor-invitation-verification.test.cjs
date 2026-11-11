const { test } = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs"),
  vm = require("node:vm");
class Element {
  constructor() {
    this.children = [];
    this.dataset = {};
    this.isConnected = true;
    this.textContent = "";
  }
  append(...nodes) {
    this.children.push(...nodes);
  }
  replaceChildren(...nodes) {
    this.children = nodes;
  }
  setAttribute() {}
  removeAttribute() {}
  get text() {
    return this.textContent + this.children.map((n) => n.text).join("");
  }
}
function fixture(user) {
  const window = {},
    root = new Element(),
    calls = [],
    notices = [];
  const current = { user, hold: null };
  const node = (_tag, text = "") =>
    Object.assign(new Element(), { textContent: text });
  vm.runInNewContext(
    fs.readFileSync("services/business-api/public/vendor-ops.js", "utf8"),
    {
      window,
      document: { createElement: node },
      AbortController,
      URLSearchParams,
      URL,
    },
  );
  const workspace = window.createVendorWorkspace({
    root,
    ui: node,
    actor: () => current.user,
    notice: (e) => notices.push(e),
    expired: () => {},
    api: async (path) => {
      calls.push(path);
      if (path === "regions") return [];
      if (path.startsWith("vendor-ops/companies?") && current.hold)
        await current.hold;
      return { data: [], current_page: 1, last_page: 1, total: 0 };
    },
  });
  return { workspace, current, root, calls, notices };
}
test("unverified customer keeps company access without fetching protected invitation names", async () => {
  const f = fixture({ id: 1, role: "customer", email_verified_at: null });
  await f.workspace.setActor(f.current.user);
  assert(f.calls.some((p) => p.startsWith("vendor-ops/companies?")));
  assert(!f.calls.some((p) => p.includes("staff-invitations")));
  assert.match(f.root.text, /Verify your email before viewing/);
  assert.deepEqual(f.notices, []);
});
test("verified account fetches its own staff invitation page", async () => {
  const f = fixture({
    id: 1,
    role: "customer",
    email_verified_at: "2026-11-11T00:00:00Z",
  });
  await f.workspace.setActor(f.current.user);
  assert(f.calls.includes("vendor-ops/staff-invitations?page=1"));
  assert.deepEqual(f.notices, []);
});
test("fresh verified identity refresh enables invitation loading", async () => {
  const f = fixture({ id: 1, role: "customer" });
  await f.workspace.setActor(f.current.user);
  f.current.user = {
    ...f.current.user,
    email_verified_at: "2026-11-11T00:00:00Z",
  };
  await f.workspace.setActor(f.current.user);
  assert.equal(
    f.calls.filter((p) => p.includes("staff-invitations")).length,
    1,
  );
  assert(!f.root.text.includes("Verify your email before viewing"));
});
test("delayed old-account company response cannot load invitations for the new unverified account", async () => {
  const f = fixture({
    id: 1,
    role: "customer",
    email_verified_at: "2026-11-11T00:00:00Z",
  });
  let release;
  f.current.hold = new Promise((resolve) => (release = resolve));
  const old = f.workspace.setActor(f.current.user);
  await new Promise((resolve) => setImmediate(resolve));
  f.current.hold = null;
  f.current.user = { id: 2, role: "customer" };
  await f.workspace.setActor(f.current.user);
  release();
  await old;
  assert(!f.calls.some((p) => p.includes("staff-invitations")));
  assert.deepEqual(f.notices, []);
});
