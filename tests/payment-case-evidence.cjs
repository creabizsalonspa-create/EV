// Real Laravel and browser. Internal encrypted evidence only; no live/provider submission.
const { chromium } = require("playwright"),
  { execFileSync } = require("node:child_process"),
  { randomBytes, createHash } = require("node:crypto"),
  fs = require("node:fs"),
  assert = require("node:assert/strict");
execFileSync(process.execPath, ["tests/case-evidence-client.cjs"], {
  stdio: "inherit",
});
(async () => {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const f = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/payment-case-evidence.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const errors = [];
  try {
    async function login(role) {
      const context = await browser.newContext(),
        p = await context.newPage();
      p.setDefaultTimeout(25000);
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto("http://127.0.0.1:8000");
      await p
        .locator("#account [name=email]")
        .fill(`payout-${role}-${run}@example.test`);
      await p.locator("#account [name=password]").fill(password);
      const pending = p.waitForResponse(
        (r) =>
          r.url().endsWith("/api/login") && r.request().method() === "POST",
      );
      await p.locator("button[value=login]").click();
      assert.equal((await pending).status(), 200);
      await p.locator('a[href="#commercial-finance"]').click();
      await p.locator("[data-payment-case-evidence]").waitFor();
      return p;
    }
    async function open(p) {
      const panel = p.locator("[data-payment-case-evidence]");
      await panel
        .getByRole("button", {
          name: "Open internal dispute evidence",
          exact: true,
        })
        .click();
      await panel
        .locator(`[data-case-id="${f.case_id}"]`)
        .getByRole("button", {
          name: "Open private case evidence",
          exact: true,
        })
        .click();
      await panel.locator("input[type=file]").waitFor();
      return panel;
    }
    const customer = await login("customer"),
      panel = await open(customer),
      file = Buffer.from("%PDF-1.4\nSynthetic private browser evidence");
    const endpoint = `**/api/commercial/cases/${f.case_id}/evidence`;
    let lost = true,
      original;
    await customer.route(endpoint, async (route) => {
      if (route.request().method() !== "POST") return route.continue();
      const response = await route.fetch();
      assert.equal(response.status(), 201);
      const body = await response.json();
      original ??= body.id;
      if (lost) {
        lost = false;
        return route.abort("connectionfailed");
      }
      assert.equal(body.id, original);
      return route.fulfill({ response });
    });
    await panel.locator("input[type=file]").setInputFiles({
      name: "private.pdf",
      mimeType: "application/pdf",
      buffer: file,
    });
    await panel.locator("input[type=checkbox]").check();
    await panel
      .getByRole("button", { name: "Upload private evidence", exact: true })
      .click();
    await panel
      .getByRole("status")
      .getByText(/Request unconfirmed/)
      .waitFor();
    assert.equal(await panel.locator("select").isDisabled(), true);
    await panel
      .getByRole("button", { name: "Upload private evidence", exact: true })
      .click();
    await panel
      .getByRole("button", {
        name: "Download verified private evidence",
        exact: true,
      })
      .waitFor();
    const rows = await customer.evaluate(
      async (id) =>
        (await fetch(`/api/commercial/cases/${id}/evidence`)).json(),
      f.case_id,
    );
    assert.equal(rows.total, 1);
    assert.equal(rows.data[0].provider_submitted, false);
    assert(!("storage_path" in rows.data[0]));
    const download = customer.waitForEvent("download");
    await panel
      .getByRole("button", {
        name: "Download verified private evidence",
        exact: true,
      })
      .click();
    const received = await download;
    const saved = await received.path();
    assert.equal(
      createHash("sha256").update(fs.readFileSync(saved)).digest("hex"),
      rows.data[0].sha256,
    );
    const vendor = await login("vendor"),
      vp = await open(vendor);
    await vp
      .getByRole("button", {
        name: "Download verified private evidence",
        exact: true,
      })
      .waitFor();
    await vendor.setViewportSize({ width: 390, height: 844 });
    for (const theme of ["light", "dark"]) {
      await vendor.locator("#theme-choice").selectOption(theme);
      assert.equal(
        await vendor.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
        true,
      );
    }
    await vendor.locator("#language").click();
    await vendor.waitForFunction(() => document.documentElement.dir === "rtl");
    assert.equal(
      await vendor.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      true,
    );
    const admin = await login("maker"),
      ap = await open(admin);
    await ap
      .getByRole("button", {
        name: "Download verified private evidence",
        exact: true,
      })
      .waitFor();
    const foreign = await login("foreign"),
      fp = foreign.locator("[data-payment-case-evidence]");
    await fp
      .getByRole("button", {
        name: "Open internal dispute evidence",
        exact: true,
      })
      .click();
    await fp.getByText("No records.", { exact: true }).waitFor();
    const denied = await foreign.evaluate(
      async ({ id, evidence }) =>
        (await fetch(`/api/commercial/cases/${id}/evidence/${evidence}`))
          .status,
      { id: f.case_id, evidence: rows.data[0].id },
    );
    assert.equal(denied, 404);
    assert.deepEqual(errors, []);
    console.log(
      "PASS actual Laravel customer lost-ack evidence replay, one encrypted private record, verified download/hash, vendor/Admin review access, foreign denial, EN/AR RTL and Light/Dark390px. No provider submission or real refund.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
