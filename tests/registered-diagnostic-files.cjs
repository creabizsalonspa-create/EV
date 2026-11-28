const assert = require("node:assert/strict"),
  { randomBytes, randomUUID, createHash } = require("node:crypto");
const fs = require("node:fs"),
  path = require("node:path");
const withRuntime = require("./helpers/registered-diagnostic-runtime.cjs");
const { runNativeDiagnostics } = require("./helpers/registered-diagnostic-native-runner.cjs");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { graph, poll, SimulatedCharger } = require("./helpers/csms-local.cjs");
const {
  waitForRetainedDiagnosticRequest,
} = require("./helpers/diagnostic-source-ready.cjs");
async function login(base, email, password) {
  const c = new BusinessClient({ base });
  c.csrf = (await c.request("csrf")).token;
  c.user = await c.request("login", "POST", { email, password });
  c.csrf = (await c.request("csrf")).token;
  return c;
}
(async () => {
  fs.mkdirSync(path.join("artifacts", "diagnostics-production"), {
    recursive: true,
  });
  await withRuntime(async (runtime) => {
    const run = randomBytes(4).toString("hex"),
      password = randomBytes(24).toString("hex") + "aA9!";
    const fixture = runtime.fixture("seed", run, password),
      before = runtime.fixture("snapshot", run);
    assert(fixture.fixture_only);
    const owner = await login(runtime.base, fixture.owner_email, password),
      admin = await login(runtime.base, fixture.admin_email, password);
    await admin.request(
      "admin/second-factor/verify",
      "POST",
      runtime.fixture("mfa", run),
    );
    admin.csrf = (await admin.request("csrf")).token;
    const station = await owner.request("stations", "POST", {
      name: "Registered diagnostic " + run,
      country: "AE",
      currency: "AED",
      timezone: "Asia/Dubai",
      latitude: 25,
      longitude: 55,
      source: "direct_unverified",
      connector_type: "CCS2",
      power_kw: 50,
    });
    const company = "vendor-ops/companies/" + fixture.company_id;
    const site = await owner.request(company + "/sites", "POST", {
      name: "Diagnostic site",
      country: "AE",
      timezone: "Asia/Dubai",
      address: "Synthetic",
      latitude: 25,
      longitude: 55,
      station_id: station.id,
      idempotency_key: randomUUID(),
    });
    const verified = [];
    for (const item of fixture.chargers) {
      const charger = await owner.request(
        company + "/sites/" + site.id + "/chargers",
        "POST",
        {
          asset_reference: item.asset,
          manufacturer: "Synthetic",
          model: "Fixture",
          ocpp_protocol: item.protocol,
          source: "direct_unverified",
          idempotency_key: randomUUID(),
        },
      );
      item.id = charger.id;
      const base = company + "/chargers/" + charger.id;
      const prepared = await admin.request(
        base + "/source-registration",
        "POST",
        { identity: item.identity, idempotency_key: randomUUID() },
      );
      const registered = await admin.request(
        base + "/source-registration/" + prepared.registration_id + "/register",
        "POST",
      );
      assert.equal(registered.state, "registered");
      const credential = await owner.request(
        base + "/admission/credential",
        "POST",
        { expected_version: 0, days: 1 },
      );
      assert.equal(credential.one_time, true);
      assert.equal(typeof credential.secret, "string");
      const device = new SimulatedCharger(item.protocol, item.identity),
        bytes = Buffer.from("Synthetic TLS diagnostic " + item.protocol + "\n"),
        digest = createHash("sha256").update(bytes).digest("hex");
      let uploaded = false,
        uploadError = null;
      device.diagnosticHandler = async (payload) => {
        assert.equal(payload.retries, 0);
        const target = new URL(payload.location || payload.log.remoteLocation);
        assert.equal(target.origin, runtime.origin);
        assert.equal(target.protocol, "https:");
        assert.equal(target.username, "diagnostic");
        assert.match(target.password, /^[a-f0-9]{64}$/);
        const auth =
          "Basic " +
          Buffer.from(target.username + ":" + target.password).toString(
            "base64",
          );
        target.username = "";
        target.password = "";
        // Exercise directory-style1.6 file naming without changing the operation identity.
        if (item.protocol === "1.6") target.pathname += "/diagnostics.log";
        await waitForRetainedDiagnosticRequest({
          graph,
          identity: item.identity,
          protocol: item.protocol,
          payload,
        });
        setTimeout(() => {
          (async () => {
            const notification =
              item.protocol === "1.6"
                ? "DiagnosticsStatusNotification"
                : "LogStatusNotification";
            const fields =
              item.protocol === "1.6" ? {} : { requestId: payload.requestId };
            await device.call(notification, { status: "Uploading", ...fields });
            const result = await runtime.transfer(target, bytes, auth);
            assert.equal(result.status, 201);
            const replay = await runtime.transfer(target, bytes, auth);
            assert.equal(replay.status, 200);
            await device.call(notification, { status: "Uploaded", ...fields });
            uploaded = true;
          })().catch((error) => {
            uploadError = error;
          });
        }, 150);
        return item.protocol === "1.6"
          ? { fileName: "diagnostics.log" }
          : { status: "Accepted" };
      };
      try {
        await device.connect();
        await device.boot();
        await device.status("Available");
        const endpoint = base + "/diagnostic-logs",
          intent = {
            request_key: randomUUID(),
            reason: "Inspect isolated TLS source diagnostic log",
          };
        const created = await owner.request(endpoint, "POST", intent);
        assert.equal(created.evidence, "AUTHENTICATED_SOURCE");
        assert.equal(created.dispatch_state, "transport_submitted");
        assert.equal(
          (await owner.request(endpoint, "POST", intent)).id,
          created.id,
        );
        await poll(
          async () => {
            if (uploadError) throw uploadError;
            return uploaded;
          },
          "controlled TLS diagnostic upload",
          25000,
        );
        const observed = await poll(
          async () => {
            const row = await owner.request(
              endpoint + "/" + created.id + "/observe",
              "POST",
              {},
            );
            return row.can_download && row;
          },
          "owned correlated TLS diagnostic proof",
          25000,
        );
        assert.equal(observed.file_sha256, digest);
        assert.equal(observed.file_size, bytes.length);
        assert.equal(observed.physical_device_verified, false);
        assert.equal(
          observed.device_status,
          item.protocol === "1.6" ? "legacy_status_unattributed" : "Uploaded",
        );
        const download = await fetch(
          runtime.base + "/api/" + endpoint + "/" + created.id + "/download",
          {
            headers: {
              Cookie: [...owner.cookies]
                .map(([k, v]) => k + "=" + v)
                .join("; "),
            },
            signal: AbortSignal.timeout(10000),
            redirect: "error",
          },
        );
        assert.equal(download.status, 200);
        assert.equal(
          createHash("sha256")
            .update(Buffer.from(await download.arrayBuffer()))
            .digest("hex"),
          digest,
        );
        const history = await owner.request(endpoint);
        assert.equal(history.evidence, "AUTHENTICATED_SOURCE");
        assert(!JSON.stringify(history).includes("diagnostic:"));
        assert(!JSON.stringify(history).includes("consent_password_hash"));
        assert.equal(history.charging_capacity_changed, false);
        assert.equal(
          device.commands.filter((x) =>
            ["GetDiagnostics", "GetLog"].includes(x.action),
          ).length,
          1,
        );
        assert.equal(
          device.commands.filter(
            (x) => !["GetDiagnostics", "GetLog"].includes(x.action),
          ).length,
          0,
        );
        verified.push({
          protocol: item.protocol,
          charger_id: charger.id,
          operation_id: created.id,
          file_size: bytes.length,
          source: "controlled_TLS_OAuth_facade_with_actual_Citrine",
          file_digest: digest,
          transport: "TLS_verified",
          source_rpc: "correlated",
          legacy_status_unattributed: item.protocol === "1.6",
          physical_device_verified: false,
          device_commands_sent: 1,
          charging_commands_sent: 0,
        });
      } finally {
        await device.close();
      }
    }
    assert.deepEqual(
      runtime.fixture("snapshot", run),
      before,
      "Diagnostic RPCs must not alter charging commands/inbox/sessions/finance",
    );
    const { chromium } = require("playwright");
    const browser = await chromium.launch({
      executablePath: process.env.EV_CHROMIUM_PATH || process.env.CHROMIUM_PATH || undefined,
      headless: true,
      args: ["--no-sandbox", "--disable-dev-shm-usage"],
    });
    try {
      const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        acceptDownloads: true,
      });
      await context.addCookies(
        [...owner.cookies].map(([name, value]) => ({
          name,
          value,
          url: runtime.base,
        })),
      );
      const page = await context.newPage(),
        errors = [];
      page.on("pageerror", (error) => errors.push(error.message));
      await page.goto(runtime.base);
      const ops = page.locator("#vendor-operations");
      await ops
        .locator("article")
        .filter({
          has: page.getByRole("heading", {
            name: fixture.company_name,
            exact: true,
          }),
        })
        .first()
        .getByRole("button", { name: "Open company", exact: true })
        .click();
      await ops
        .getByRole("button", { name: "Open chargers", exact: true })
        .click();
      for (let index = 0; index < verified.length; index++) {
        const card = ops
          .getByRole("heading", {
            name: fixture.chargers[index].asset,
            exact: true,
          })
          .locator("..");
        await card
          .getByRole("button", { name: "Diagnostic files", exact: true })
          .click();
        const pane = card.locator("[data-diagnostic-files]");
        await pane
          .getByText(
            "Registered source diagnostics; physical device unverified.",
            { exact: true },
          )
          .first()
          .waitFor();
        const pendingDownload = page.waitForEvent("download");
        await pane
          .getByRole("button", {
            name: "Download private diagnostic file",
            exact: true,
          })
          .click();
        const file = await pendingDownload;
        assert.equal(
          createHash("sha256")
            .update(fs.readFileSync(await file.path()))
            .digest("hex"),
          verified[index].file_digest,
        );
        await file.delete();
      }
      await page.locator("#language").click();
      await page
        .getByText("تشخيص مصدر مسجل؛ الجهاز الفعلي غير متحقق.", { exact: true })
        .first()
        .waitFor();
      assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
      assert.equal(
        await page.evaluate(
          () => document.documentElement.scrollWidth > window.innerWidth,
        ),
        false,
      );
      assert.deepEqual(errors, []);
      await context.close();
    } finally {
      await browser.close();
    }
    const connectedNative = process.env.EV_RUN_CONNECTED_NATIVE_DIAGNOSTICS === "1";
    if (connectedNative || process.env.EV_NATIVE_DIAGNOSTICS_HOLD === "1") {
      const native = path.join(runtime.folder, "native-fixture.json");
      fs.writeFileSync(
        native,
        JSON.stringify({
          fixture_only: true,
          verify_native_revocation: connectedNative,
          base: runtime.base,
          owner: fixture.owner_email,
          password,
          company_id: fixture.company_id,
          chargers: verified.map(
            ({
              charger_id,
              protocol,
              operation_id,
              file_digest,
              file_size,
            }) => ({
              id: charger_id,
              protocol,
              operation_id,
              file_digest,
              file_size,
            }),
          ),
        }),
        { mode: 0o600 },
      );
      const marker = path.join(runtime.folder, "native.done");
      if (connectedNative) {
        await runNativeDiagnostics({ file: native, marker });
        for (const item of verified) {
          const endpoint = company + "/chargers/" + item.charger_id + "/diagnostic-logs";
          const history = await owner.request(endpoint);
          const receipt = history.data.find((row) => row.id === item.operation_id);
          assert(receipt && receipt.cancelled_at && receipt.content_removed_at);
          assert.equal(receipt.can_download, false);
          assert.equal(receipt.file_sha256, item.file_digest);
        }
        assert.deepEqual(runtime.fixture("snapshot", run), before);
        console.log("PASS connected native private downloads and explicit revocation; source RPC counts and charging/finance unchanged.");
      } else {
        console.log("Private native diagnostic fixture ready: " + native);
        const deadline = Date.now() + 300000;
        while (!fs.existsSync(marker) && Date.now() < deadline)
          await new Promise((resolve) => setTimeout(resolve, 1000));
        assert(fs.existsSync(marker), "Native fixture release was not received within the bounded active session");
      }
    }
    assert.deepEqual(runtime.counts(), { opsRequests: 2, uploads: 4 });
    fs.writeFileSync(
      "artifacts/diagnostics-production/tls-actual.json",
      JSON.stringify(
        {
          fixture_only: true,
          live_provider: false,
          physical_hardware: false,
          verified,
          counts: runtime.counts(),
          charging_and_finance_unchanged: true,
        },
        null,
        2,
      ) + "\n",
    );
    console.log(
      "PASS actual pinned Citrine1.6/2.0.1 diagnostic RPCs through controlled TLS/OAuth fixture: verified TLS uploads, safe1.6filename suffix, owned source request/reply evidence, immutable file replay/digest, private download and unchanged charging/finance. Test facade uses localDev only behind loopback; not live IdP/production charger acceptance.",
    );
  });
})().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
