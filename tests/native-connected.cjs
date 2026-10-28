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
    ["native-loyalty", "native-financial-operations"], // 8 logins: loyalty3 plus financial5; same production limit
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
