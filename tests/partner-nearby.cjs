// Agent 8: synthetic browser contract for partner proximity and source-age UI.
// All distances are mocked and explicitly non-driving; no operator or charger is contacted.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");

(async () => {
    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.EV_CHROMIUM_PATH || undefined,
        args: ["--no-sandbox"],
    });
    try {
        const page = await browser.newPage();
        const errors = [];
        const urls = [];
        page.on("pageerror", (e) => errors.push(e.message));
        await page.route("**/api/roaming/locations*", (route) => {
            const uri = new URL(route.request().url());
            urls.push(uri);
            const nearby = uri.searchParams.has("latitude");
            return route.fulfill({
                json: {
                    data: [
                        {
                            id: 19,
                            provider_id: 9,
                            evidence: "PARTNER_REPORTED_UNVERIFIED",
                            availability: "unknown",
                            remote_control: false,
                            reservable: false,
                            source_timestamp_evidence: "PARTNER_REPORTED_ONLY",
                            received_at: "2026-11-07T12:00:00Z",
                            source_updated_at: "2026-11-07T10:00:00Z",
                            reported_source_age_minutes: 120,
                            ...(nearby
                                ? { distance_km: 1.234, distance_basis: "great_circle" }
                                : {}),
                            location: {
                                id: "mock-station",
                                name: "Nearby partner fixture",
                                country: "ARE",
                                city: "Test city",
                                address: "Test street",
                                latitude: "25.2",
                                longitude: "55.3",
                                last_updated: "2026-11-07T10:00:00Z",
                                evses: [],
                                availability: "unknown",
                                remote_control: false,
                                reservable: false,
                            },
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    total: 1,
                    next_page_url: null,
                },
            });
        });

        await page.goto(process.env.EV_TEST_URL || "http://127.0.0.1:8000");
        const root = page.locator("#roaming-discovery");
        await root.getByText("Nearby partner fixture", { exact: true }).waitFor();
        assert.equal(await root.getByText("1.23 km").count(), 0);
        assert.equal(await root.getByRole("button", { name: "Request charge" }).count(), 0);

        const lat = root.locator("[name=latitude]");
        const lng = root.locator("[name=longitude]");
        const radius = root.locator("[name=radius_km]");
        await lat.fill("25.2");
        assert.equal(await root.locator("form").evaluate((form) => form.checkValidity()), false);
        await lng.fill("55.3");
        await radius.fill("10");
        assert.equal(await root.locator("form").evaluate((form) => form.checkValidity()), true);

        const search = root.getByRole("button", {
            name: "Search partner locations",
            exact: true,
        });
        await search.click();
        await root.getByText("1.23 km", { exact: true }).waitFor();
        await root.getByText("120 min · not live availability", { exact: true }).waitFor();
        const queried = urls.filter((uri) => uri.searchParams.has("latitude")).at(-1);
        assert.equal(queried.searchParams.get("latitude"), "25.2");
        assert.equal(queried.searchParams.get("longitude"), "55.3");
        assert.equal(queried.searchParams.get("radius_km"), "10");
        assert.equal(await root.locator("form").count(), 1);
        assert.equal(await root.locator('button[data-command="remote-start"]').count(), 0);

        await page.locator("#language").click();
        await root.getByText("المسافة بخط مستقيم (ليست مسافة قيادة):", { exact: true }).waitFor();
        assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
        assert.deepEqual(errors, []);
        console.log("PASS synthetic partner nearby: grouped coordinates, kilometer-only distance, explicit source age, Arabic RTL and no charger-control path.");
    } finally {
        await browser.close();
    }
})().catch((err) => {
    console.error(err.message);
    process.exitCode = 1;
});
