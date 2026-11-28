// Controlled browser scheduler/authority contracts; distinct from actual-Laravel suite.
const { test, before, after } = require("node:test"),
  assert = require("node:assert/strict");
const { chromium } = require("playwright"),
  path = require("node:path");
let browser;
before(async () => {
  browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
});
after(async () => {
  await browser?.close();
});
function snapshot(state = "prepared", extra = {}) {
  const active = state !== "not_prepared";
  return {
    registration_id: active ? 1 : null,
    binding_id: active ? 2 : null,
    profile_id: active ? 3 : null,
    state,
    source_station_id: state === "registered" ? 401 : null,
    verified_at: state === "registered" ? "2026-11-21T10:15:30.123456Z" : null,
    upstream_registration_verified: state === "registered",
    evidence:
      state === "registered"
        ? "authenticated_source_record_created_and_read_back"
        : "no_verified_registration",
    device_commands_sent: 0,
    charging_capability_granted: false,
    charger_hardware_verified: false,
    automatic_retry_allowed: false,
    source_snapshot_only: true,
    live_connection_verified: false,
    current_inventory_matches_registration: state === "registered",
    can_prepare: state === "not_prepared",
    can_register: state === "prepared",
    ...extra,
  };
}
async function pageWith(queue, role = "admin", endpoint = "owned/source-registration") {
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await page.route("http://127.0.0.1:8099/**", (r) =>
    r.fulfill({
      contentType: "text/html",
      body: '<!doctype html><html lang="en"><body><section id="root"></section></body></html>',
    }),
  );
  await page.goto("http://127.0.0.1:8099/");
  await page.addScriptTag({
    path: path.join(
      __dirname,
      "../services/business-api/public/charger-source-registration.js",
    ),
  });
  await page.evaluate(
    ({ queue, role, endpoint }) => {
      window.actorValue = { id: 1, role };
      window.calls = [];
      window.queue = queue;
      window.pending = [];
      window.expiries = 0;
      window.controller = new AbortController();
      window.api = async (path, method = "GET", payload) => {
        calls.push({ path, method, payload });
        const next = window.queue.shift();
        if (next?.hold)
          return new Promise((resolve, reject) =>
            pending.push({ resolve, reject }),
          );
        if (next?.error) throw { status: next.error };
        return next?.data;
      };
      window.mountChargerSourceRegistration({
        root: document.querySelector("#root"),
        endpoint,
        actor: () => window.actorValue,
        api: window.api,
        valid: () => true,
        signal: controller.signal,
        expired: () => expiries++,
      });
    },
    { queue, role, endpoint },
  );
  return page;
}
test("vendor ignores forged management flags and renders source snapshot read-only", async () => {
  const page = await pageWith(
    [
      {
        data: snapshot("registered", { can_prepare: true, can_register: true }),
      },
    ],
    "vendor",
  );
  try {
    await page.getByText("Source record observed", { exact: true }).waitFor();
    assert.equal(await page.locator("form,input").count(), 0);
    assert.equal(
      await page
        .getByRole("button", {
          name: "Register source metadata once",
          exact: true,
        })
        .count(),
      0,
    );
    assert.equal(await page.locator("time").getAttribute("dir"), "ltr");
  } finally {
    await page.close();
  }
});
test("unconfirmed source POST is followed only by receipt GET and quarantined outcome", async () => {
  const page = await pageWith([
    { data: snapshot() },
    { error: 503 },
    { data: snapshot("requires_inspection") },
  ]);
  try {
    await page.locator("input[type=checkbox]").check();
    await page
      .getByRole("button", {
        name: "Register source metadata once",
        exact: true,
      })
      .click();
    await page
      .getByText("Operator inspection required; do not resend", { exact: true })
      .waitFor();
    assert.deepEqual(await page.evaluate(() => calls.map((c) => c.method)), [
      "GET",
      "POST",
      "GET",
    ]);
    assert.equal(
      await page
        .getByRole("button", {
          name: "Register source metadata once",
          exact: true,
        })
        .count(),
      0,
    );
  } finally {
    await page.close();
  }
});
test("preparation payload freezes before asynchronous work and duplicate submit is blocked", async () => {
  const page = await pageWith([
    { data: snapshot("not_prepared") },
    { hold: true },
    { data: snapshot() },
  ]);
  try {
    await page
      .getByLabel("Charger source identity (maximum 36 characters)", {
        exact: true,
      })
      .fill("CB-FROZEN-001");
    await page
      .getByRole("button", { name: "Prepare source registration", exact: true })
      .click();
    await page.waitForFunction(() => pending.length === 1);
    await page.evaluate(() => {
      document.querySelector("input").value = "CB-OTHER";
      document
        .querySelector("form")
        .dispatchEvent(new Event("submit", { cancelable: true }));
    });
    assert.equal(
      await page.evaluate(
        () => calls.filter((c) => c.method === "POST").length,
      ),
      1,
    );
    assert.equal(
      await page.evaluate(() => calls[1].payload.identity),
      "CB-FROZEN-001",
    );
    await page.evaluate(() => pending[0].resolve({}));
    await page
      .getByText("Prepared; no source request sent", { exact: true })
      .waitFor();
    assert.deepEqual(
      await page.evaluate(() => [localStorage.length, sessionStorage.length]),
      [0, 0],
    );
  } finally {
    await page.close();
  }
});
for (const status of [401, 419])
  test(
    "current " +
      status +
      " clears private pane and expires current workspace once",
    async () => {
      const page = await pageWith([{ error: status }]);
      try {
        await page.waitForFunction(() => expiries === 1);
        assert.equal(await page.locator("#root").innerText(), "");
      } finally {
        await page.close();
      }
    },
  );
test("stale actor response cannot render another account receipt or expire new account", async () => {
  const page = await pageWith([{ hold: true }]);
  try {
    await page.waitForFunction(() => pending.length === 1);
    await page.evaluate(() => {
      actorValue = { id: 2, role: "admin" };
      pending[0].reject({ status: 401 });
    });
    await page.waitForTimeout(30);
    assert.equal(await page.evaluate(() => expiries), 0);
    assert.equal(
      await page.getByText("Source record observed", { exact: true }).count(),
      0,
    );
    assert.equal(await page.locator("form,input").count(), 0);
  } finally {
    await page.close();
  }
});
test("abort during source mutation scrubs pane and prohibits follow-up reads or writes", async () => {
  const page = await pageWith([{ data: snapshot() }, { hold: true }]);
  try {
    await page.locator("input[type=checkbox]").check();
    await page
      .getByRole("button", {
        name: "Register source metadata once",
        exact: true,
      })
      .click();
    await page.waitForFunction(() => pending.length === 1);
    await page.evaluate(() => {
      controller.abort();
      pending[0].resolve({});
    });
    await page.waitForTimeout(30);
    assert.equal(await page.locator("#root").innerText(), "");
    assert.deepEqual(await page.evaluate(() => calls.map((c) => c.method)), [
      "GET",
      "POST",
    ]);
  } finally {
    await page.close();
  }
});
test("malformed authority or unsafe IDs fail closed without management controls", async () => {
  for (const data of [
    snapshot("prepared", { device_commands_sent: 1 }),
    snapshot("prepared", { registration_id: 9007199254740992 }),
    snapshot("registered", { evidence: "live_hardware" }),
  ]) {
    const page = await pageWith([{ data }]);
    try {
      await page
        .getByText(
          "Registration could not be confirmed. Refresh the retained receipt before taking another action.",
          { exact: true },
        )
        .waitFor();
      assert.equal(await page.locator("form,input").count(), 0);
    } finally {
      await page.close();
    }
  }
});
test("locale change reloads read-only receipt and renders Arabic with LTR source time", async () => {
  const data = snapshot("registered");
  const page = await pageWith([{ data }, { data }], "vendor");
  try {
    await page.getByText("Source record observed", { exact: true }).waitFor();
    await page.evaluate(() => {
      document.documentElement.lang = "ar";
      document.documentElement.dir = "rtl";
      dispatchEvent(new Event("ev:locale"));
    });
    await page.getByText("تمت ملاحظة سجل المصدر", { exact: true }).waitFor();
    assert.equal(await page.locator("time").getAttribute("dir"), "ltr");
    assert.deepEqual(await page.evaluate(() => calls.map((c) => c.method)), [
      "GET",
      "GET",
    ]);
  } finally {
    await page.close();
  }
});

test("registered connection health reads only the scoped source on explicit request", async () => {
  const endpoint = "vendor-ops/companies/1/chargers/4/source-registration";
  const health = {
    company_id: 1, charger_id: 4, registration_id: 1, source_station_id: 401,
    connection_state: "recent", source_last_message_at: "2026-11-21T10:15:30.123456Z",
    source_firmware_version: "fixture-firmware", evidence: "AUTHENTICATED_REGISTERED_SOURCE",
    device_commands_sent: 0, charging_capability_granted: false, charger_hardware_verified: false,
  };
  const page = await pageWith([
    { data: snapshot("registered", { can_inspect_connection: true }) },
    { data: health },
    { data: { ...health, charger_id: 5 } },
  ], "vendor", endpoint);
  try {
    const inspect = page.getByRole("button", { name: "Inspect source connection" });
    await inspect.waitFor();
    assert.deepEqual(await page.evaluate(() => calls.map(c => c.method)), ["GET"]);
    await inspect.click();
    await page.getByText("Recent source message", { exact: true }).waitFor();
    assert.equal(await page.evaluate(() => calls[1].path), endpoint + "/1/connection-health");
    await inspect.click();
    await page.getByText("Source connection could not be confirmed.", { exact: true }).waitFor();
    assert.equal(await page.getByText("Recent source message", { exact: true }).count(), 0);
    assert.deepEqual(await page.evaluate(() => calls.map(c => c.method)), ["GET", "GET", "GET"]);
  } finally {
    await page.close();
  }
});
