"use strict";

const assert = require("node:assert/strict");
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const { chromium } = require("playwright");
const { BusinessClient } = require("./helpers/business-client.cjs");

async function verifyLanguage(base, browser, protocol, language) {
    const run = randomBytes(4).toString("hex");
    const password = randomBytes(24).toString("hex") + "Aa9!";
    const runner = process.env.EV_TEST_PHP_RUNNER || "./scripts/php";
    const fixture = (mode, ...args) => JSON.parse(execFileSync(
        runner, ["tests/Fixtures/vendor-session-observations.php", mode, run, ...args],
        { encoding: "utf8" },
    ));
    const f = fixture("seed", password, "stop-" + protocol);
    const before = fixture("snapshot");
    assert.equal(before.rows, 28);
    const path = "vendor-ops/companies/" + f.company_id;
    const sessions = path + "/charging-sessions";
    const stop = sessions + "/" + f.stop_session_id + "/stop";

    async function login(email) {
        const session = new BusinessClient({ base });
        session.csrf = (await session.request("csrf")).token;
        await session.request("login", "POST", { email, password });
        session.csrf = (await session.request("csrf")).token;
        return session;
    }

    const owner = await login(f.owner_email);
    const staff = await login(f.staff_email);
    await owner.request(path + "/staff/" + f.membership_id + "/permissions", "PATCH", {
        expected_version: 1, permissions: ["charging.read", "charging.stop"],
    });
    const grant = { grant_id: f.membership_id, grant_version: 2, consent: true };
    await assert.rejects(() => staff.request(stop, "POST", grant), /404/);
    await staff.request("vendor-ops/staff-invitations/" + f.membership_id + "/accept", "POST", {
        expected_version: 2, consent: true,
    });
    const ready = await staff.request(sessions);
    assert.equal(ready.data.find(row => row.id === f.stop_session_id).can_request_stop, true);
    assert.equal(ready.control_actions_exposed, false);

    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    try {
        await context.addCookies([...staff.cookies].map(([name, value]) => ({ name, value, url: base })));
        const page = await context.newPage();
        page.setDefaultTimeout(15000);
        await page.goto(base);
        await page.waitForLoadState("networkidle");
        const ar = language === "ar";
        if (ar) {
            await page.locator("#language").click();
            await page.waitForFunction(() => document.documentElement.dir === "rtl");
        }
        await page.getByRole("button", {
            name: ar ? "جلسات الشحن المسجلة" : "Recorded charging sessions", exact: true,
        }).click();
        const panel = page.locator("[data-company-session-observations]");
        const button = panel.getByRole("button", {
            name: ar ? "طلب إيقاف جلسة محاكاة" : "Request a simulator stop", exact: true,
        });
        let confirmed = null;
        page.once("dialog", async dialog => {
            confirmed = dialog.message();
            await dialog.accept();
        });
        const result = page.waitForResponse(response =>
            new URL(response.url()).pathname === "/api/" + stop && response.request().method() === "POST",
        );
        await button.click();
        const response = await result;
        assert.equal(response.status(), 202);
        assert(confirmed.includes(String(f.stop_session_id)));
        const receipt = await response.json();
        assert.equal(receipt.company_id, f.company_id);
        assert.equal(receipt.session_id, f.stop_session_id);
        assert.equal(receipt.command_status, "pending");
        assert.equal(receipt.session_status, "stopping");
        assert.equal(receipt.physical_charging_verified, false);
        assert(!JSON.stringify(receipt).includes("PRIVATE-"));
        await panel.getByText(ar
            ? "تم تسجيل طلب إيقاف المحاكاة. اقرأ حالة الجلسة الحالية قبل المتابعة."
            : "Simulator stop request recorded. Read the current session state before continuing.",
            { exact: true },
        ).waitFor();
        await panel.getByRole("button", {
            name: ar ? "قراءة الصفحة الأولى" : "Read first page", exact: true,
        }).click();
        await panel.getByRole("button", {
            name: ar ? "طلب إيقاف جلسة محاكاة" : "Request a simulator stop", exact: true,
        }).waitFor({ state: "hidden" });
        const replay = await staff.request(stop, "POST", grant);
        assert.equal(replay.command_id, receipt.command_id);
        const after = fixture("snapshot");
        assert.equal(after.commands, before.commands + 1);
        assert.equal(after.canonical_finance_hash, before.canonical_finance_hash);
        assert.equal(after.meter_history_hash, before.meter_history_hash);
    } finally {
        await context.close();
    }
}

(async () => {
    const base = process.env.EV_TEST_URL || "http://127.0.0.1:8000";
    const target = new URL(base);
    assert(target.protocol === "http:" && ["127.0.0.1", "localhost"].includes(target.hostname));
    const browser = await chromium.launch({
        ...(process.env.EV_CHROMIUM_PATH ? { executablePath: process.env.EV_CHROMIUM_PATH } : {}),
        headless: true,
        args: ["--no-sandbox"],
    });
    try {
        await verifyLanguage(base, browser, "1.6", "en");
        await verifyLanguage(base, browser, "2.0.1", "ar");
        console.log("PASS: English and Arabic staff permissions queue one scoped simulator stop per active session through Laravel.");
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
