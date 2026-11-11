#!/usr/bin/env node
"use strict";

// Agent 9: one-shot, isolated security smoke. Never deploys or connects to a
// charger, external API, PSP, or production database.
// Example: node scripts/agent9-isolated-security-qa.cjs
//   --isolated-root=/tmp/ev-agent9-qa --php-bin=/usr/bin/php8.4
const assert = require("node:assert/strict");
const { randomBytes } = require("node:crypto");
const fs = require("node:fs");
const net = require("node:net");
const os = require("node:os");
const path = require("node:path");
const { spawn, spawnSync } = require("node:child_process");

const args = Object.create(null);
for (const raw of process.argv.slice(2)) {
  const match = /^--(isolated-root|php-bin)=(.+)$/.exec(raw);
  if (!match || args[match[1]]) {
    console.error("Usage: node scripts/agent9-isolated-security-qa.cjs --isolated-root=/tmp/CLONE --php-bin=/path/to/php84");
    process.exit(2);
  }
  args[match[1]] = match[2];
}
if (!args["isolated-root"] || !args["php-bin"]) {
  console.error("Explicit disposable checkout and PHP 8.4+ binary are required.");
  process.exit(2);
}
let root;
try { root = fs.realpathSync(args["isolated-root"]); } catch { /* reject */ }
const tmp = fs.realpathSync(os.tmpdir());
if (!root || !root.startsWith(tmp + path.sep) ||
    root === tmp || root === "/" || root.split(path.sep).includes("..")) {
  console.error("Refusing non-temporary or ambiguous source checkout.");
  process.exit(2);
}
const php = args["php-bin"];
if (!path.isAbsolute(php) || !fs.existsSync(php)) {
  console.error("PHP executable must be an explicit existing absolute path.");
  process.exit(2);
}
const business = path.join(root, "services/business-api");
const serveFrom = path.join(business, "public");
const router = path.join(business, "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php");
// The temporary checkout is not necessarily trustworthy: never allow its
// HTTP document root to be a symlink, or to contain symlinked assets that
// would make the test server serve arbitrary files outside this checkout.
function refuseUnsafePublicTree() {
  for (const directory of [path.join(root, "services"), business, serveFrom]) {
    let info;
    try { info = fs.lstatSync(directory); } catch { /* reject below */ }
    if (!info || !info.isDirectory() || info.isSymbolicLink() ||
        !fs.realpathSync(directory).startsWith(root + path.sep)) {
      console.error("Refusing symlinked or external HTTP document root.");
      process.exit(2);
    }
  }
  const pending = [serveFrom];
  let entries = 0;
  while (pending.length) {
    const directory = pending.pop();
    for (const item of fs.readdirSync(directory, { withFileTypes: true })) {
      if (++entries > 5000 || item.isSymbolicLink() ||
          (!item.isDirectory() && !item.isFile())) {
        console.error("Refusing symlink, special file or unbounded HTTP document tree.");
        process.exit(2);
      }
      if (item.isDirectory()) pending.push(path.join(directory, item.name));
    }
  }
}
refuseUnsafePublicTree();
for (const file of [
  router,
  path.join(business, "vendor/autoload.php"),
  path.join(root, "tests/agent9-security-http.cjs"),
  path.join(root, "tests/agent9-payment-return-browser.cjs"),
]) {
  if (!fs.existsSync(file)) {
    console.error("Isolated QA dependency missing:", path.relative(root, file));
    process.exit(2);
  }
  // A temporary checkout may contain symlinks. Never execute a router or
  // test source whose resolved contents escape the temporary source tree.
  const actual = fs.realpathSync(file);
  if (!actual.startsWith(root + path.sep)) {
    console.error("Refusing dependency outside disposable source tree.");
    process.exit(2);
  }
}
try {
  require.resolve("playwright", { paths: [root] });
} catch {
  console.error("Isolated checkout must already contain Playwright and Chromium.");
  process.exit(2);
}
const version = spawnSync(php, ["-r", "echo PHP_VERSION_ID;"], {
  encoding: "utf8", timeout: 4000,
});
if (version.status !== 0 || Number(version.stdout) < 80401) {
  console.error("Isolated Laravel lockfile requires PHP >=8.4.1.");
  process.exit(2);
}

function reserveLoopbackPort() {
  return new Promise((resolve, reject) => {
    const socket = net.createServer();
    socket.once("error", reject);
    socket.listen(0, "127.0.0.1", () => {
      const port = socket.address().port;
      socket.close((error) => error ? reject(error) : resolve(port));
    });
  });
}
function waitExit(processHandle) {
  if (processHandle.exitCode !== null || processHandle.signalCode !== null) {
    return Promise.resolve();
  }
  return new Promise((resolve) => processHandle.once("exit", resolve));
}
async function runNodeScript(script, base) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [path.join(root, "tests", script), base], {
      cwd: root,
      env: {
        PATH: process.env.PATH || "/usr/bin:/bin",
        HOME: os.tmpdir(),
        NODE_ENV: "test",
        PLAYWRIGHT_BROWSERS_PATH: process.env.PLAYWRIGHT_BROWSERS_PATH || path.join(os.homedir(), ".cache/ms-playwright"),
      },
      stdio: "inherit",
    });
    let finished = false;
    const timeout = setTimeout(() => {
      if (finished) return;
      child.kill("SIGTERM");
      reject(new Error(script + " exceeded the bounded isolated QA window"));
    }, 45000);
    child.once("error", (error) => {
      finished = true;
      clearTimeout(timeout);
      reject(error);
    });
    child.once("exit", (code, signal) => {
      finished = true;
      clearTimeout(timeout);
      if (code === 0 && !signal) resolve();
      else reject(new Error(script + " failed with " + String(code) + "/" + String(signal)));
    });
  });
}
async function main() {
  const port = await reserveLoopbackPort();
  const base = "http://127.0.0.1:" + port;
  // Deliberately do not forward the caller's DB, network, proxy, mail or PSP
  // environment into the Laravel runtime. All state lives in this checkout.
  const env = {
    PATH: process.env.PATH || "/usr/bin:/bin",
    HOME: os.tmpdir(),
    APP_ENV: "testing",
    APP_DEBUG: "false",
    APP_KEY: "base64:" + randomBytes(32).toString("base64"),
    APP_URL: base,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: ":memory:",
    CACHE_STORE: "array",
    SESSION_DRIVER: "array",
    QUEUE_CONNECTION: "sync",
    MAIL_MAILER: "array",
    LOG_CHANNEL: "stderr",
  };
  const server = spawn(php, [
    "-d", "log_errors=1", "-d", "error_log=/dev/stderr",
    "-S", "127.0.0.1:" + port, "-t", serveFrom, router,
  ], {
    // Laravel's built-in-server router resolves public/index.php from cwd.
    cwd: serveFrom, env, stdio: ["ignore", "pipe", "pipe"],
  });
  let output = "";
  function append(buffer) {
    output = (output + buffer.toString("utf8")).slice(-1600);
  }
  server.stdout.on("data", append);
  server.stderr.on("data", append);
  const cleanup = () => {
    if (server.exitCode === null && server.signalCode === null) server.kill("SIGTERM");
  };
  process.once("exit", cleanup);
  try {
    let ready = false;
    for (let attempt = 0; attempt < 80; attempt++) {
      if (server.exitCode !== null) break;
      try {
        const response = await fetch(base + "/payments/hosted-return", {
          signal: AbortSignal.timeout(800),
          redirect: "manual",
        });
        if (response.status === 200) { ready = true; break; }
      } catch { /* server is not yet listening */ }
      await new Promise((resolve) => setTimeout(resolve, 125));
    }
    if (!ready) throw new Error("Temporary Laravel server did not become healthy: " + output);
    await runNodeScript("agent9-security-http.cjs", base);
    await runNodeScript("agent9-payment-return-browser.cjs", base);
    console.log("PASS: Agent 9 real loopback HTTP + Chromium QA; all 11 tests passed.");
  } finally {
    cleanup();
    await Promise.race([waitExit(server), new Promise((resolve) => setTimeout(resolve, 2500))]);
    process.removeListener("exit", cleanup);
    if (server.exitCode === null && server.signalCode === null) {
      server.kill("SIGKILL");
      await waitExit(server);
    }
  }
}
main().catch((error) => {
  console.error("Agent 9 isolated QA failed:", error.message);
  process.exitCode = 1;
});
