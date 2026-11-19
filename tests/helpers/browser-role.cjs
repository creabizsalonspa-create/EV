'use strict';
const {execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');

// Shell role changes are privileged test mutations. Never print raw SQL or email.
async function provisionTestRole(email, role, {pages, runner = process.env.EV_TEST_PHP_RUNNER || './scripts/php', runCommand = execFileSync}) {
  // CLI role changes share the local SQLite file with HTTP read projections.
  // Wait for every browser request to finish before this privileged mutation;
  // never replay an unknown CLI outcome or weaken the application limiter.
  assert(Array.isArray(pages) && pages.length > 0, 'Explicit browser pages are required');
  await Promise.all(pages.map(page => page.waitForLoadState('networkidle')));
  try {
    await runCommand(runner, ["artisan", "platform:role", email, role], {
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    });
  } catch (error) {
    const output = String(error.stdout || "") + String(error.stderr || "");
    const sqlstate = output.match(/SQLSTATE\[([A-Z0-9]{5})\]/);
    const diagnosis = /database is locked|database table is locked/i.test(output)
      ? "SQLITE_BUSY_OR_LOCKED"
      : /readonly database/i.test(output) ? "SQLITE_READONLY"
      : /unable to open database file/i.test(output) ? "SQLITE_CANTOPEN"
      : /database or disk is full/i.test(output) ? "SQLITE_FULL"
      : "DATABASE_CAUSE_UNCLASSIFIED";
    throw new Error(
      "Synthetic role provisioning failed: " +
        (sqlstate ? "SQLSTATE " + sqlstate[1] : "SQLSTATE unavailable") + " · " + diagnosis +
        "; inspect private CI evidence for cause.",
      { cause: { exitCode: error.status } },
    );
  }
}
module.exports={provisionTestRole};
