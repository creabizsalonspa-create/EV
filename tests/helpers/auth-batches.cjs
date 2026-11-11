// Test scheduling only. Bound each wait and pace from the last completed
// fixture; never clear rate limits, retry an assertion or replay a mutation.
async function runPacedSuites(
  groups,
  { run, wait, now = () => performance.now(), log = console.log },
) {
  let completed = now();
  for (const group of groups) {
    await waitForFreshWindow({ wait, now, log, completed });
    for (const suite of group) await run(suite);
    completed = now();
  }
}
// Authentication and protected-read budgets both use unchanged one-minute
// windows. A phase boundary is scheduling, never a cache reset or write replay.
async function waitForFreshWindow({
  wait,
  now = () => performance.now(),
  log = console.log,
  completed = now(),
}) {
  for (;;) {
    const remaining = 61000 - (now() - completed);
    if (remaining <= 0) break;
    log(
      "Respecting the unchanged shared rate-limit window before the next integration phase.",
    );
    await wait(Math.min(30000, Math.ceil(remaining)));
  }
}
module.exports = { runPacedSuites, waitForFreshWindow };
