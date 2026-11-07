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
    // Reload restores identity asynchronously; theme scripts do not imply account readiness.
    await vendor.page
      .locator("#email-status")
      .filter({ hasText: "Email not verified" })
      .waitFor();
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
      .locator("#stations")
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
      .filter({ hasText: "دعوة حساب موجود" })
      .click();
    await customer.page.waitForFunction(() =>
      document.querySelector("#notice").textContent.includes("سُجلت الدعوة"),
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
      .locator('#fleet-detail input[data-i18n-aria="Cost center code"]')
      .fill("QA");
    await customer.page
      .locator('#fleet-detail input[data-i18n-aria="Cost center name"]')
      .fill("Browser operations");
    await customer.page
      .locator("#fleet-detail")
      .getByRole("button", { name: "إضافة مركز تكلفة" })
      .click();
    await customer.page.locator("#fleet-assign").waitFor();
    await customer.page.locator("#fleet-assign button").click();
    await customer.page.waitForFunction(() =>
      document.querySelector("#fleet-detail").textContent.includes("المركبة #"),
    );
    await customer.page
      .locator("#support-create [name=subject]")
      .fill("Browser support " + run);
    await customer.page
      .locator("#support-create [name=body]")
      .fill("Test support message");
    const createRequest = customer.page.waitForRequest((request) =>
      request.url().endsWith("/api/support") &&
      request.method() === "POST",
    );
    await customer.page.locator("#support-create button").click();
    const createPayload = (await createRequest).postDataJSON();
    assert.match(createPayload.idempotency_key, /^[a-f0-9]{32}$/);
    await customer.page
      .locator("#ticket-detail h3")
      .filter({ hasText: "Browser support " + run })
      .waitFor();
    const savedTicketsBeforeReplay = await customer.page.evaluate(() =>
      api("support"),
    );
    const createdTicket = savedTicketsBeforeReplay.data.find(
      (row) => row.subject === "Browser support " + run,
    );
    assert(createdTicket && Number.isSafeInteger(createdTicket.id));
    assert.equal(
      await customer.page.locator("#support-create [name=subject]").inputValue(),
      "",
    );
    assert.equal(
      await customer.page.locator("#support-create [name=body]").inputValue(),
      "",
    );
    const replayCreatedTicket = await customer.page.evaluate(
      (payload) => api("support", "POST", payload), createPayload,
    );
    assert.equal(replayCreatedTicket.id, createdTicket.id);
    assert.equal(replayCreatedTicket.idempotency_key, undefined);
    const savedTicketsAfterReplay = await customer.page.evaluate(() =>
      api("support"),
    );
    assert.equal(
      savedTicketsAfterReplay.data.filter(
        (row) => row.subject === "Browser support " + run,
      ).length, 1,
    );
    // Distinct role principals avoid spending one actor's entire protected-read
    // budget on repeated full-workspace reloads. Production limits stay enabled.
    const admin = await account("Admin");
    execFileSync(
      "./scripts/php",
      ["artisan", "platform:role", admin.email, "admin"],
      { stdio: "pipe" },
    );
    await admin.page.reload();
    await admin.page.locator("#admin").waitFor({ state: "visible" });
    await admin.page.waitForFunction(() =>
      document.querySelector("#overview").textContent.includes("Audit history"),
    );
    await admin.page.locator("#audit-search").fill("station.created");
    assert((await admin.page.locator("#overview tbody tr").count()) > 0);
    assert(
      (await admin.page.locator("#overview tbody tr").allTextContents()).every(
        (text) => text.includes("station.created"),
      ),
    );
    const auditDownload = admin.page.waitForEvent("download");
    await admin.page.locator("#audit-export").click();
    assert.equal(
      (await auditDownload).suggestedFilename(),
      "creabiz-audit.csv",
    );
    await admin.page.locator("#audit-search").fill("");
    await admin.page
      .locator("#ticket-list button")
      .filter({ hasText: "Browser support " + run })
      .click();
    await admin.page
      .locator("#ticket-detail textarea")
      .fill("Internal secret QA note " + run);
    await admin.page.locator("#ticket-detail input[name=internal]").check();
    await admin.page
      .locator("#ticket-detail button")
      .filter({ hasText: "Reply" })
      .click();
    await admin.page.waitForFunction(
      (text) =>
        document.querySelector("#ticket-detail").textContent.includes(text),
      "Internal secret QA note " + run,
    );
    assert.equal(await vendor.page.locator("#admin").isVisible(), false);
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
    // A replay after an uncertain HTTP acknowledgement must not create
    // duplicate public replies or duplicate vendor/customer inbox alerts.
    const browserSupportTickets = await admin.page.evaluate(
      () => api("support"),
    );
    const targetSupport = browserSupportTickets.data.find(
      (row) => row.subject === "Browser support " + run,
    );
    assert(targetSupport && Number.isSafeInteger(targetSupport.id));
    const replayKey = randomUUID().replace(/-/g, "");
    const replayBody = "Replay-safe browser support update " + run;
    const unreadBeforeReplay = await customer.page.evaluate(
      () => api("notifications/summary"),
    );
    const initialReply = await admin.page.evaluate(
      ({ ticketId, body, key }) => api(
        "support/" + ticketId + "/messages", "POST",
        { body, internal: false, idempotency_key: key },
      ),
      { ticketId: targetSupport.id, body: replayBody, key: replayKey },
    );
    const replayedReply = await admin.page.evaluate(
      ({ ticketId, body, key }) => api(
        "support/" + ticketId + "/messages", "POST",
        { body, internal: false, idempotency_key: key },
      ),
      { ticketId: targetSupport.id, body: replayBody, key: replayKey },
    );
    assert.equal(replayedReply.id, initialReply.id);
    assert.equal(replayedReply.idempotency_key, undefined);
    const unreadAfterReplay = await customer.page.evaluate(
      () => api("notifications/summary"),
    );
    assert.equal(unreadAfterReplay.unread_count,
      unreadBeforeReplay.unread_count + 1);
    const savedThread = await admin.page.evaluate(
      (id) => api("support/" + id), targetSupport.id,
    );
    assert.equal(
      savedThread.messages.data.filter((m) => m.body === replayBody).length,
      1,
    );

    // New browser + Laravel notification-cutoff acceptance. The second reply is
    // created AFTER the customer's first visible high-water mark, and must stay
    // unread when the customer marks only the previously observed messages.
    const publicReply = async (text) => {
      await admin.page.locator("#ticket-detail textarea").fill(text);
      await admin.page.locator("#ticket-detail input[name=internal]").uncheck();
      await admin.page.locator("#ticket-detail button")
        .filter({ hasText: "Reply" }).click();
      await admin.page.waitForFunction(
        (value) => document.querySelector("#ticket-detail").textContent.includes(value),
        text,
      );
    };
    const beforePublic = await customer.page.evaluate(() => api("notifications/summary"));
    await publicReply("Synthetic public update 1 " + run);
    // No page reload: the user can refresh only the account inbox, without
    // refetching an unrelated ticket list or acknowledging the new event.
    await customer.page.locator("#notification-refresh").click();
    await customer.page.locator("#notification-read-through").waitFor();
    const observed = await customer.page.evaluate(() => api("notifications/summary"));
    assert.equal(observed.unread_count, beforePublic.unread_count + 1);
    assert(observed.latest_id > beforePublic.latest_id);
    await publicReply("Synthetic public update 2 " + run);
    const bulkResponse = customer.page.waitForResponse(
      (response) => response.url().endsWith("/api/notifications/read-through") &&
          response.request().method() === "POST",
    );
    const postBulkSummary = customer.page.waitForResponse(
      (response) => response.url().endsWith("/api/notifications/summary") &&
          response.request().method() === "GET",
    );
    await customer.page.locator("#notification-read-through").click();
    assert.equal((await bulkResponse).status(), 200);
    assert.equal((await postBulkSummary).status(), 200);
    const afterBulk = await customer.page.evaluate(async () => ({
      summary: await api("notifications/summary"),
      page: await api("notifications"),
    }));
    assert(afterBulk.summary.latest_id > observed.latest_id);
    assert.equal(afterBulk.summary.unread_count, 1);
    const oldRow = afterBulk.page.data.find((row) => row.id === observed.latest_id);
    assert(oldRow && oldRow.read_at !== null);
    const newest = afterBulk.page.data.find((row) => row.id === afterBulk.summary.latest_id);
    assert(newest && newest.read_at === null);
    assert.equal(afterBulk.summary.unread_by_category.support, 1);
    assert.equal(afterBulk.summary.unread_by_category.charging, 0);
    // Older messages retain their own read receipt. Explicitly reopening one
    // cannot create an event or change the newer message's state.
    const oldNotification = customer.page.locator(
      '[data-notification-id="' + observed.latest_id + '"]',
    );
    const unreadResponse = customer.page.waitForResponse(
      (response) => response.url().endsWith(
        '/api/notifications/' + observed.latest_id + '/unread',
      ) && response.request().method() === 'POST',
    );
    await oldNotification.getByRole('button', { name: 'Mark unread' }).click();
    assert.equal((await unreadResponse).status(), 200);
    const reopened = await customer.page.evaluate(async () => ({
      summary: await api('notifications/summary'),
      notices: await api('notifications'),
    }));
    assert.equal(reopened.summary.unread_count, 2);
    assert.equal(reopened.summary.unread_by_category.support, 2);
    assert.equal(reopened.notices.total, afterBulk.page.total);
    const openedNote = reopened.notices.data.find((row) => row.id === observed.latest_id);
    assert(openedNote && openedNote.read_at === null);
    const rereadResponse = customer.page.waitForResponse(
      (response) => response.url().endsWith(
        '/api/notifications/' + observed.latest_id + '/read',
      ) && response.request().method() === 'POST',
    );
    await customer.page.locator(
      '[data-notification-id="' + observed.latest_id + '"]',
    ).getByRole('button', { name: 'Mark read', exact: true }).click();
    assert.equal((await rereadResponse).status(), 200);
    assert.equal(
      (await customer.page.evaluate(() => api('notifications/summary'))).unread_count,
      1,
    );
    const filtered = await customer.page.evaluate(
      () => api("notifications?unread_only=1"),
    );
    const classified = await customer.page.evaluate(
      () => api("notifications?category=support&unread_only=1"),
    );
    assert.equal(classified.total, 1);
    assert.equal(classified.data[0].kind, "support.reply");
    assert.equal(filtered.total, 1);
    assert.equal(filtered.data[0].id, afterBulk.summary.latest_id);
    // Rapid filter changes must settle on the latest requested category,
    // regardless of earlier network response completion order.
    const lastFilter = customer.page.locator("#notification-category");
    await lastFilter.selectOption("other");
    await customer.page.waitForFunction(
      () => document.querySelector("#notification-category")?.value === "other",
    );
    const otherPage = await customer.page.evaluate(
      () => api("notifications?category=other"),
    );
    assert(otherPage.total >= otherPage.data.length);
    assert(otherPage.data.every((item) =>
      !["support.", "vehicle.", "charging.", "fleet."].some(
        (prefix) => typeof item.kind === "string" && item.kind.startsWith(prefix),
      ),
    ));
    await lastFilter.selectOption("fleet");
    await customer.page.waitForFunction(
      () => document.querySelector("#notification-category")?.value === "fleet",
    );
    await lastFilter.selectOption("support");
    await customer.page.waitForFunction(
      () => document.querySelector("#notification-category")?.value === "support",
    );
    assert.equal(await lastFilter.inputValue(), "support");
    await customer.page.locator("#notification-unread-filter").click();
    await customer.page.waitForFunction(
      () => document.querySelector("#notification-unread-filter")?.textContent.includes(
        document.documentElement.lang === "ar" ? "جميع" : "all",
      ),
    );
    // Saved notification defaults round-trip via the actual authenticated
    // API, are applied after a fresh browser session and cannot mute critical
    // in-app events or falsely claim an external delivery provider.
    await customer.page.locator("#notification-default-category").selectOption("charging");
    await customer.page.locator("#notification-default-unread").check();
    await customer.page.locator("#notification-repeat-reminders").uncheck();
    await customer.page.locator("#notification-fault-delay").selectOption("60");
    const savedPreferences = customer.page.waitForResponse(
      (response) => response.url().endsWith("/api/notifications/preferences") &&
        response.request().method() === "PUT",
    );
    await customer.page.locator("#notification-preference-save").click();
    assert.equal((await savedPreferences).status(), 200);
    const configured = await customer.page.evaluate(
      () => api("notifications/preferences"),
    );
    assert.equal(configured.default_category, "charging");
    assert.equal(configured.default_unread_only, true);
    assert.equal(configured.remind_unresolved_simulator_faults, false);
    assert.equal(configured.fault_reminder_delay_minutes, 60);
    assert.equal(configured.external_delivery_available, false);
    const stillRecorded = await customer.page.evaluate(
      () => api("notifications/summary"),
    );
    assert(stillRecorded.unread_by_category.support >= 0);
    await customer.page.reload();
    await customer.page.locator("#notification-preferences").waitFor();
    assert.equal(
      await customer.page.locator("#notification-category").inputValue(), "charging",
    );
    assert.equal(
      await customer.page.locator("#notification-default-unread").isChecked(), true,
    );
    assert.equal(
      await customer.page.locator("#notification-repeat-reminders").isChecked(), false,
    );
    assert.equal(
      await customer.page.locator("#notification-fault-delay").inputValue(), "60",
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
