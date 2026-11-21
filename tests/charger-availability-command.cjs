// Real Laravel + pinned CitrineOS + WebSocket simulator, using isolated identities.
const { execFileSync } = require("node:child_process");
const { randomBytes } = require("node:crypto");
const assert = require("node:assert/strict");
const { chromium } = require("playwright");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { SimulatedCharger, poll } = require("./helpers/csms-local.cjs");

async function verify(browser, prefix) {
    const fixtureId = prefix + randomBytes(3).toString("hex");
    const password = randomBytes(24).toString("hex") + "aA9!";
    const fixture = JSON.parse(execFileSync(
        "./scripts/php",
        ["tests/Fixtures/charger-diagnostics.php", fixtureId, password],
        { encoding: "utf8" },
    ));
    assert.equal(fixture.fixture_only, true);

    const client = new BusinessClient();
    client.csrf = (await client.request("csrf")).token;
    await client.request("login", "POST", {
        email: `canonical-owner-${fixtureId}@example.test`,
        password,
    });
    client.csrf = (await client.request("csrf")).token;

    const device = new SimulatedCharger(fixture.protocol, fixture.identity);
    device.availabilityReply = prefix === "16" ? "Accepted" : "Scheduled";
    const context = await browser.newContext({ viewport: { width: 720, height: 900 } });
    try {
        await device.connect();
        await device.boot();
        await device.status();
        await context.addCookies([...client.cookies].map(([name, value]) => ({ name, value, url: client.base })));
        const page = await context.newPage();
        await page.goto(client.base);
        const workspace = page.locator("#vendor-operations");
        await workspace.locator("article").filter({
            has: page.getByRole("heading", { name: fixture.name, exact: true }),
        }).getByRole("button", { name: "Open company", exact: true }).click();
        await workspace.getByRole("button", { name: "Open chargers", exact: true }).click();
        await workspace.getByRole("button", { name: "Charger availability commands", exact: true }).click();

        const panel = workspace.locator("[data-availability-operations]");
        const form = panel.locator("form");
        await form.getByLabel("Reason", { exact: true }).fill("Schedule simulator availability review");
        await form.getByLabel("Requested state", { exact: true }).selectOption("Inoperative");
        const requestPath = `vendor-ops/companies/${fixture.company_id}/chargers/${fixture.charger_id}/availability-operations`;
        const formIdentity = await form.locator('input[name="idempotency_key"]').inputValue();
        const createResponse = page.waitForResponse((response) =>
            response.url().endsWith(requestPath) && response.request().method() === "POST",
        );
        await form.getByRole("button", { name: "Save", exact: true }).click();
        const created = await createResponse;
        assert.equal(created.status(), 201);
        const receipt = await created.json();
        assert.equal(receipt.status, "transport_submitted");
        assert.equal(receipt.physical_availability_verified, false);

        await poll(() => device.commands.find((command) => command.action === "ChangeAvailability"), "availability protocol action");
        assert.deepEqual(
            device.commands.find((command) => command.action === "ChangeAvailability").payload,
            prefix === "16" ? { connectorId: 0, type: "Inoperative" } : { operationalStatus: "Inoperative" },
        );
        const label = prefix === "16" ? "Simulator accepted" : "Simulator scheduled";
        await poll(async () => {
            if (await panel.getByText(label, { exact: true }).isVisible()) return true;
            await panel.getByRole("button", { name: "Check simulator response", exact: true }).click();
            return false;
        }, "correlated simulator confirmation");

        const replay = await client.request(requestPath, "POST", {
            requested_state: "Inoperative",
            connector_id: null,
            reason: "Schedule simulator availability review",
            idempotency_key: formIdentity,
        });
        assert.equal(replay.id, receipt.id);
        assert.equal(replay.physical_availability_verified, false);
        assert.equal(device.commands.filter((command) => command.action === "ChangeAvailability").length, 1);
    } finally {
        await device.close();
        await context.close();
    }
}

(async () => {
    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.EV_CHROMIUM_PATH || undefined,
        args: ["--no-sandbox"],
    });
    try {
        await verify(browser, "16");
        await verify(browser, "20");
        console.log("OCPP 1.6 and 2.0.1 availability command browser, source confirmation and one-shot recovery passed");
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
