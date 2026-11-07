// Controlled local Laravel server with synthetic server-only OCPI metadata.
// No partner request/negotiation is issued; registration is revoked by default.
const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const net = require("node:net");
const assert = require("node:assert/strict");
(async () => {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const fixture = JSON.parse(
    execFileSync(
      "./scripts/php",
      ["tests/Fixtures/ocpi-provisioning.php", run, password],
      { encoding: "utf8" },
    ),
  );
  const probe = net.createServer();
  await new Promise((resolve) => probe.listen(0, "127.0.0.1", resolve));
  const port = probe.address().port;
  await new Promise((resolve) => probe.close(resolve));
  const name = "ev-ocpi-registration-" + run,
    key = "registration_" + run;
  const token = randomBytes(32).toString("hex");
  const lostKey = "lost_ack_" + run;
  const metadata = JSON.stringify({
    [key]: {
      origin: "https://partner.example",
      token_env: "OCPI_TEST_TOKEN",
      environment: "sandbox",
    },
    [lostKey]: {
      origin: "https://partner.example",
      token_env: "OCPI_TEST_TOKEN",
      environment: "sandbox",
    },
    ["invalid_" + run]: {
      origin: "http://127.0.0.1",
      token_env: "OCPI_TEST_TOKEN",
    },
  });
  let browser;
  try {
    execFileSync(
      "docker",
      [
        "run",
        "-d",
        "--rm",
        "--name",
        name,
        "--network",
        "host",
        "--user",
        `${process.getuid()}:${process.getgid()}`,
        "-v",
        process.cwd() + ":/app",
        "-w",
        "/app/services/business-api",
        "-e",
        "OCPI_CONNECTIONS_JSON=" + metadata,
        "-e",
        "OCPI_TEST_TOKEN=" + token,
        "--entrypoint",
        "php",
        "composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc",
        "artisan",
        "serve",
        "--no-reload",
        "--host=127.0.0.1",
        "--port=" + port,
      ],
      { stdio: "pipe" },
    );
    const base = "http://127.0.0.1:" + port;
    let ready = false;
    for (let i = 0; i < 60; i++) {
      try {
        if ((await fetch(base + "/up")).ok) {
          ready = true;
          break;
        }
      } catch {}
      await new Promise((resolve) => setTimeout(resolve, 250));
    }
    assert(ready, "Isolated registration server did not become ready.");
    browser = await chromium.launch({
      headless: true,
      executablePath: process.env.EV_CHROMIUM_PATH || undefined,
      args: ["--no-sandbox"],
    });
    const context = await browser.newContext();
    context.setDefaultTimeout(15000);
    const page = await context.newPage(),
      errors = [],
      network = [];
    page.on("pageerror", (e) => errors.push(e.message));
    page.on("request", (r) => network.push(r.url()));
    await page.goto(base);
    await page.locator("#account [name=email]").fill(fixture.admin);
    await page.locator("#account [name=password]").fill(password);
    for (let attempt = 0; attempt < 2; attempt++) {
      const response = page.waitForResponse(
        (r) =>
          new URL(r.url()).pathname === "/api/login" &&
          r.request().method() === "POST",
      );
      await page.locator("button[value=login]").click();
      const login = await response;
      if (login.status() === 429 && attempt === 0) {
        const seconds = login.headers()["retry-after"];
        assert(/^\d+$/.test(seconds || "") && Number(seconds) <= 60);
        await page.waitForTimeout((Number(seconds) + 1) * 1000);
        continue;
      }
      assert.equal(login.status(), 200);
      break;
    }
    const root = page.locator("[data-ocpi-registration]");
    try {
      await root.locator("form").waitFor();
    } catch (error) {
      console.error(
        "Registration UI diagnostics:",
        errors,
        await root.allTextContents(),
      );
      const diagnostic = await context.request.get(
        base + "/api/ocpi/connection-options",
      );
      console.error("Connection option status:", diagnostic.status());
      if (diagnostic.ok())
        console.error("Safe options:", await diagnostic.json());
      throw error;
    }
    const configuration = await (
      await context.request.get(base + "/api/ocpi/connection-options")
    ).json();
    assert.equal(configuration.external_requests_made, false);
    const text = JSON.stringify(configuration);
    for (const privateValue of [
      token,
      "partner.example",
      "authorization_token",
      "OCPI_TEST_TOKEN",
    ])
      assert(!text.includes(privateValue));
    assert.equal(await root.locator("[name=connection_key] option").count(), 2);
    await root.locator("[name=owner_search]").fill("Registration " + run);
    await root
      .getByRole("button", { name: "Search provider vendors", exact: true })
      .click();
    await root.getByText("Page 1 of 2 · 26 records", { exact: true }).waitFor();
    await root.getByRole("button", { name: "Next", exact: true }).click();
    await root.getByText("Page 2 of 2 · 26 records", { exact: true }).waitFor();
    assert.equal(
      await root.locator("[name=owner_id]").inputValue(),
      String(fixture.vendor_id),
    );
    await root.locator("[name=name]").fill("Literal <registered provider> " + run);
    await page.locator("#language").click();
    assert.equal(
      await root.locator("[name=name]").inputValue(),
      "Literal <registered provider> " + run,
    );
    assert.equal(
      await root.locator("[name=connection_key] option:checked").textContent(),
      key,
    );
    assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
    await page.locator("#language").click();
    let writes = 0;
    page.on("request", (r) => {
      if (
        new URL(r.url()).pathname === "/api/ocpi/providers" &&
        r.method() === "POST"
      )
        writes++;
    });
    page.once("dialog", (d) => d.accept());
    const posted = page.waitForResponse(
      (r) =>
        new URL(r.url()).pathname === "/api/ocpi/providers" &&
        r.request().method() === "POST",
    );
    await root
      .getByRole("button", { name: "Register roaming provider", exact: true })
      .click();
    const response = await posted;
    assert.equal(response.status(), 201);
    const provider = await response.json();
    assert.equal(provider.authorized, false);
    assert.equal(provider.owner_id, fixture.vendor_id);
    assert(!("connection_key" in provider));
    await page
      .locator("#roaming-operations")
      .getByRole("heading", {
        name: "Literal <registered provider> " + run,
        exact: true,
      })
      .waitFor();
    await root
      .locator('[name=connection_key] option[value="' + lostKey + '"]')
      .waitFor({ state: "attached" });
    await root.locator("[name=owner_search]").fill("Registration " + run);
    await root
      .getByRole("button", { name: "Search provider vendors", exact: true })
      .click();
    await root.getByText("Page 1 of 2 · 26 records", { exact: true }).waitFor();
    await root.locator("[name=name]").fill("Lost-ack provider " + run);
    let committed;
    await page.route("**/api/ocpi/providers", async (route) => {
      if (route.request().method() !== "POST") return route.continue();
      const result = await route.fetch();
      assert.equal(result.status(), 201);
      committed = await result.json();
      await route.abort("failed");
    });
    page.once("dialog", (d) => d.accept());
    await root
      .getByRole("button", { name: "Register roaming provider", exact: true })
      .click();
    await root
      .getByText(
        "Registration was not confirmed. Refresh providers before trying again; it may already have been saved.",
        { exact: true },
      )
      .waitFor();
    assert(committed && !committed.authorized);
    assert.equal(await root.locator("form").count(), 0);
    await page.unroute("**/api/ocpi/providers");
    await root.getByRole("button", { name: "Refresh", exact: true }).click();
    await page
      .locator("#roaming-operations")
      .getByRole("heading", { name: "Lost-ack provider " + run, exact: true })
      .waitFor();
    await root
      .getByText(
        "No unassigned validated server connection is available. An operator must provision approved connection metadata and credentials securely before registration.",
        { exact: true },
      )
      .waitFor();
    await page.route("**/api/ocpi/connection-options", (route) =>
      route.fulfill({
        status: 503,
        contentType: "application/json",
        body: JSON.stringify({ message: "synthetic failure" }),
      }),
    );
    await page
      .locator("#roaming-operations")
      .getByRole("button", { name: "Refresh", exact: true })
      .first()
      .click();
    await root
      .getByText(
        "Connection registration options could not be loaded. Existing provider operations remain available.",
        { exact: true },
      )
      .waitFor();
    await page
      .locator("#roaming-operations")
      .getByRole("heading", { name: "Lost-ack provider " + run, exact: true })
      .waitFor();
    assert.equal(writes, 2);
    assert.deepEqual(errors, []);
    assert(
      network.every((url) => new URL(url).origin === base),
      "Registration must make no partner/network request.",
    );
    console.log(
      "PASS: actual Laravel admin-only safe connection options and paginated vendor selection; EN/AR provider registration against server-only synthetic metadata; revoked default, committed lost-ack recovery without replay, existing operations survive selector failure, no credentials exposed and no external requests.",
    );
  } finally {
    if (browser) await browser.close();
    try {
      execFileSync("docker", ["rm", "-f", name], { stdio: "pipe" });
    } catch {}
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
