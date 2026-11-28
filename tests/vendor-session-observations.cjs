"use strict";
const assert = require("node:assert/strict"),
  fs = require("node:fs"),
  vm = require("node:vm"),
  { test } = require("node:test");
class Element {
  constructor(tag) {
    this.tag = tag;
    this.children = [];
    this.dataset = {};
    this.attributes = {};
    this._text = "";
    this.disabled = false;
    this.hidden = false;
    this.isConnected = true;
    this.value = "";
  }
  set textContent(value) {
    this._text = String(value);
    this.children = [];
  }
  get textContent() {
    return this._text + this.children.map((c) => c.textContent).join("");
  }
  append(...children) {
    this.children.push(...children);
  }
  replaceChildren(...children) {
    this._text = "";
    this.children = children;
  }
  get firstChild() {
    return this.children[0];
  }
  setAttribute(key, value) {
    this.attributes[key] = value;
  }
  remove() {
    this.isConnected = false;
    this.children = [];
  }
}
const nodes = (root, tag) => [
  ...(root.tag === tag ? [root] : []),
  ...root.children.flatMap((child) => nodes(child, tag)),
];
const flush = () => new Promise((resolve) => setImmediate(resolve));
function fixture(ar = false) {
  const root = new Element("div"),
    events = {},
    windowEvents = {};
  const document = {
    hidden: false,
    documentElement: { lang: ar ? "ar" : "en" },
    createElement: (tag) => new Element(tag),
    addEventListener: (key, fn) => (events[key] = fn),
    removeEventListener: (key) => delete events[key],
  };
  const window = {
    addEventListener: (key, fn) => (windowEvents[key] = fn),
    removeEventListener: (key) => delete windowEvents[key],
  };
  for (const script of ["station-time", "vendor-session-observations"]) {
    vm.runInNewContext(
      fs.readFileSync(`services/business-api/public/${script}.js`, "utf8"),
      { window, document, AbortController, URLSearchParams },
    );
  }
  let current = true;
  return {
    root,
    document,
    events,
    windowEvents,
    revoke: () => (current = false),
    open: (api, onExpired, session = null) =>
      window.EvCompanySessionObservations.open({
        host: root,
        api,
        company: { id: 4, legal_name: "Company" },
        current: () => current,
        onExpired,
        session,
      }),
    clear: window.EvCompanySessionObservations.clear,
  };
}
function record(id) {
  return {
    id,
    station_id: 7,
    station_name: "Station " + id,
    recorded_status: "stopped",
    energy_wh: 2500,
    energy_state: "recorded",
    record_created_at: "2026-11-19T06:00:00.000000Z",
    record_updated_at: "2026-11-19T06:01:00Z",
    stopped_at: "2026-11-19T06:00:00Z",
    protocol: null,
    evidence: "SIMULATED",
    physical_charging_verified: false,
    live_availability_verified: false,
  };
}
function page(number = 1, filter = null) {
  return {
    scope: "company_recorded_charging_sessions",
    company_id: 4,
    status_filter: filter,
    inventory_station_scope: "current_owned_unique_site_links",
    customer_identity_fields_included: false,
    financial_fields_included: false,
    control_actions_exposed: false,
    physical_charging_verified: false,
    live_availability_verified: false,
    data: [record(number === 1 ? 27 : 1)],
    per_page: 25,
    current_page: number,
    last_page: 2,
    total: 26,
    as_of: "2026-11-19T06:02:00Z",
  };
}
const buttons = (f) => nodes(f.root, "button");
for (const ar of [false, true])
  test("EN/AR read-only projection drops private fields " + ar, async () => {
    const f = fixture(ar),
      calls = [];
    f.open(async (...args) => {
      calls.push(args);
      const d = page();
      d.data[0].customer_email = "PRIVATE";
      return d;
    });
    await flush();
    assert.equal(calls.length, 1);
    assert.equal(calls[0][1], "GET");
    assert.equal(calls[0][2], undefined);
    assert(
      f.root.textContent.includes(
        ar ? "جلسات الشحن المسجلة" : "Recorded charging sessions",
      ),
    );
    assert(f.root.textContent.includes("Station 27"));
    assert(!f.root.textContent.includes("PRIVATE"));
    const clocks = nodes(f.root, "time");
    assert.deepEqual(clocks.map(clock => clock.dateTime), [
      "2026-11-19T06:02:00Z",
      "2026-11-19T06:01:00Z",
      "2026-11-19T06:00:00Z",
    ]);
    for (const clock of clocks) {
      assert.equal(clock.textContent, clock.dataset[ar ? "ar" : "en"]);
      assert.match(clock.textContent, /\(UTC\)$/);
      assert.notEqual(clock.dataset.en, clock.dataset.ar);
    }
  });
test("pagination clears old records, failure retries precise page and filter resets page", async () => {
  const f = fixture(),
    calls = [];
  let fail = true;
  f.open(async (path) => {
    calls.push(path);
    const q = new URL("http://localhost/" + path).searchParams;
    if (q.get("page") === "2" && fail) throw Error("PRIVATE");
    return page(Number(q.get("page")), q.get("status"));
  });
  await flush();
  const stale = buttons(f)[2].onclick;
  buttons(f)[2].onclick();
  await flush();
  assert(!f.root.textContent.includes("Station 27"));
  assert.equal(buttons(f)[3].hidden, false);
  stale();
  await flush();
  assert.equal(calls.length, 2);
  fail = false;
  buttons(f)[3].onclick();
  await flush();
  assert(calls[2].includes("page=2"));
  assert(f.root.textContent.includes("Station 1"));
  const select = nodes(f.root, "select")[0];
  select.value = "charging";
  select.onchange();
  await flush();
  assert(calls[3].includes("page=1&status=charging"));
});
test("single flight and captured callbacks cannot read after account invalidation", async () => {
  const f = fixture();
  let reads = 0,
    finish;
  f.open(() => {
    reads++;
    return new Promise((resolve) => (finish = resolve));
  });
  const old = buttons(f)[0].onclick;
  old();
  assert.equal(reads, 1);
  f.revoke();
  finish(page());
  await flush();
  old();
  await flush();
  assert.equal(reads, 1);
  assert(!f.root.textContent.includes("Station 27"));
});
for (const failure of [false, true])
  test("old reply cannot overwrite new company panel " + failure, async () => {
    const f = fixture();
    let resolve, reject;
    f.open(
      () =>
        new Promise((yes, no) => {
          resolve = yes;
          reject = no;
        }),
    );
    const old = buttons(f)[0].onclick;
    f.open(async () => page(1));
    await flush();
    failure ? reject(Error("PRIVATE401")) : resolve(page(2));
    await flush();
    old();
    assert(f.root.textContent.includes("Station 27"));
    assert(!f.root.textContent.includes("Station 1"));
  });
test("hidden mount never reads; background erases and captured callback stays inert", async () => {
  const f = fixture();
  let calls = 0;
  f.document.hidden = true;
  f.open(async () => {
    calls++;
    return page();
  });
  assert.equal(calls, 0);
  f.document.hidden = false;
  f.open(async () => {
    calls++;
    return page();
  });
  await flush();
  const old = buttons(f)[0].onclick;
  f.document.hidden = true;
  f.events.visibilitychange();
  assert(!f.root.textContent.includes("Station 27"));
  f.document.hidden = false;
  old();
  await flush();
  assert.equal(calls, 1);
});
test("malformed and acceptance-promoting responses render generic failure without records", async () => {
  for (const change of [
    (v) => (v.company_id = 99),
    (v) => (v.physical_charging_verified = true),
    (v) => (v.financial_fields_included = true),
    (v) => (v.last_page = 3),
    (v) => (v.as_of = "2026-02-30T00:00:00Z"),
    (v) => (v.data[0].energy_wh = -1),
    (v) => (v.data[0].protocol = "2.0.1"),
    (v) => (v.data = [record(1), record(1)]),
  ]) {
    const f = fixture();
    f.open(async () => {
      const d = page();
      change(d);
      return d;
    });
    await flush();
    assert(!f.root.textContent.includes("Station 27"));
    assert.equal(buttons(f)[3].hidden, false);
  }
});

test("current credential conflicts expire the view; old conflicts cannot expire a new view", async () => {
  for (const code of [401, 409, 419]) {
    const f = fixture();
    let expired = 0;
    f.open(
      async () => {
        throw Object.assign(Error("PRIVATE"), { status: code });
      },
      () => expired++,
    );
    await flush();
    assert.equal(expired, 1);
    assert.equal(buttons(f).length, 0);
  }
  const f = fixture();
  let reject,
    expired = 0;
  f.open(
    () => new Promise((yes, no) => (reject = no)),
    () => expired++,
  );
  f.open(async () => page());
  await flush();
  reject(Object.assign(Error("PRIVATE"), { status: 409 }));
  await flush();
  assert.equal(expired, 0);
  assert(f.root.textContent.includes("Station 27"));
});

function meterPage(number = 1, cut = 27, linked = true) {
  return {
    scope: "company_csms_meter_history",
    company_id: 4,
    session_id: 27,
    through_id: linked ? cut : 0,
    source_linked: linked,
    protocol: linked ? "2.0.1" : null,
    coverage: "RECORDED_APPLIED_CSMS_EVENTS",
    history_complete: false,
    billing_authority: false,
    physical_energy_verified: false,
    commands_sent: false,
    network_requests: 0,
    evidence: linked ? "SIMULATED_CSMS" : "NO_RECORDED_CSMS_LINK",
    as_of: "2026-11-19T09:59:00Z",
    observations: {
      data: linked
        ? [
            {
              id: number === 1 ? cut : 1,
              sequence: 26,
              kind: "ended",
              cumulative_meter_wh: 1002500,
              source_occurred_at: "2026-11-19T09:59:00.123456Z",
              recorded_at: "2026-11-19T10:00:00Z",
              event_id: "PRIVATE-EXTRA",
            },
          ]
        : [],
      current_page: number,
      last_page: linked ? 2 : 1,
      total: linked ? 26 : 0,
      per_page: 25,
    },
  };
}
const byLabel = (f, label) =>
  buttons(f).find((button) => button.textContent === label);
for (const ar of [false, true])
  test(
    "meter timeline keeps snapshot cursor, redacts source identifiers and refreshes explicitly " +
      ar,
    async () => {
      const f = fixture(ar),
        calls = [];
      let newer = false;
      f.open(
        async (path) => {
          calls.push(path);
          const q = new URL("http://localhost/" + path).searchParams;
          return meterPage(
            Number(q.get("page")),
            q.has("through") ? Number(q.get("through")) : newer ? 28 : 27,
          );
        },
        undefined,
        27,
      );
      await flush();
      assert(f.root.textContent.includes("1002500 Wh"));
      assert(f.root.textContent.includes("2026-11-19T09:59:00.123456Z"));
      assert(!f.root.textContent.includes("PRIVATE"));
      assert(
        f.root.textContent.includes(
          ar ? "ليست طاقة مستهلكة" : "not consumed or billed",
        ),
      );
      newer = true;
      byLabel(f, ar ? "التالي" : "Next").onclick();
      await flush();
      assert(calls[1].endsWith("page=2&through=27"));
      byLabel(f, ar ? "تحديث اللقطة" : "Refresh snapshot").onclick();
      await flush();
      assert(calls[2].endsWith("meter-history?page=1"));
      assert(!calls[2].includes("through="));
    },
  );
test("meter navigation clears old session records and invalidates detached actions", async () => {
  const f = fixture(),
    calls = [];
  f.open(async (path) => {
    calls.push(path);
    return path.includes("/meter-history") ? meterPage() : page();
  });
  await flush();
  const old = byLabel(f, "Recorded meter history").onclick;
  old();
  await flush();
  assert(!f.root.textContent.includes("Station 27"));
  assert(f.root.textContent.includes("1002500 Wh"));
  old();
  await flush();
  assert.equal(calls.length, 2);
  byLabel(f, "Back to recorded sessions").onclick();
  await flush();
  assert(f.root.textContent.includes("Station 27"));
  assert(!f.root.textContent.includes("1002500 Wh"));
});
test("history read failure clears old counters and retries only failed page/cursor", async () => {
  const f = fixture(),
    calls = [];
  let fail = true;
  f.open(
    async (path) => {
      calls.push(path);
      if (path.includes("page=2") && fail) throw Error("PRIVATE");
      return meterPage(path.includes("page=2") ? 2 : 1);
    },
    undefined,
    27,
  );
  await flush();
  byLabel(f, "Next").onclick();
  await flush();
  assert(!f.root.textContent.includes("1002500 Wh"));
  fail = false;
  byLabel(f, "Retry this page").onclick();
  await flush();
  assert(calls[2].endsWith("page=2&through=27"));
  assert(f.root.textContent.includes("1002500 Wh"));
});
test("no CSMS link yields an explicit empty history rather than invented readings", async () => {
  const f = fixture();
  f.open(async () => meterPage(1, 0, false), undefined, 27);
  await flush();
  assert(f.root.textContent.includes("No recorded CSMS link"));
  assert(!f.root.textContent.includes("Cumulative counter:"));
});
test("unsafe, foreign or acceptance-promoting meter replies cannot render a counter", async () => {
  for (const change of [
    (v) => (v.company_id = 5),
    (v) => (v.session_id = 28),
    (v) => (v.billing_authority = true),
    (v) => (v.history_complete = true),
    (v) => (v.commands_sent = true),
    (v) => (v.protocol = null),
    (v) => (v.through_id = 1),
    (v) => (v.observations.data[0].cumulative_meter_wh = -1),
    (v) => (v.observations.data[0].source_occurred_at = "2026-02-30T00:00:00Z"),
  ]) {
    const f = fixture();
    f.open(
      async () => {
        const d = meterPage();
        change(d);
        return d;
      },
      undefined,
      27,
    );
    await flush();
    assert(!f.root.textContent.includes("1002500 Wh"));
    assert.equal(byLabel(f, "Retry this page").hidden, false);
  }
});
