const { chromium } = require("playwright");
const { execFileSync } = require("node:child_process");
const assert = require("node:assert/strict");
const { randomUUID } = require("node:crypto");
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
  const run = randomUUID().slice(0, 8),
    password = randomUUID() + "aA9!";
  const errors = [];
  async function account(name) {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(base);
    await page.locator("#account [name=name]").fill(name);
    const email = name.toLowerCase() + run + "@example.test";
    await page.locator("#account [name=email]").fill(email);
    await page.locator("#account [name=password]").fill(password);
    for (let attempt = 0; attempt < 2; attempt++) {
      const reply = page.waitForResponse(
        (r) =>
          r.url().endsWith("/api/register") && r.request().method() === "POST",
      );
      await page.locator("button[value=register]").click();
      const response = await reply;
      if (response.status() === 429 && attempt === 0) {
        const seconds = Number(response.headers()["retry-after"] || 60);
        assert(seconds <= 60);
        console.log("Respecting authentication rate-limit retry interval.");
        await page.waitForTimeout((seconds + 1) * 1000);
        continue;
      }
      assert.equal(
        response.status(),
        201,
        (await response.json()).message || "Registration failed",
      );
      break;
    }
    await page.locator("#signed").waitFor({ state: "visible" });
    return { page, email };
  }
  try {
    const vendor = await account("Vendor");
    const theme = vendor.page.locator("#theme-choice");
    await vendor.page.emulateMedia({ colorScheme: "dark" });
    await theme.selectOption("system");
    await vendor.page.waitForFunction(
      () => document.documentElement.dataset.theme === "dark",
    );
    const darkBackground = await vendor.page.evaluate(
      () => getComputedStyle(document.body).backgroundColor,
    );
    await vendor.page.emulateMedia({ colorScheme: "light" });
    await vendor.page.waitForFunction(
      () => document.documentElement.dataset.theme === "light",
    );
    assert.notEqual(
      await vendor.page.evaluate(
        () => getComputedStyle(document.body).backgroundColor,
      ),
      darkBackground,
    );
    await theme.selectOption("dark");
    await vendor.page.reload();
    assert.equal(await theme.inputValue(), "dark");
    assert.equal(
      await vendor.page.locator("html").getAttribute("data-theme"),
      "dark",
    );
    await theme.selectOption("light");
    await vendor.page.emulateMedia({ colorScheme: "dark" });
    assert.equal(
      await vendor.page.locator("html").getAttribute("data-theme"),
      "light",
    );
    await theme.selectOption("dark");
    assert.match(
      await vendor.page.locator("#email-status").textContent(),
      /Email not verified/,
    );
    const sent = vendor.page.waitForResponse((r) =>
      r.url().endsWith("/api/email/verification-notification"),
    );
    await vendor.page.locator("#verify-email").click();
    assert.equal((await sent).status(), 202);
    // Obtain the normal notification URL in memory through the local test shell.
    // This is test-only tooling, not a public verification bypass or mail-delivery claim.
    const verificationUrl = execFileSync(
      "./scripts/php",
      [
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
        vendor.email,
        base,
      ],
      { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
    ).trim();
    await vendor.page.goto(verificationUrl);
    await vendor.page
      .getByRole("heading", { name: /Email verified/ })
      .waitFor();
    await vendor.page
      .getByRole("link", { name: /Return to your account/ })
      .click();
    await vendor.page.locator("#signed").waitFor({ state: "visible" });
    assert.match(
      await vendor.page.locator("#email-status").textContent(),
      /Email verified/,
    );
    assert.equal(await vendor.page.locator("#verify-email").isVisible(), false);
    execFileSync(
      "./scripts/php",
      ["artisan", "platform:role", vendor.email, "vendor"],
      { stdio: "pipe" },
    );
    await vendor.page.reload();
    await vendor.page.locator("#vendor").waitFor({ state: "visible" });
    const f = vendor.page.locator("#station-form");
    for (const [key, value] of Object.entries({
      name: "Browser station " + run,
      latitude: "25.2",
      longitude: "55.3",
      power_kw: "50",
    }))
      await f.locator(`[name=${key}]`).fill(value);
    await f.locator("[name=source]").selectOption("simulator");
    await f.locator("button").click();
    await vendor.page
      .getByRole("heading", { name: "Browser station " + run, exact: true })
      .waitFor();
    const customer = await account("Customer");
    const article = customer.page
      .locator("#stations article")
      .filter({ hasText: "Browser station " + run });
    const slot = new Date(Date.now() + 86400000);
    slot.setUTCMinutes(0, 0, 0);
    await article
      .locator("input[type=datetime-local]")
      .fill(slot.toISOString().slice(0, 16));
    await article
      .getByRole("button", { name: "Reserve simulator slot" })
      .click();
    await customer.page.locator("#reservation-list article").waitFor();
    await vendor.page.reload();
    await vendor.page.locator("#reservation-list article").waitFor();
    await customer.page
      .locator("#reservation-list button")
      .filter({ hasText: "Cancel" })
      .click();
    await customer.page.waitForFunction(() =>
      document
        .querySelector("#reservation-list")
        .textContent.includes("cancelled"),
    );
    await customer.page.locator("#language").click();
    assert.equal(
      await customer.page.locator("html").getAttribute("dir"),
      "rtl",
    );
    await customer.page.setViewportSize({ width: 390, height: 844 });
    assert(
      await customer.page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    const outsider = await account("Outsider");
    assert.equal(
      await outsider.page.locator("#reservation-list article").count(),
      0,
    );
    await customer.page
      .locator("#vehicle-create [name=name]")
      .fill("Browser EV " + run);
    await customer.page.locator("#vehicle-create button").click();
    await customer.page.waitForFunction(
      (name) =>
        document.querySelector("#vehicle-list").textContent.includes(name),
      "Browser EV " + run,
    );
    await customer.page
      .locator("#fleet-create [name=name]")
      .fill("Browser fleet " + run);
    await customer.page.locator("#fleet-create button").click();
    await customer.page
      .locator("#fleet-list button")
      .filter({ hasText: "Browser fleet " + run })
      .click();
    await customer.page
      .locator("#fleet-detail input[type=email]")
      .fill(outsider.email);
    await customer.page
      .locator("#fleet-detail button")
      .filter({ hasText: "Invite existing account" })
      .click();
    await customer.page.waitForFunction(() =>
      document
        .querySelector("#notice")
        .textContent.includes("Invitation recorded"),
    );
    await outsider.page.reload();
    await outsider.page.locator("#fleet-invitations button").click();
    await outsider.page
      .locator("#fleet-list button")
      .filter({ hasText: "Browser fleet " + run })
      .waitFor();
    assert.equal(
      await outsider.page.locator("#vehicle-list").textContent(),
      "",
    );
    await customer.page
      .locator('#fleet-detail input[aria-label="Cost center code"]')
      .fill("QA");
    await customer.page
      .locator('#fleet-detail input[aria-label="Cost center name"]')
      .fill("Browser operations");
    await customer.page
      .locator("#fleet-detail")
      .getByRole("button", { name: "Add cost center" })
      .click();
    await customer.page.locator("#fleet-assign").waitFor();
    await customer.page.locator("#fleet-assign button").click();
    await customer.page.waitForFunction(() =>
      document.querySelector("#fleet-detail").textContent.includes("Vehicle #"),
    );
    await customer.page
      .locator("#support-create [name=subject]")
      .fill("Browser support " + run);
    await customer.page
      .locator("#support-create [name=body]")
      .fill("Test support message");
    await customer.page.locator("#support-create button").click();
    await customer.page
      .locator("#ticket-detail h3")
      .filter({ hasText: "Browser support " + run })
      .waitFor();
    execFileSync(
      "./scripts/php",
      ["artisan", "platform:role", vendor.email, "admin"],
      { stdio: "pipe" },
    );
    await vendor.page.reload();
    await vendor.page.locator("#admin").waitFor({ state: "visible" });
    await vendor.page.waitForFunction(() =>
      document.querySelector("#overview").textContent.includes("Audit history"),
    );
    await vendor.page
      .locator("#ticket-list button")
      .filter({ hasText: "Browser support " + run })
      .click();
    await vendor.page
      .locator("#ticket-detail textarea")
      .fill("Internal secret QA note " + run);
    await vendor.page.locator("#ticket-detail input[name=internal]").check();
    await vendor.page
      .locator("#ticket-detail button")
      .filter({ hasText: "Reply" })
      .click();
    await vendor.page.waitForFunction(
      (text) =>
        document.querySelector("#ticket-detail").textContent.includes(text),
      "Internal secret QA note " + run,
    );
    await customer.page.reload();
    await customer.page
      .locator("#ticket-list button")
      .filter({ hasText: "Browser support " + run })
      .click();
    await customer.page.locator("#ticket-detail h3").waitFor();
    assert(
      !(await customer.page.locator("#ticket-detail").textContent()).includes(
        "Internal secret QA note",
      ),
    );
    await vendor.page.setViewportSize({ width: 390, height: 844 });
    assert(
      await vendor.page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    // Simulate an expired session response, then verify private DOM is cleared.
    await customer.page.route("**/api/me", (route) =>
      route.fulfill({
        status: 401,
        contentType: "application/json",
        body: JSON.stringify({ message: "Unauthenticated." }),
      }),
    );
    await customer.page.locator("#refresh-identity").click();
    await customer.page.locator("#account").waitFor({ state: "visible" });
    assert.equal(await customer.page.locator("#garage").isVisible(), false);
    assert.equal(
      await customer.page.locator("#vehicle-list").textContent(),
      "",
    );
    assert.equal(
      await customer.page.locator("#fleet-detail").textContent(),
      "",
    );
    assert.equal(await customer.page.locator("#who").textContent(), "");
    const restricted = await browser.newContext();
    await restricted.addInitScript(() => {
      Object.defineProperty(window, "localStorage", {
        get() {
          throw new DOMException("Storage unavailable", "SecurityError");
        },
      });
    });
    const restrictedPage = await restricted.newPage();
    restrictedPage.on("pageerror", (e) => errors.push(e.message));
    await restrictedPage.goto(base);
    await restrictedPage.locator("#theme-choice").selectOption("dark");
    assert.equal(
      await restrictedPage.locator("html").getAttribute("data-theme"),
      "dark",
    );
    await restricted.close();
    assert.deepEqual(errors, []);
    console.log(
      "PASS: browser Light/Dark/System themes, preference persistence and OS changes; registration, email verification request/signed confirmation/status, vendor station creation, customer reservation/cancel, vendor visibility, tenant privacy, admin overview, garage privacy, fleet invitation acceptance, support internal-note privacy, Arabic RTL, mobile width; no page errors.",
    );
  } catch (error) {
    console.error("Browser page errors:", errors);
    for (const context of browser.contexts())
      for (const page of context.pages())
        console.error(
          "UI status:",
          await page
            .locator("#notice")
            .textContent()
            .catch(() => ""),
        );
    throw error;
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
