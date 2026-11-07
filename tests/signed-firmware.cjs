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
      await approval.getByRole("button", { name: "Save", exact: true }).click();
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
      await form.getByRole("button", { name: "Save", exact: true }).click();
      await pane.getByText("transport_submitted", { exact: true }).waitFor();
      const path = `vendor-ops/companies/${f.company_id}/chargers/${f.charger_id}/firmware-operations`;
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
