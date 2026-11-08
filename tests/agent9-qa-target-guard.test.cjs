// These operator-driven QA programs must reject anything except explicitly
// passed high-port 127.0.0.1 test servers before making network calls.
const test = require("node:test");
const assert = require("node:assert/strict");
const path = require("node:path");
const { spawnSync } = require("node:child_process");

const invalid = [
  undefined,
  "https://127.0.0.1:18764/",
  "http://localhost:18764/",
  "http://192.168.1.10:18764/",
  "http://example.invalid:18764/",
  "http://0.0.0.0:18764/",
  "http://127.0.0.1:80/",
  "http://127.0.0.1/",
  "http://guest:password@127.0.0.1:18764/",
  "http://127.0.0.1:18764/private",
  "http://127.0.0.1:18764/?secret=1",
  "http://127.0.0.1:18764/#redirect",
  "ftp://127.0.0.1:18764/",
  "not-a-url",
];
for (const file of [
  "agent9-security-http.cjs",
  "agent9-payment-return-browser.cjs",
]) {
  test(`${file} refuses every unsafe or unspecified destination`, () => {
    const full = path.join(__dirname, file);
    for (const argument of invalid) {
      const result = spawnSync(process.execPath,
        argument === undefined ? [full] : [full, argument], {
          encoding: "utf8",
          timeout: 3000,
          maxBuffer: 8192,
        });
      assert.equal(result.status, 2,
        `${file} must refuse ${argument === undefined ? "unspecified input" : argument}`);
      assert.equal(result.signal, null);
      assert.equal(result.error, undefined);
    }
  });
}
