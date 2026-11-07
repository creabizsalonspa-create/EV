const assert = require("node:assert/strict");
const { execFileSync, spawn } = require("node:child_process");
const { randomBytes, randomUUID, createHash } = require("node:crypto");
const { chromium } = require("playwright");
const fs = require("node:fs"),
  os = require("node:os"),
  path = require("node:path");
const { BusinessClient } = require("./helpers/business-client.cjs");
const { SimulatedCharger, poll } = require("./helpers/csms-local.cjs");
(async () => {
  execFileSync("./scripts/diagnostic-logs-setup", { stdio: "pipe" });
  for (const prefix of ["16", "20"]) {
    const run = prefix + randomBytes(3).toString("hex"),
      password = randomBytes(24).toString("hex") + "aA9!";
    const f = JSON.parse(
      execFileSync(
        "./scripts/php",
        ["tests/Fixtures/charger-diagnostics.php", run, password],
        { encoding: "utf8" },
      ),
    );
    const client = new BusinessClient();
    client.csrf = (await client.request("csrf")).token;
    await client.request("login", "POST", {
      email: `canonical-owner-${run}@example.test`,
      password,
    });
    client.csrf = (await client.request("csrf")).token;
    const device = new SimulatedCharger(f.protocol, f.identity);
    const bytes = Buffer.from(
      "Synthetic private diagnostic data\nOCPP " + f.protocol + "\n",
    );
    const digest = createHash("sha256").update(bytes).digest("hex");
    let uploaded = false,
      transferError = null;
    device.diagnosticHandler = (payload) => {
      assert.equal(payload.retries, 0);
      const target = new URL(payload.location || payload.log.remoteLocation);
      assert.equal(target.origin, "http://127.0.0.1:8000");
      assert.equal(target.username, "diagnostic");
      assert(/^[a-f0-9]{64}$/.test(target.password));
      assert(/^\/api\/internal\/diagnostic-logs\/\d+$/.test(target.pathname));
      const auth =
        "Basic " +
        Buffer.from(target.username + ":" + target.password).toString("base64");
      target.username = "";
      target.password = "";
      setTimeout(() => {
        (async () => {
          await device.call(
            f.protocol === "1.6"
              ? "DiagnosticsStatusNotification"
              : "LogStatusNotification",
            {
              status: "Uploading",
              ...(f.protocol === "1.6" ? {} : { requestId: payload.requestId }),
            },
          );
          const response = await fetch(target, {
            method: "PUT",
            redirect: "error",
            headers: {
              Authorization: auth,
              "Content-Type": "application/octet-stream",
            },
            body: bytes,
            signal: AbortSignal.timeout(10000),
          });
          assert.equal(response.status, 201);
          const replay = await fetch(target, {
            method: "PUT",
            redirect: "error",
            headers: {
              Authorization: auth,
              "Content-Type": "application/octet-stream",
            },
            body: bytes,
            signal: AbortSignal.timeout(10000),
          });
          assert.equal(replay.status, 200);
          await device.call(
            f.protocol === "1.6"
              ? "DiagnosticsStatusNotification"
              : "LogStatusNotification",
            {
              status: "Uploaded",
              ...(f.protocol === "1.6" ? {} : { requestId: payload.requestId }),
            },
          );
          uploaded = true;
        })().catch((error) => {
          transferError = error;
        });
      }, 150);
      return f.protocol === "1.6"
        ? { fileName: "private-device-filename.log" }
        : { status: "Accepted" };
    };
    try {
      await device.connect();
      await device.boot();
      await device.status("Faulted");
      await device.status("Available");
      const base = `vendor-ops/companies/${f.company_id}/chargers/${f.charger_id}/diagnostic-logs`;
      const root = base.replace("/diagnostic-logs", "");
      const fault = await poll(
        async () => {
          await client.request(root + "/fault-alerts/inspect", "POST", {});
          const alerts = await client.request(root + "/fault-alerts");
          return alerts.data[0] || null;
        },
        "canonical fault for diagnostic work order",
        12000,
      );
      const repair = await client.request(root + "/repair-orders", "POST", {
        request_key: randomUUID(),
        alert_id: fault.id,
        expected_version: 0,
        state: "open",
        note: "Investigate the synthetic fault with private diagnostic logs",
      });
      const intent = {
        request_key: randomUUID(),
        reason: "Inspect private synthetic device diagnostic log",
      };
      const created = await client.request(base, "POST", intent);
      assert.equal(created.dispatch_state, "transport_submitted");
      const id = created.id;
      let nativeOperation = id;
      assert.equal((await client.request(base, "POST", intent)).id, id);
      await poll(
        async () => {
          if (transferError) throw transferError;
          return uploaded;
        },
        "authenticated diagnostic upload",
        25000,
      );
      const observed = await poll(
        async () => {
          const row = await client.request(
            base + "/" + id + "/observe",
            "POST",
            {},
          );
          return (
            row.accepted_at && row.file_received_at && row.device_status && row
          );
        },
        "correlated diagnostic source evidence",
        25000,
      );
      assert.equal(observed.file_sha256, digest);
      assert.equal(observed.file_size, bytes.length);
      assert.equal(observed.can_download, true);
      assert.equal(
        observed.device_status,
        f.protocol === "1.6" ? "legacy_status_unattributed" : "Uploaded",
      );
      assert.equal(observed.physical_device_verified, false);
      const list = await client.request(base);
      assert(!JSON.stringify(list).includes("diagnostic:"));
      assert(!JSON.stringify(list).includes("payload_ciphertext"));
      assert.equal(
        device.commands.filter((x) =>
          ["GetDiagnostics", "GetLog"].includes(x.action),
        ).length,
        1,
      );
      assert.equal(
        device.commands.some((x) =>
          /Start|Stop|Reset|Firmware|Set/.test(x.action),
        ),
        false,
      );
      const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.EV_CHROMIUM_PATH || undefined,
        args: ["--no-sandbox"],
      });
      try {
        const context = await browser.newContext({
          viewport: { width: 390, height: 844 },
          acceptDownloads: true,
        });
        await context.addCookies(
          [...client.cookies].map(([name, value]) => ({
            name,
            value,
            url: client.base,
          })),
        );
        const page = await context.newPage(),
          errors = [];
        page.on("pageerror", (e) => errors.push(e.message));
        await page.goto(client.base);
        const ops = page.locator("#vendor-operations");
        await ops
          .locator("article")
          .filter({
            has: page.getByRole("heading", { name: f.name, exact: true }),
          })
          .first()
          .getByRole("button", { name: "Open company", exact: true })
          .click();
        await ops
          .getByRole("button", { name: "Open chargers", exact: true })
          .click();
        await ops
          .getByRole("button", { name: "Source diagnostics", exact: true })
          .click();
        await ops
          .getByRole("button", { name: "Diagnostic files", exact: true })
          .click();
        const pane = ops.locator("[data-diagnostic-files]");
        await pane
          .getByText("Canonical diagnostic acceptance observed", {
            exact: true,
          })
          .waitFor();
        assert.equal(await ops.locator("[data-source-diagnostics]").count(), 0);
        const ready = page.waitForEvent("download");
        await pane
          .getByRole("button", {
            name: "Download private diagnostic file",
            exact: true,
          })
          .click();
        const file = await ready;
        assert.equal(
          createHash("sha256")
            .update(fs.readFileSync(await file.path()))
            .digest("hex"),
          digest,
        );
        await file.delete();
        page.once("dialog", (dialog) => dialog.accept());
        await pane
          .getByRole("button", {
            name: "Revoke diagnostic upload and remove file",
            exact: true,
          })
          .click();
        await pane
          .getByText("Diagnostic upload revoked and content removed", {
            exact: true,
          })
          .waitFor();
        await pane
          .locator("[name=reason]")
          .fill("Retrieve a fresh synthetic diagnostic file from the web");
        await pane.locator("[name=repair_order_id]").fill(String(repair.id));
        const posted = page.waitForResponse(
          (response) =>
            new URL(response.url()).pathname.endsWith("/diagnostic-logs") &&
            response.request().method() === "POST",
        );
        await pane.getByRole("button", { name: "Save", exact: true }).click();
        const postedResponse = await posted;
        assert.equal(postedResponse.status(), 201);
        const linked = await postedResponse.json();
        assert.equal(linked.repair_order_id, repair.id);
        nativeOperation = linked.id;
        assert.notEqual(nativeOperation, id);
        await poll(
          async () => {
            if (transferError) throw transferError;
            const row = await client.request(
              base + "/" + nativeOperation + "/observe",
              "POST",
              {},
            );
            return row.can_download && row;
          },
          "explicit web diagnostic request source upload",
          25000,
        );
        await pane
          .getByRole("button", {
            name: "Refresh diagnostic history",
            exact: true,
          })
          .click();
        await pane
          .getByRole("button", {
            name: "Download private diagnostic file",
            exact: true,
          })
          .waitFor();
        assert.equal(
          device.commands.filter((command) =>
            ["GetDiagnostics", "GetLog"].includes(command.action),
          ).length,
          2,
          "Only the two explicit identities may dispatch diagnostic commands",
        );
        await page.locator("#language").click();
        await pane
          .getByRole("heading", { name: "ملفات التشخيص", exact: true })
          .waitFor();
        assert.equal(
          await page.evaluate(
            () => document.documentElement.scrollWidth > innerWidth,
          ),
          false,
        );
        await page.locator("#logout").click();
        await page.locator("#account [name=email]").waitFor();
        assert.equal(await ops.locator("[data-diagnostic-files]").count(), 0);
        assert.deepEqual(errors, []);
      } finally {
        await browser.close();
      }
      if (process.env.EV_DIAGNOSTIC_NATIVE === "1") {
        console.log(
          "Respecting the unchanged operator API budget before native diagnostic checks.",
        );
        await new Promise((resolve) => setTimeout(resolve, 30000));
        await new Promise((resolve) => setTimeout(resolve, 31000));
        const dir = fs.mkdtempSync(
            path.join(os.tmpdir(), "ev-diagnostic-native-"),
          ),
          input = path.join(dir, "fixture.json");
        fs.writeFileSync(
          input,
          JSON.stringify({
            ...f,
            operation: nativeOperation,
            repair_order_id: repair.id,
            sha256: digest,
            bytes: bytes.toString("base64"),
            owner: `canonical-owner-${run}@example.test`,
            customer: `canonical-customer-${run}@example.test`,
            password,
          }),
          { mode: 0o600 },
        );
        try {
          await new Promise((resolve, reject) => {
            const child = spawn(
              "flutter",
              [
                "test",
                "test/live_diagnostic_files_test.dart",
                "--reporter",
                "expanded",
              ],
              {
                cwd: "apps/mobile",
                stdio: "inherit",
                env: { ...process.env, EV_NATIVE_DIAGNOSTIC_FIXTURE: input },
              },
            );
            child.once("error", reject);
            child.once("exit", (code) =>
              code === 0
                ? resolve()
                : reject(Error("Native diagnostic file proof failed")),
            );
          });
        } finally {
          fs.rmSync(dir, { recursive: true, force: true });
        }
      }
      console.log(
        JSON.stringify({
          protocol: f.protocol,
          source_request_reply_upload: true,
          encrypted_private_download: true,
          web_ar_phone_logout: true,
          legacy_status_attribution: f.protocol !== "1.6",
          physical_device_verified: false,
        }),
      );
    } finally {
      await device.close();
    }
  }
})().catch((error) => {
  const message = String(error.message).replace(
    /diagnostic:[a-f0-9]+@/g,
    "diagnostic:[redacted]@",
  );
  console.error(message);
  process.exitCode = 1;
});
