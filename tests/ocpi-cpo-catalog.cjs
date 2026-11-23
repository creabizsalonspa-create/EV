const assert = require("node:assert/strict");
const { chromium } = require("playwright");

(async () => {
    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.EV_CHROMIUM_PATH || undefined,
        args: ["--no-sandbox", "--disable-dev-shm-usage"],
    });
    try {
        for (const arabic of [false, true]) {
            const language = arabic ? "ar" : "en";
            const label = (en, ar) => arabic ? ar : en;
            const page = await browser.newPage();
            await page.route("https://platform.example/", (route) => route.fulfill({
                contentType: "text/html",
                body: `<!doctype html><html lang="${language}" dir="${arabic ? "rtl" : "ltr"}"><main id="public"></main><main id="private"></main></html>`,
            }));
            await page.goto("https://platform.example/");
            await page.addScriptTag({ path: "services/business-api/public/locale-data.js" });
            await page.addScriptTag({ path: "services/business-api/public/roaming.js" });
            const errors = [];
            page.on("pageerror", (error) => errors.push(error.message));
            await page.evaluate(async ({ arabic }) => {
                window.viewer = { id: 7, role: "admin" };
                window.writes = [];
                const stamp = "2026-11-21T08:40:00.000000Z";
                const row = { source_id: "9007199254740993", module: "locations", published_at: stamp, authority_current: true, withdrawn: false, delivery: null };
                const cdr = { ...row, source_id: "11", module: "cdrs", delivery: "unknown" };
                const base = "ocpi/providers/42/cpo-catalog";
                const list = (data) => ({ data, current_page: 1, last_page: 1 });
                const api = async (path, method = "GET", body) => {
                    if (path === "ocpi/providers?page=1") return list([{ id: 42, name: "<img src=x onerror=alert(1)>", authorized: true, version: "2.2.1" }]);
                    if (path === "ocpi/connection-options" || path === "ocpi/owner-options?page=1" || path.startsWith("roaming/locations")) return list([]);
                    if (path === `${base}?page=1`) return { configured: true, sources: { locations: [row.source_id], tariffs: ["3"] }, history: list(row.delivery === "reconciled" ? [row, cdr] : [row]), sandbox_push: true };
                    window.writes.push({ path, method, body });
                    if (path.endsWith("/push")) {
                        row.delivery = "unknown";
                        throw Error("PRIVATE_PARTNER_RESPONSE");
                    }
                    if (path.endsWith("/reconcile")) {
                        const record = path.includes("/cdrs/") ? cdr : row;
                        record.delivery = "reconciled";
                    }
                    if (body?.action === "withdraw") row.withdrawn = true;
                    return {};
                };
                const ui = (tag, name) => {
                    const node = document.createElement(tag);
                    node.textContent = name === "CPO catalog publications" && arabic ? "منشورات كتالوج مشغّل الشحن" : name;
                    return node;
                };
                window.workspace = window.createRoamingWorkspace({
                    publicRoot: document.querySelector("#public"),
                    privateRoot: document.querySelector("#private"),
                    api, ui,
                    actor: () => window.viewer,
                    notice: (error) => { throw error; },
                });
                await window.workspace.setActor();
            }, { arabic });

            await page.getByRole("button", { name: label("CPO catalog publications", "منشورات كتالوج مشغّل الشحن") }).click();
            const panel = page.locator("[data-cpo-catalog]");
            const send = panel.getByRole("button", { name: label("Send to sandbox receiver", "إرسال إلى المستلم التجريبي") });
            await send.waitFor();
            assert.equal(await page.locator("img").count(), 0);
            page.once("dialog", (dialog) => dialog.dismiss());
            await send.click();
            assert.equal(await page.evaluate(() => window.writes.length), 0);
            page.once("dialog", (dialog) => dialog.accept());
            await send.click();
            await panel.getByRole("alert").waitFor();
            assert.equal(await panel.getByText("PRIVATE_PARTNER_RESPONSE").count(), 0);
            const original = await page.evaluate(() => window.writes[0]);
            assert.equal(original.path, "ocpi/providers/42/cpo-catalog/locations/9007199254740993/push");
            assert.equal(original.body.published_at, "2026-11-21T08:40:00.000000Z");
            assert.match(original.body.operation_key, /^[0-9a-f-]{36}$/);

            await panel.getByRole("button", { name: label("Refresh", "تحديث") }).click();
            const reconcile = panel.getByRole("button", { name: label("Reconcile sandbox delivery", "مطابقة الإرسال التجريبي") });
            await reconcile.waitFor();
            assert.equal(await send.count(), 0);
            page.once("dialog", (dialog) => dialog.accept());
            await reconcile.click();
            await panel.getByText(label("Delivery confirmed by sandbox receiver read", "أُكّد الإرسال بقراءة المستلم التجريبي")).waitFor();
            const cdr = panel.locator("article").filter({hasText: label("Charge detail record #11", "سجل تفاصيل الشحن #11")});
            await cdr.getByLabel(label("Peer-assigned CDR receipt ID", "معرّف إيصال تفاصيل الشحن من الشريك")).fill("peer-record");
            assert.equal(await cdr.getByRole("button", { name: label("Withdraw publication", "سحب المنشور") }).count(), 0);
            page.once("dialog", (dialog) => dialog.accept());
            await cdr.getByRole("button", { name: label("Reconcile sandbox delivery", "مطابقة الإرسال التجريبي") }).click();
            await cdr.getByText(label("Delivery confirmed by sandbox receiver read", "أُكّد الإرسال بقراءة المستلم التجريبي")).waitFor();
            const cdrRead = await page.evaluate(() => window.writes[2]);
            assert.equal(cdrRead.path, "ocpi/providers/42/cpo-catalog/cdrs/11/reconcile");
            assert.equal(cdrRead.body.cdr_receipt, "peer-record");

            const withdraw = panel.getByRole("button", { name: label("Withdraw publication", "سحب المنشور") });
            await page.evaluate(() => { window.viewer = { id: 8, role: "vendor" }; });
            await withdraw.click();
            assert.equal(await page.evaluate(() => window.writes.length), 3);
            await page.evaluate(() => window.workspace.setActor());
            assert.equal(await panel.count(), 0);
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log("PASS CPO catalog English and Arabic, explicit confirmation, exact ID and timestamp, uncertain acknowledgement, read-only recovery and account fence");
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
