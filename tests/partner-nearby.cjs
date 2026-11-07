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
        let futureClock = false;
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
                            provider_label: "Unverified Network_50%!",
                            provider_label_evidence: "PROVIDER_CONFIGURED_UNVERIFIED",
                            evidence: "PARTNER_REPORTED_UNVERIFIED",
                            availability: "unknown",
                            remote_control: false,
                            reservable: false,
                            source_timestamp_evidence: "PARTNER_REPORTED_ONLY",
                            received_at: "2026-11-07T12:00:00Z",
                            source_updated_at: "2026-11-07T10:00:00Z",
                            reported_source_age_minutes: futureClock ? null : 120,
                            source_clock_evidence: futureClock ? "PARTNER_CLOCK_AHEAD" : "PARTNER_REPORTED_TIME",
                            received_age_minutes: 1,
                            received_timestamp_evidence: "LOCAL_INGESTION_TIME",
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

        const reportedStatus = root.locator("[name=reported_evse_status]");
        await reportedStatus.selectOption("AVAILABLE");
        const maxAge = root.locator("[name=max_source_age_hours]");
        await maxAge.fill("6");
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
        assert.equal(queried.searchParams.get("max_source_age_hours"), "6");
        assert.equal(queried.searchParams.get("reported_evse_status"), "AVAILABLE");
        await root.getByText("1 min · not live availability", { exact: true }).waitFor();
        assert.equal(await root.locator("form").count(), 1);
        assert.equal(await root.locator('button[data-command="remote-start"]').count(), 0);
        await root.getByText("Configured provider (not operator-certified):", { exact: true }).waitFor();
        await root.locator("[name=partner_provider]").fill("Network_50%!");
        await root.getByRole("button", { name: "Search partner locations", exact: true }).click();
        await root.getByText("Unverified Network_50%!", { exact: true }).waitFor();
        const providerUri = urls.at(-1);
        assert.equal(providerUri.searchParams.get("provider"), "Network_50%!");
        assert.equal(providerUri.searchParams.get("radius_km"), "10");

        futureClock = true;
        await root.getByRole("button", { name: "Refresh", exact: true }).click();
        await root.getByText("Warning: partner's reported timestamp is in the future; freshness cannot be confirmed.", { exact: true }).waitFor();
        await page.locator("#language").click();
        await root.getByText("المسافة بخط مستقيم (ليست مسافة قيادة):", { exact: true }).waitFor();
        await root.getByText("مقدم الخدمة المُسجل (دون اعتماد من المشغل):", { exact: true }).waitFor();
        await root.getByText("تحذير: طابع وقت الشريك في المستقبل؛ لا يمكن تأكيد حداثة البيانات.", { exact: true }).waitFor();
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
