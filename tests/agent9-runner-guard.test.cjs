// The one-command Agent 9 QA wrapper must refuse unsafe source roots or
// ambiguous PHP inputs *before* starting any local or remote server.
const test = require("node:test");
const assert = require("node:assert/strict");
const path = require("node:path");
const { spawnSync } = require("node:child_process");

test("isolated QA wrapper denies production paths, remote roots and missing binaries", () => {
  const runner = path.join(__dirname, "../scripts/agent9-isolated-security-qa.cjs");
  const invalid = [
    [],
    ["--isolated-root=/"],
    ["--isolated-root=/tmp"],
    ["--isolated-root=/home/hasio/ev-deployment", "--php-bin=/usr/bin/php"],
    ["--isolated-root=/etc", "--php-bin=/usr/bin/php"],
    ["--isolated-root=/tmp/not-an-existing-ev-checkout", "--php-bin=/usr/bin/php"],
    ["--isolated-root=/tmp/ev-qa", "--php-bin=/definitely/missing/php"],
    ["--isolated-root=../relative-path", "--php-bin=php"],
    ["--isolated-root=https://example.invalid/ev", "--php-bin=/usr/bin/php"],
    ["--isolated-root=/tmp/ev-qa", "--php-bin=php"],
    ["--isolated-root=/tmp/ev-qa", "--php-bin=/usr/bin/php", "--unknown=1"],
  ];
  for (const args of invalid) {
    const result = spawnSync(process.execPath, [runner, ...args], {
      encoding: "utf8", timeout: 3000,
    });
    assert.equal(result.status, 2, "unsafe invocation should fail before server start");
    assert.equal(result.signal, null);
    assert.equal(result.error, undefined);
  }
});
