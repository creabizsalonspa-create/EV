// Deliberately delayed synthetic responses test browser identity ordering, not backend authentication.
const { chromium } = require("playwright"),
  assert = require("node:assert/strict");
const deferred = () => {
  let resolve;
  const promise = new Promise((r) => (resolve = r));
  return { promise, resolve };
};
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const gates = [],
    errors = [];
  try {
    async function wait(promise, label) {
      let timer;
      try {
        return await Promise.race([
          promise,
          new Promise(
            (_, reject) =>
              (timer = setTimeout(
                () => reject(new Error(label + " did not complete")),
                15000,
              )),
          ),
        ]);
      } finally {
        clearTimeout(timer);
      }
    }
    async function fixture(hold, admin = false) {
      const context = await browser.newContext();
      context.setDefaultTimeout(15000);
      const page = await context.newPage();
      page.on("pageerror", (e) => errors.push(e.message));
      const a = {
          id: 1,
          name: "Account A",
          role: admin ? "admin" : "customer",
        },
        b = { id: 2, name: "Account B", role: "customer" };
      let who = a,
        meCalls = 0,
        vehicleCalls = 0,
        logoutFails = true,
        logoutCalls = 0,
        badIdentity = false;
      const vehicles = deferred(),
        identity = deferred(),
        vehiclesSeen = deferred(),
        identitySeen = deferred(),
        overview = deferred(),
        overviewSeen = deferred();
      gates.push(vehicles, identity, overview);
      let vehicleRequest, identityRequest, overviewRequest;
      const paged = (data) => ({
        data,
        current_page: 1,
        last_page: 1,
        total: data.length,
      });
      await page.route("**/api/**", async (route) => {
        const request = route.request(),
          path = new URL(request.url()).pathname;
        const json = (data, status = 200) =>
          route.fulfill({
            status,
            contentType: "application/json",
            body: JSON.stringify(data),
          });
        if (path === "/api/admin/overview" && admin) {
          overviewRequest = request;
          overviewSeen.resolve();
          await overview.promise;
          return json({
            users: 99,
            stations: 88,
            reservations: 77,
            simulations: 66,
            audit_events: [
              {
                actor_id: 1,
                action: "PRIVATE_ADMIN_EVENT",
                subject: "synthetic-only",
                created_at: "2026-10-27T00:00:00Z",
              },
            ],
          });
        }
        if (path === "/api/csrf") return json({ token: "synthetic-only" });
        if (path === "/api/me") {
          meCalls++;
          if (badIdentity) return json(null);
          if (hold && meCalls === 2) {
            identityRequest = request;
            identitySeen.resolve();
            await identity.promise;
            return json({ message: "old session expired" }, 401);
          }
          return who ? json(who) : json({ message: "Unauthenticated" }, 401);
        }
        if (path === "/api/login") {
          who = b;
          return json(b);
        }
        if (path === "/api/logout") {
          logoutCalls++;
          if (logoutFails) {
            logoutFails = false;
            return json({ message: "Synthetic unavailable logout" }, 503);
          }
          who = null;
          return route.fulfill({ status: 204 });
        }
        if (path === "/api/vehicles") {
          vehicleCalls++;
          if (hold && vehicleCalls === 1) {
            vehicleRequest = request;
            vehiclesSeen.resolve();
            await vehicles.promise;
            return json(
              paged([
                { id: 1, name: "Private garage A", connector_type: "CCS2" },
              ]),
            );
          }
          return json(
            paged(
              who
                ? [
                    {
                      id: who.id,
                      name:
                        who.id === 1 ? "Private garage A" : "Private garage B",
                      connector_type: "CCS2",
                    },
                  ]
                : [],
            ),
          );
        }
        if (path === "/api/favorites/status")
          return json({ favorite_station_ids: [] });
        if (path === "/api/stations")
          return json(
            paged([
              {
                id: 1,
                name: "Synthetic public station",
                evidence: "SIMULATED",
                availability: "unknown",
                reservable: true,
                connector_type: "CCS2",
                power_kw: 50,
                country: "AE",
                currency: "AED",
                latitude: "25",
                longitude: "55",
              },
            ]),
          );
        if (path === "/api/regions")
          return json([
            {
              country: "AE",
              currency: "AED",
              minor_units: 2,
              timezone: "Asia/Dubai",
              tax_bps: 500,
            },
          ]);
        return json(paged([]));
      });
      await page.goto(process.env.EV_TEST_URL || "http://127.0.0.1:8000");
      return {
        page,
        vehicles,
        identity,
        vehiclesSeen,
        identitySeen,
        overview,
        overviewSeen,
        unreadable() {
          badIdentity = true;
        },
        downgrade() {
          who = { ...a, role: "customer" };
        },
        get overviewRequest() {
          return overviewRequest;
        },
        get meCalls() {
          return meCalls;
        },
        get logoutCalls() {
          return logoutCalls;
        },
        get held() {
          return [vehicleRequest, identityRequest];
        },
      };
    }
    const state = await fixture(true),
      p = state.page;
    await wait(state.vehiclesSeen.promise, "old vehicles");
    await p.locator("#refresh-identity").click();
    await wait(state.identitySeen.promise, "old identity");
    await p.locator("#refresh-catalog").click();
    await p.locator("#stations form").waitFor();
    await p.locator("#logout").click();
    await p.locator("#logout-retry").waitFor();
    assert.equal(await p.locator("#vehicle-list").textContent(), "");
    assert.equal(await p.locator("#stations form").count(), 0);
    assert.equal(
      await p.evaluate(() => sessionStorage.getItem("creabiz.logout-pending")),
      "1",
    );
    await p.locator("#language").click();
    assert.match(
      await p.locator("#logout-retry").textContent(),
      /إعادة محاولة تسجيل الخروج/,
    );
    assert.match(
      await p.locator("#notice").textContent(),
      /تعذر تأكيد تسجيل الخروج/,
    );
    await p.locator("#account [name=email]").fill("synthetic-b@example.test");
    await p.locator("#account [name=password]").fill("synthetic-password-only");
    await p.locator("button[value=login]").click();
    await p.locator("#who").filter({ hasText: "Account B" }).waitFor();
    await p
      .locator("#vehicle-list")
      .filter({ hasText: "Private garage B" })
      .waitFor();
    const finished = Promise.all(
      state.held.map((r) =>
        r.response().then((response) => response?.finished()),
      ),
    );
    state.vehicles.resolve();
    state.identity.resolve();
    await wait(finished, "old responses");
    await p.evaluate(
      () =>
        new Promise((r) =>
          requestAnimationFrame(() => requestAnimationFrame(r)),
        ),
    );
    assert.match(await p.locator("#who").textContent(), /Account B/);
    assert.match(
      await p.locator("#vehicle-list").textContent(),
      /Private garage B/,
    );
    assert.doesNotMatch(
      await p.locator("#vehicle-list").textContent(),
      /Private garage A/,
    );
    assert.equal(await p.locator("#account").isHidden(), true);
    assert.equal(
      await p.evaluate(() => sessionStorage.getItem("creabiz.logout-pending")),
      null,
    );
    const restore = await fixture(false),
      r = restore.page;
    await r
      .locator("#vehicle-list")
      .filter({ hasText: "Private garage A" })
      .waitFor();
    await r.locator("#logout").click();
    await r.locator("#logout-retry").waitFor();
    assert.equal(restore.meCalls, 1);
    await r.reload();
    await r.locator("#notice").filter({ hasText: "Signed out." }).waitFor();
    assert.equal(
      restore.meCalls,
      1,
      "Pending logout must not restore the old account after reload.",
    );
    assert.equal(restore.logoutCalls, 2);
    assert.equal(await r.locator("#vehicle-list").textContent(), "");
    assert.equal(await r.locator("#who").textContent(), "");
    assert.equal(await r.locator("#logout-retry").isHidden(), true);
    assert.equal(
      await r.evaluate(() => sessionStorage.getItem("creabiz.logout-pending")),
      null,
    );
    const role = await fixture(false, true),
      rp = role.page;
    await wait(role.overviewSeen.promise, "admin overview");
    role.downgrade();
    await rp.locator("#refresh-identity").click();
    await rp.locator("#who").filter({ hasText: "customer" }).waitFor();
    const roleFinished = role.overviewRequest
      .response()
      .then((response) => response?.finished());
    role.overview.resolve();
    await wait(roleFinished, "old admin response");
    await rp.evaluate(
      () =>
        new Promise((r) =>
          requestAnimationFrame(() => requestAnimationFrame(r)),
        ),
    );
    assert.equal(await rp.locator("#admin").isHidden(), true);
    assert.equal(await rp.locator("#overview").textContent(), "");
    const invalid = await fixture(false),
      ip = invalid.page;
    await ip
      .locator("#vehicle-list")
      .filter({ hasText: "Private garage A" })
      .waitFor();
    invalid.unreadable();
    await ip.locator("#refresh-identity").click();
    await ip.locator("#signed").waitFor({ state: "hidden" });
    assert.equal(await ip.locator("#vehicle-list").textContent(), "");
    assert.equal(await ip.locator("#account [name=email]").isDisabled(), false);
    assert.deepEqual(errors, []);
    console.log(
      "PASS: delayed private vehicle/401 responses cannot repopulate or sign out a new account; immediate local logout clears actions; failed logout remains explicit and retries after reload without restoring private identity. Downgraded roles cannot receive stale admin data. Unreadable identities fail closed; logout errors are localized. Controlled browser fixtures only.",
    );
  } finally {
    for (const gate of gates) gate.resolve();
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
