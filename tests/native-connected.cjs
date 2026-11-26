// Respect the unchanged production authentication limit: 10 requests/IP/minute.
// Separate synthetic fixture suites share loopback, so pace their aggregate
// login budget rather than clearing cache, raising limits or replaying logins.
const { spawnSync } = require("node:child_process");
(async () => {
  const groups = [
    [
      "native-inventory",
      "native-topology",
      "native-charger-operations",
      "native-reservation-lifecycle",
    ], // 9 logins
    [
      "native-payment-recovery",
      "native-subscription-dunning",
      "native-recurring-payments",
      "native-vendor-onboarding",
    ], // 9 logins
    ["native-loyalty", "native-financial-operations"], // 9 logins: loyalty4 plus financial5; same production limit
    ["native-referrals"], // three identities and local mock invoice, separate authentication window
    ["native-commercial-parity", "native-case-evidence"], // 8 logins; own fresh unchanged authentication window
    ["native-hosted-checkout"], // 3 independent authenticated identities
    ["native-corporate-hosted"], // 3 payer/driver/vendor identities; separate auth window
    [
      "native-ocpi-operations",
      "native-ocpi-credentials",
      "native-ocpi-incoming",
    ], // 9 identities including private saved-location redaction; no live partner requests
    ["native-charger-certificates", "native-charger-certificate-chains"], // isolated certificate API lifecycle; own login window
    ["native-charging-reviews"], // five private synthetic identities; independent auth window
    ["native-ocpi-test-invoice", "native-ocpi-credit-picker"], // owned TEST invoice and source-credit review: at most 7 logins; unchanged auth window
    ["native-partner-catalog-observations"], // two logins; real source-only private read with unchanged authentication budget
    ["native-ocpi-unpaid-credit"], // no collection; own unchanged authentication window
    ["native-sandbox-roaming", "native-sandbox-reservations"], // own auth window; sandbox only, no limiter bypass
    ["native-privacy"], // three ordinary bearer logins; canonical private rights requests, no erasure
    ["native-operating-calendar"], // six fixture/browser + three native logins; same production limit
    ["native-financial-analytics"], // 3 logins, separate unchanged authentication window
    ["native-fleet-pagination", "native-source-registration"], // 4 identities; retained registration snapshots are read-only
    ["native-account-recovery"], // synthetic password reset; isolated identity and single-use token
    ["native-vendor-session-observations"], // two identities; delegated read-only scope, own unchanged window
    ["native-subscription-preview"], // 2 cookie logins plus a negative Admin-token GET; own window
  ];
  let started;
  for (const group of groups) {
    if (started !== undefined) {
      while (performance.now() - started < 61000) {
        const wait = Math.min(30000, 61000 - (performance.now() - started));
        console.log(
          "Respecting shared loopback authentication window before next connected batch.",
        );
        await new Promise((resolve) => setTimeout(resolve, wait));
      }
    }
    for (const suite of group) {
      const result = spawnSync(process.execPath, ["tests/" + suite + ".cjs"], {
        stdio: "inherit",
      });
      if (result.error) throw result.error;
      if (result.status !== 0) process.exit(result.status || 1);
    }
    // Measure from completion: fixture/bootstrap time before the first login
    // must not shorten the server's actual authentication window.
    started = performance.now();
  }
})().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
