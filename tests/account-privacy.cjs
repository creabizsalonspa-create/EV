"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const path = require("node:path");
const { test } = require("node:test");

class Element {
  constructor(tag) {
    this.tag = tag;
    this.children = [];
    this.value = "";
    this.attributes = {};
    this._text = "";
    this.disabled = false;
  }
  get textContent() {
    return this._text + this.children.map((x) => x.textContent).join("");
  }
  set textContent(value) {
    this._text = String(value);
    this.children = [];
  }
  append(...items) {
    this.children.push(...items);
  }
  replaceChildren(...items) {
    this._text = "";
    this.children = items;
  }
  setAttribute(k, v) {
    this.attributes[k] = v;
  }
}
function fixture() {
  const mine = new Element("div"),
    admin = new Element("div"),
    window = {};
  const document = {
    getElementById(id) {
      if (id === "account-privacy") return mine;
      if (id === "admin-privacy") return admin;
      throw Error("Unrecognized privacy root: " + id);
    },
    createElement(tag) {
      return new Element(tag);
    },
  };
  vm.runInNewContext(
    fs.readFileSync(
      path.join(
        __dirname,
        "../services/business-api/public/account-privacy.js",
      ),
      "utf8",
    ),
    {
      window,
      document,
      AbortController,
      URLSearchParams,
      crypto: { randomUUID: require("node:crypto").randomUUID },
    },
  );
  return { mine, admin, ...window.EvPrivacyRequests };
}
function nodes(root, tag) {
  const out = [];
  function walk(item) {
    if (item.tag === tag) out.push(item);
    item.children.forEach(walk);
  }
  walk(root);
  return out;
}
const tick = () => new Promise((resolve) => setImmediate(resolve));
const record = {
  id: 22,
  kind: "erasure",
  status: "pending",
  created_at: "2026-11-07T10:00:00Z",
};
const page = (data = [record]) => ({
  current_page: 1,
  last_page: 1,
  total: data.length,
  data,
});

test("guest context never fetches and both private views clear on signout", async () => {
  const ui = fixture(),
    calls = [];
  ui.setSelfActor(null, async (p) => {
    calls.push(p);
    return page();
  });
  ui.setAdminActor({ role: "vendor" }, async (p) => {
    calls.push(p);
    return page();
  });
  await tick();
  assert.equal(calls.length, 0);
  ui.setSelfActor({ id: 3, role: "customer" }, async (p) => {
    calls.push(p);
    return page();
  });
  ui.setAdminActor({ role: "admin" }, async (p) => {
    calls.push(p);
    return page();
  });
  await tick();
  assert.equal(calls.length, 2);
  assert.match(ui.mine.textContent, /erasure/);
  ui.setSelfActor(null, async () => {
    throw Error("must not fetch");
  });
  ui.setAdminActor(null, async () => {
    throw Error("must not fetch");
  });
  assert.equal(ui.mine.children.length, 0);
  assert.equal(ui.admin.children.length, 0);
});

test("self-service captures a real private reason without claiming automated deletion", async () => {
  const ui = fixture(),
    calls = [];
  let records = [];
  ui.setSelfActor(
    { id: 3, role: "vendor" },
    async (p, method = "GET", body) => {
      calls.push({ p, method, body });
      if (method === "POST") {
        records = [{ ...record, kind: body.kind }];
        return {
          id: 22,
          kind: body.kind,
          status: "pending",
          automated_erasure_performed: false,
        };
      }
      return page(records);
    },
  );
  await tick();
  const form = nodes(ui.mine, "form")[0],
    details = nodes(form, "textarea")[0];
  details.value = "short";
  await form.onsubmit({ preventDefault() {} });
  assert.equal(calls.filter((x) => x.method === "POST").length, 0);
  details.value =
    "Please review retained charging and account records for deletion.";
  nodes(form, "select")[0].value = "erasure";
  await form.onsubmit({ preventDefault() {} });
  await tick();
  const submitted = calls.find((x) => x.method === "POST");
  assert.ok(submitted);
  assert.equal(submitted.p, "account/privacy-requests");
  assert.equal(submitted.body.kind, "erasure");
  assert.match(submitted.body.details, /retained charging/);
  assert.match(ui.mine.textContent, /No data has been automatically deleted/);
  assert.match(ui.mine.textContent, /Request #22/);
  assert.equal(details.value, "");
});

test("Admin can filter and record a stage transition with expected prior state", async () => {
  const ui = fixture(),
    calls = [];
  let current = "pending";
  const privateRecord = {
    ...record,
    owner: { id: 3, name: "Private Customer", email: "private@example.test" },
    details: "My contact address is incorrect",
  };
  ui.setAdminActor(
    { role: "admin", id: 4 },
    async (p, method = "GET", body) => {
      calls.push({ p, method, body });
      if (method === "PATCH") {
        current = "reviewing";
        return { id: 22, status: "reviewing" };
      }
      return page(current === "pending" ? [privateRecord] : []);
    },
  );
  await tick();
  assert.match(ui.admin.textContent, /private@example.test/);
  const form = nodes(ui.admin, "form").find((x) => nodes(x, "textarea").length);
  assert.ok(form);
  nodes(form, "textarea")[0].value =
    "Reviewed customer identity and correction request";
  await form.onsubmit({ preventDefault() {} });
  await tick();
  const decision = calls.find((x) => x.method === "PATCH");
  assert.ok(decision);
  assert.equal(decision.p, "admin/privacy-requests/22/review");
  assert.equal(decision.body.expected_status, "pending");
  assert.equal(decision.body.status, "reviewing");
  assert.match(decision.body.review_note, /Reviewed customer identity/);
  assert.match(ui.admin.textContent, /No requests match these filters/);
});

test("Admin review refuses a short note and never performs a direct deletion", async () => {
  const ui = fixture(),
    calls = [];
  ui.setAdminActor({ role: "admin" }, async (p, method = "GET") => {
    calls.push({ p, method });
    return page([
      {
        ...record,
        details: "<img src=x onerror=alert(1)>",
        owner: { id: 5, name: "Private", email: "user@example.test" },
      },
    ]);
  });
  await tick();
  const form = nodes(ui.admin, "form").find((x) => nodes(x, "textarea").length);
  nodes(form, "textarea")[0].value = "short";
  await form.onsubmit({ preventDefault() {} });
  assert.equal(calls.filter((x) => x.method === "PATCH").length, 0);
  assert.match(ui.admin.textContent, /<img src=x onerror=alert\(1\)>/);
  assert.equal(nodes(ui.admin, "img").length, 0);
  assert.match(ui.admin.textContent, /no automatic deletion/);
});

test("stale self-service response cannot repopulate after actor changes", async () => {
  const ui = fixture();
  let release;
  ui.setSelfActor(
    { id: 2, role: "customer" },
    () =>
      new Promise((resolve) => {
        release = resolve;
      }),
  );
  assert.equal(typeof release, "function");
  ui.setSelfActor(null, async () => {
    throw Error("no identity");
  });
  release(page([{ ...record, kind: "access" }]));
  await tick();
  await tick();
  assert.equal(ui.mine.children.length, 0);
  assert.equal(ui.mine.textContent, "");
});

test("stale Admin review response cannot leak private data after logout", async () => {
  const ui = fixture();
  let release;
  ui.setAdminActor(
    { role: "admin" },
    () =>
      new Promise((resolve) => {
        release = resolve;
      }),
  );
  assert.equal(typeof release, "function");
  ui.setAdminActor(null, async () => {
    throw Error("no identity");
  });
  release(
    page([{ ...record, details: "PRIVATE-DATA", owner: { name: "Secret" } }]),
  );
  await tick();
  await tick();
  assert.equal(ui.admin.children.length, 0);
  assert.equal(ui.admin.textContent, "");
});

test("Admin private decision history renders only after request and remains text-only", async () => {
  const ui = fixture(),
    calls = [];
  const markup = "<img src=x onerror=alert(1)>";
  ui.setAdminActor({ role: "admin", id: 4 }, async (p) => {
    calls.push(p);
    if (p.endsWith("/reviews"))
      return {
        data: [
          {
            id: 5,
            from_status: "pending",
            to_status: "reviewing",
            note: markup,
            created_at: "2026-11-07T12:00:00Z",
          },
        ],
      };
    return page([
      {
        ...record,
        owner: {
          id: 3,
          name: "Private Customer",
          email: "private@example.test",
        },
        details: "Request details retained for authorized review.",
      },
    ]);
  });
  await tick();
  assert.equal(
    calls.length,
    1,
    "history must not fetch without Admin interaction",
  );
  assert.equal(nodes(ui.admin, "img").length, 0);
  const history = nodes(ui.admin, "button").find((x) =>
    x.textContent.includes("Private review history"),
  );
  assert.ok(history);
  await history.onclick();
  assert.equal(calls.length, 2);
  assert.equal(calls[1], "admin/privacy-requests/22/reviews");
  assert.match(ui.admin.textContent, /pending → reviewing/);
  assert.match(ui.admin.textContent, /<img src=x onerror=alert\(1\)>/);
  assert.equal(nodes(ui.admin, "img").length, 0);
});

test("delayed private privacy review history cannot reappear after logout", async () => {
  const ui = fixture();
  let release;
  ui.setAdminActor({ role: "admin", id: 4 }, (p) => {
    if (p.endsWith("/reviews"))
      return new Promise((resolve) => {
        release = resolve;
      });
    return Promise.resolve(
      page([
        {
          ...record,
          details: "Private detail",
          owner: { id: 3, name: "Private Owner", email: "owner@example.test" },
        },
      ]),
    );
  });
  await tick();
  const history = nodes(ui.admin, "button").find((x) =>
    x.textContent.includes("Private review history"),
  );
  const pending = history.onclick();
  assert.equal(typeof release, "function");
  ui.setAdminActor(null, async () => {
    throw Error("no request");
  });
  release({
    data: [
      {
        id: 5,
        from_status: "pending",
        to_status: "reviewing",
        note: "PRIVATE DECISION",
        created_at: "2026-11-07",
      },
    ],
  });
  await pending;
  await tick();
  assert.equal(ui.admin.children.length, 0);
  assert.equal(ui.admin.textContent, "");
});

test("unknown request retains its frozen identity and rejects changed retry content", async () => {
  const ui = fixture(),
    posted = [];
  let records = [],
    first = true;
  ui.setSelfActor(
    { id: 3, role: "customer" },
    async (p, method = "GET", body) => {
      if (method === "POST") {
        posted.push(body);
        if (!records.length)
          records = [{ ...record, kind: body.kind, details: body.details }];
        if (first) {
          first = false;
          throw Error("committed response lost");
        }
        return { id: record.id };
      }
      return page(records);
    },
  );
  await tick();
  const form = nodes(ui.mine, "form")[0],
    details = nodes(form, "textarea")[0];
  details.value = "Please review my personal information.";
  await form.onsubmit({ preventDefault() {} });
  assert.equal(posted.length, 1);
  assert(details.value.length > 0);
  details.value = "A different explanation must not reuse an identity.";
  await form.onsubmit({ preventDefault() {} });
  assert.equal(posted.length, 1);
  details.value = posted[0].details;
  await form.onsubmit({ preventDefault() {} });
  assert.equal(posted.length, 2);
  assert.equal(posted[0].idempotency_key, posted[1].idempotency_key);
  assert.equal(details.value, "");
});

test("detached previous-account form cannot dispatch private details using a new account", async () => {
  const ui = fixture(),
    posted = [];
  ui.setSelfActor({ id: 3, role: "customer" }, async () => page([]));
  await tick();
  const old = nodes(ui.mine, "form")[0];
  nodes(old, "textarea")[0].value = "Prior account confidential explanation.";
  ui.setSelfActor({ id: 4, role: "customer" }, async (p, m = "GET", b) => {
    if (m === "POST") posted.push(b);
    return page([]);
  });
  await tick();
  await old.onsubmit({ preventDefault() {} });
  assert.equal(posted.length, 0);
  assert(!ui.mine.textContent.includes("confidential"));
});

test("owner withdrawal is explicit and removes active controls after refreshed status", async () => {
  const ui = fixture(),
    posted = [];
  let status = "pending";
  ui.setSelfActor({ id: 3, role: "customer" }, async (p, m = "GET") => {
    if (m === "POST") {
      posted.push(p);
      status = "withdrawn";
      return { id: record.id, status };
    }
    return page([{ ...record, status }]);
  });
  await tick();
  const withdraw = nodes(ui.mine, "button").find((x) =>
    x.textContent.startsWith("Withdraw request"),
  );
  await withdraw.onclick();
  await tick();
  assert.deepEqual(posted, ["account/privacy-requests/22/withdraw"]);
  assert.match(ui.mine.textContent, /withdrawn/);
  assert(
    !nodes(ui.mine, "button").some((x) =>
      x.textContent.startsWith("Withdraw request"),
    ),
  );
});

test("Admin can inspect their own case but receives no independent-review form", async () => {
  const ui = fixture();
  ui.setAdminActor({ id: 4, role: "admin" }, async () =>
    page([
      {
        ...record,
        details: "My privacy case",
        owner: { id: 4, name: "Owner", email: "owner@example.test" },
      },
    ]),
  );
  await tick();
  assert.match(ui.admin.textContent, /My privacy case/);
  assert.equal(
    nodes(ui.admin, "form").filter((x) => nodes(x, "textarea").length).length,
    0,
  );
});

test("owner request history pages without retaining another accounts private controls", async () => {
  const ui = fixture(),
    calls = [];
  ui.setSelfActor({ id: 3, role: "customer" }, async (p) => {
    calls.push(p);
    const second = p.endsWith("?page=2");
    return {
      data: [
        {
          ...record,
          id: second ? 11 : 22,
          details: second ? "Older private request" : "Newest request",
        },
      ],
      current_page: second ? 2 : 1,
      last_page: 2,
      total: 26,
    };
  });
  await tick();
  const next = nodes(ui.mine, "button").find((x) =>
    x.textContent.startsWith("Next requests"),
  );
  assert.equal(next.disabled, false);
  await next.onclick();
  await tick();
  assert.equal(calls[1], "account/privacy-requests?page=2");
  assert.match(ui.mine.textContent, /Older private request/);
  assert(!ui.mine.textContent.includes("Newest request"));
  const previous = nodes(ui.mine, "button").find((x) =>
    x.textContent.startsWith("Previous requests"),
  );
  ui.setSelfActor(null, async () => {
    throw Error("No signed-out request allowed");
  });
  await previous.onclick();
  await tick();
  assert.equal(calls.length, 2);
  assert.equal(ui.mine.textContent, "");
});
