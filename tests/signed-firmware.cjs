// Actual local signed OCPP delivery, verified synthetic bytes; never hardware.
const assert = require("node:assert/strict");
const {
  randomBytes,
  createHash,
  verify,
  X509Certificate,
} = require("node:crypto");
const { execFileSync } = require("node:child_process");
const { chromium } = require("playwright");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { SimulatedCharger, poll } = require("./helpers/csms-local.cjs");
(async () => {
  for (const prefix of ["16", "20"]) {
    const run = prefix + randomBytes(3).toString("hex"),
      password = randomBytes(24).toString("hex") + "aA9!";
    const f = JSON.parse(
      execFileSync(
        "./scripts/php",
        ["tests/Fixtures/signed-firmware.php", run, password],
        { encoding: "utf8" },
      ),
    );
    execFileSync("./scripts/signed-firmware-setup", { stdio: "pipe" });
    const login = async (email) => {
      const c = new BusinessClient();
      c.csrf = (await c.request("csrf")).token;
      await c.request("login", "POST", { email, password });
      c.csrf = (await c.request("csrf")).token;
      return c;
    };
    const owner = await login(`canonical-owner-${run}@example.test`),
      admin = await login(`firmware-admin-${run}@example.test`);
    const charger = new SimulatedCharger(f.protocol, f.identity);
    let verifiedBytes = false,
      location;
    charger.firmwareHandler = async (payload, action) => {
      assert.equal(
        action,
        f.protocol === "1.6" ? "SignedUpdateFirmware" : "UpdateFirmware",
      );
      assert.equal(payload.retries, 1);
      location = payload.firmware.location;
      const r = await fetch(location, { signal: AbortSignal.timeout(15000) });
      assert(r.ok, "Private firmware download denied");
      const bytes = Buffer.from(await r.arrayBuffer());
      assert.equal(createHash("sha256").update(bytes).digest("hex"), f.sha256);
      const cert = new X509Certificate(payload.firmware.signingCertificate);
      assert.equal(
        cert.fingerprint256.replaceAll(":", "").toLowerCase(),
        f.signer_fingerprint,
      );
      assert(
        verify(
          "sha256",
          bytes,
          cert.publicKey,
          Buffer.from(payload.firmware.signature, "base64"),
        ),
      );
      verifiedBytes = true;
      setTimeout(async () => {
        try {
          await charger.call(
            f.protocol === "1.6"
              ? "SignedFirmwareStatusNotification"
              : "FirmwareStatusNotification",
            { requestId: payload.requestId, status: "Installed" },
          );
          await charger.call(
            "BootNotification",
            f.protocol === "1.6"
              ? {
                  chargePointVendor: "Synthetic",
                  chargePointModel: "Fixture",
                  firmwareVersion: f.version,
                }
              : {
                  reason: "FirmwareUpdate",
                  chargingStation: {
                    vendorName: "Synthetic",
                    model: "Fixture",
                    firmwareVersion: f.version,
                  },
                },
          );
          await charger.status();
        } catch {
          process.exitCode = 1;
        }
      }, 200);
      return { status: "Accepted" };
    };
    const browser = await chromium.launch({
      headless: true,
      executablePath: process.env.EV_CHROMIUM_PATH || undefined,
      args: ["--no-sandbox"],
    });
    try {
      await charger.connect();
      await charger.call(
        "BootNotification",
        f.protocol === "1.6"
          ? { chargePointVendor: "Synthetic", chargePointModel: "Fixture" }
          : {
              reason: "PowerUp",
              chargingStation: { vendorName: "Synthetic", model: "Fixture" },
            },
      );
      await charger.status();
      const open = async (c) => {
        const ctx = await browser.newContext({
          viewport: { width: 390, height: 844 },
        });
        await ctx.addCookies(
          [...c.cookies].map(([name, value]) => ({ name, value, url: c.base })),
        );
        const p = await ctx.newPage();
        await p.goto(c.base);
        const ops = p.locator("#vendor-operations");
        await ops
          .locator("article")
          .filter({
            has: p.getByRole("heading", { name: f.name, exact: true }),
          })
          .first()
          .getByRole("button", { name: "Open company", exact: true })
          .click();
        return { p, ops };
      };
      const a = await open(admin);
      await a.ops
        .getByRole("button", { name: "Signed firmware releases", exact: true })
        .click();
      const releases = a.ops.locator("[data-firmware-releases]");
      const approval = releases.locator("form").first();
      await approval.locator("[name=catalog_key]").selectOption(f.catalog_key);
      await approval
        .locator("[name=note]")
        .fill("Reviewed independently trusted synthetic signature");
      const approvedReply=a.p.waitForResponse(r=>new URL(r.url()).pathname===`/api/vendor-ops/companies/${f.company_id}/firmware-releases`&&r.request().method()==='POST');
      const [approved]=await Promise.all([approvedReply,approval.getByRole("button", { name: "Save", exact: true }).click()]);
      assert.equal(approved.status(),201);
      const release=await approved.json();assert.equal(release.signature_verified,true);
      const approvedPage=await admin.request(`vendor-ops/companies/${f.company_id}/firmware-releases`);
      const evidence=approvedPage.data.find(row=>row.id===release.id);
      assert.deepEqual(evidence.compatibility_policy,{protocols:[f.protocol],from_versions:[f.initial_version],manifest_sha256:f.compatibility_manifest_sha256});
      await releases.getByText(f.sha256, { exact: true }).waitFor();
      const o = await open(owner);
      await o.ops
        .getByRole("button", { name: "Open chargers", exact: true })
        .click();
      await o.ops
        .getByRole("button", {
          name: "Signed firmware operations",
          exact: true,
        })
        .click();
      const pane = o.ops.locator("[data-firmware-operations]");
      const form = pane.locator("form").first();
      await form
        .locator("[name=reason]")
        .fill("Signed synthetic firmware demonstration only");
      const key = await form.locator("[name=idempotency_key]").inputValue();
      const releaseId = Number(
        await form.locator("[name=release_id]").inputValue(),
      );
      const path = `vendor-ops/companies/${f.company_id}/chargers/${f.charger_id}/firmware-operations`;
      // A read-only signed compatibility matrix must not infer firmware
      // compatibility from a BootNotification without a typed source version.
      const check = pane.getByRole("button", {
        name: "Check live source firmware compatibility",
        exact: true,
      });
      await check.click();
      await pane.locator("[data-firmware-compatibility]")
        .getByText("Source compatibility unavailable; no release recommended.", { exact: true })
        .waitFor();
      assert.equal(charger.commands.filter(c=>["SignedUpdateFirmware","UpdateFirmware"].includes(c.action)).length,0);
      // The first Boot intentionally omitted current firmware identity. The
      // production guard must reject it without a command or durable operation.
      await assert.rejects(owner.request(path,'POST',{release_id:releaseId,reason:'Signed synthetic firmware demonstration only',idempotency_key:key}),/failed \(409\): Complete typed source firmware identity/);
      assert.equal((await owner.request(path)).data.length,0);
      assert.equal(charger.commands.filter(c=>['SignedUpdateFirmware','UpdateFirmware'].includes(c.action)).length,0);
      await charger.call('BootNotification',f.protocol==='1.6'
        ?{chargePointVendor:'Synthetic',chargePointModel:'Fixture',firmwareVersion:f.initial_version}
        :{reason:'PowerUp',chargingStation:{vendorName:'Synthetic',model:'Fixture',firmwareVersion:f.initial_version}});
      await check.click();
      const matrix = pane.locator("[data-firmware-compatibility]");
      await matrix.getByText(`Source firmware: ${f.initial_version}`, { exact: true }).waitFor();
      await matrix.getByText(`${f.version}: Signed source match`, { exact: true }).waitFor();
      await o.p.locator("#language").click();
      await matrix.getByRole("heading", { name: "لقطة مصدر موثقة", exact: true }).waitFor();
      await matrix.getByText(`الإصدار ${f.version}: تطابق مصدر موقع`, { exact: true }).waitFor();
      await o.p.locator("#language").click();
      await matrix.getByRole("heading", { name: "Authenticated source snapshot", exact: true }).waitFor();
      const compatibilityPath = `**/api/vendor-ops/companies/${f.company_id}/chargers/${f.charger_id}/firmware-compatibility`;
      // A contradictory upstream/browser projection cannot promote a
      // revoked signer into a visually trusted firmware upgrade option.
      await o.p.route(compatibilityPath, async (route) => {
        const sourceReply = await route.fetch();
        assert.equal(sourceReply.status(), 200);
        const result = await sourceReply.json();
        assert(result.releases.length > 0);
        result.releases[0].signer_currently_trusted = false;
        await route.fulfill({ response: sourceReply, json: result });
      }, { times: 1 });
      await check.click();
      await matrix.getByText(`${f.version}: Not verified for this source`, { exact: true }).waitFor();
      assert.equal(await matrix.getByText(`${f.version}: Signed source match`, { exact: true }).count(), 0);
      await check.click();
      await matrix.getByText(`${f.version}: Signed source match`, { exact: true }).waitFor();
      assert.equal(charger.commands.filter(c=>["SignedUpdateFirmware","UpdateFirmware"].includes(c.action)).length,0);
      const submittedReply=o.p.waitForResponse(r=>new URL(r.url()).pathname==='/api/'+path&&r.request().method()==='POST');
      const [submitted]=await Promise.all([submittedReply,form.getByRole("button", { name: "Save", exact: true }).click()]);
      assert.equal(submitted.status(),201);assert.equal((await submitted.json()).status,'transport_submitted');
      await pane.getByText("transport_submitted", { exact: true }).waitFor();
      await owner.request(path, "POST", {
        release_id: releaseId,
        reason: "Signed synthetic firmware demonstration only",
        idempotency_key: key,
      });
      await poll(
        async () => verifiedBytes,
        "signed artifact byte verification",
      );
      await poll(async () => {
        const d = await owner.request(
          `vendor-ops/companies/${f.company_id}/chargers/${f.charger_id}/source-diagnostics`,
        );
        return d.firmware_version === f.version;
      }, "new source firmware version");
      assert.equal(
        charger.commands.filter((x) =>
          ["SignedUpdateFirmware", "UpdateFirmware"].includes(x.action),
        ).length,
        1,
      );
      await pane
        .locator("[name=note]")
        .fill("Reviewed correlated installation and idle source");
      await pane
        .locator("form")
        .last()
        .getByRole("button", { name: "Save", exact: true })
        .click();
      await pane.getByText("source_installed", { exact: true }).waitFor();
      assert.equal(
        (await fetch(location)).status,
        404,
        "Reviewed artifact URL must be revoked",
      );
      await o.p.locator("#language").click();
      await pane
        .getByRole("heading", {
          name: "عمليات البرامج الثابتة الموقعة",
          exact: true,
        })
        .waitFor();
      assert.equal(
        await o.p.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
        false,
      );
      console.log(
        `${f.protocol}: Admin signed release approval, exact synthetic byte/signature delivery, one-shot request, correlated source install/version review and operator EN/AR390px workflow passed; physical installation unverified`,
      );
    } finally {
      await browser.close();
      await charger.close();
    }
  }
})().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
