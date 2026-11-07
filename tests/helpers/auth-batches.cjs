// Test scheduling only. Bound each wait and pace from the last completed
// fixture; never clear rate limits, retry an assertion or replay a mutation.
async function runPacedSuites(
  groups,
  { run, wait, now = () => performance.now(), log = console.log },
) {
  let completed = now();
  for (const group of groups) {
    while (now() - completed < 61000) {
      log(
        "Respecting the unchanged shared authentication window before the next integration batch.",
      );
      await wait(Math.min(30000, 61000 - (now() - completed)));
    }
    for (const suite of group) await run(suite);
    completed = now();
  }
}
module.exports = { runPacedSuites };
