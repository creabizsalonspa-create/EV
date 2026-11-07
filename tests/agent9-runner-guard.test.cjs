// The one-command Agent 9 QA wrapper must refuse unsafe source roots or
// ambiguous PHP inputs *before* starting any local or remote server.
const test = require("node:test");
const assert = require("node:assert/strict");
const path = require("node:path");
const fs = require("node:fs");
const os = require("node:os");
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

test("isolated QA wrapper rejects symlinked document roots and nested asset escapes", () => {
  const runner = path.join(__dirname, "../scripts/agent9-isolated-security-qa.cjs");
  const external = fs.mkdtempSync(path.join(os.tmpdir(), "agent9-external-public-"));
  try {
    for (const kind of ["root-symlink", "nested-symlink"]) {
      const temp = fs.mkdtempSync(path.join(os.tmpdir(), "agent9-malicious-qa-"));
      try {
        const business = path.join(temp, "services/business-api");
        fs.mkdirSync(business, { recursive: true });
        const publicDir = path.join(business, "public");
        if (kind === "root-symlink") fs.symlinkSync(external, publicDir, "dir");
        else {
          fs.mkdirSync(publicDir);
          fs.symlinkSync(external, path.join(publicDir, "private-file-link"), "dir");
        }
        // Use an existing executable; the boundary must reject the source
        // before runtime dependency discovery or attempting to start PHP.
        const result = spawnSync(process.execPath, [
          runner, "--isolated-root=" + temp, "--php-bin=" + process.execPath,
        ], { encoding: "utf8", timeout: 3000 });
        assert.equal(result.status, 2, kind);
        assert.match(result.stderr, /Refusing symlinked or external HTTP document root|Refusing symlink, special file or unbounded HTTP document tree/);
        assert.equal(result.signal, null);
      } finally {
        fs.rmSync(temp, { recursive: true, force: true });
      }
    }
  } finally {
    fs.rmSync(external, { recursive: true, force: true });
  }
});
