const { execFileSync, spawnSync } = require("node:child_process");
const { randomBytes, randomUUID } = require("node:crypto");
const fs = require("node:fs"),
  path = require("node:path"),
  os = require("node:os");
const assert = require("node:assert/strict");
const { BusinessClient } = require("./helpers/business-client.cjs");
async function main() {
  const run = randomBytes(4).toString("hex"),
    password = randomBytes(24).toString("hex") + "aA9!";
  const args = ["tests/Fixtures/charging-reviews.php", run, password];
  const local = process.env.EV_LOCAL_PHP_EXEC === "1";
  const f = JSON.parse(
    execFileSync(
      local ? "docker" : "./scripts/php",
      local
        ? [
            "exec",
            "-u",
            "1000:1000",
            "-w",
            "/app/services/business-api",
            "ev-business-api",
            "php",
            ...args,
          ]
        : args,
      { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
    ),
  );
  if (process.env.EV_REVIEWS_NATIVE_ONLY === "1") {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), "ev-feedback-native-"));
    try {
      const input = path.join(dir, "fixture.json");
      fs.writeFileSync(input, JSON.stringify(f), { mode: 0o600 });
      const result = spawnSync(
        "flutter",
        [
          "test",
          "test/live_charging_reviews_test.dart",
          "--reporter",
          "expanded",
        ],
        {
          cwd: "apps/mobile",
          stdio: "inherit",
          env: { ...process.env, EV_NATIVE_REVIEWS_FIXTURE: input },
        },
      );
      assert.equal(result.status, 0, "Native feedback HTTP proof failed");
    } finally {
      fs.rmSync(dir, { recursive: true, force: true });
    }
    return;
  }
  const login = async (role) => {
    const c = new BusinessClient();
    c.csrf = (await c.request("csrf")).token;
    await c.request("login", "POST", f.accounts[role]);
    c.csrf = (await c.request("csrf")).token;
    return c;
  };
  const customer = await login("customer"),
    admin = await login("admin");
  const { chromium } = require("playwright");
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.EV_CHROMIUM_PATH || undefined,
    args: ["--no-sandbox"],
  });
  try {
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 },
    });
    await context.addCookies(
      [...customer.cookies].map(([name, value]) => ({
        name,
        value,
        url: customer.base,
      })),
    );
    const page = await context.newPage(),
      errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(customer.base);
    const root = page.locator("#charging-reviews");
    await root
      .getByRole("button", {
        name: "Completed sessions for feedback",
        exact: true,
      })
      .click();
    await root
      .getByRole("button", { name: "Write feedback", exact: true })
      .click();
    const form = root.locator("[data-review-editor]");
    await form.locator("[name=rating]").selectOption("4");
    await form
      .locator("[name=body]")
      .fill("Synthetic helpful feedback <script>plain text only</script>");
    await form.getByRole("checkbox").check();
    await form
      .getByRole("button", { name: "Send for moderation", exact: true })
      .click();
    await root
      .getByRole("button", { name: "My feedback", exact: true })
      .click();
    await root
      .getByText(
        "Synthetic helpful feedback <script>plain text only</script>",
        { exact: true },
      )
      .waitFor();
    let row = (await customer.request("charging-reviews/mine")).data[0];
    assert.equal(row.state, "pending");
    assert.equal(row.evidence, "SIMULATED_APP_ONLY");
    assert.equal(
      (await customer.request(`stations/${f.station_id}/reviews`)).total,
      0,
    );
    // Admin uses the actual independent moderation screen, without driver controls.
    const adminContext = await browser.newContext();
    await adminContext.addCookies(
      [...admin.cookies].map(([name, value]) => ({
        name,
        value,
        url: admin.base,
      })),
    );
    const ap = await adminContext.newPage();
    await ap.goto(admin.base);
    const adminRoot = ap.locator("#charging-reviews");
    const card = adminRoot
      .locator("article")
      .filter({
        hasText: "Synthetic helpful feedback <script>plain text only</script>",
      });
    await card
      .locator("textarea")
      .fill("Reviewed synthetic feedback for publication");
    await card.getByRole("button", { name: "Publish", exact: true }).click();
    await card.getByText("published", { exact: true }).waitFor();
    let pub = await customer.request(`stations/${f.station_id}/reviews`);
    assert.equal(pub.groups[1].count, 1);
    assert(!JSON.stringify(pub).includes("user_id"));
    assert(!JSON.stringify(pub).includes("session_id"));
    await root.getByRole("button", { name: "Refresh", exact: true }).click();
    await root
      .getByRole("button", { name: "Revise feedback", exact: true })
      .click();
    const revised = root.locator("[data-review-editor]");
    await revised
      .locator("[name=body]")
      .fill("Revised synthetic feedback, pending fresh moderation");
    await revised.getByRole("checkbox").check();
    await revised
      .getByRole("button", { name: "Send for moderation", exact: true })
      .click();
    await root.getByText("pending", { exact: true }).waitFor();
    assert.equal(
      (await customer.request(`stations/${f.station_id}/reviews`)).total,
      0,
    );
    await page.locator("#language").click();
    await root
      .getByRole("heading", { name: "ملاحظات الشحن", exact: true })
      .waitFor();
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth + 1,
      ),
      false,
    );
    await page.locator("#language").click();
    await root
      .getByRole("button", { name: "Withdraw publication", exact: true })
      .click();
    await root.getByText("withdrawn", { exact: true }).waitFor();
    await root
      .getByRole("button", { name: "Feedback history", exact: true })
      .click();
    await root
      .getByText("Revised synthetic feedback, pending fresh moderation", {
        exact: true,
      })
      .waitFor();
    await page.locator("#logout").click();
    await page.waitForFunction(
      () => document.querySelector("#charging-reviews").hidden,
    );
    assert.equal(await root.textContent(), "");
    assert.deepEqual(errors, []);
    console.log(
      "PASS completed app simulator -> consented private feedback -> independent Admin moderation -> public anonymous rating -> revised pending -> withdrawal/history, plain-text safety, Arabic phone and logout. No physical charging verification.",
    );
  } finally {
    await browser.close();
  }
}
main().catch((e) => {
  console.error(
    e.name === "AssertionError" || e.name === "TimeoutError"
      ? e.message
      : "Feedback integration failed; private fixture details withheld.",
  );
  process.exitCode = 1;
});
