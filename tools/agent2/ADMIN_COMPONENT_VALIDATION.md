# Isolated Admin charging component validation

This optional runner builds the actual `OcppBookingWorkflow.tsx` and its evidence
helper in a disposable Next.js shell, then executes the existing mocked-HTTP
Chrome suite. It requires no purchased source or real credentials. The synthetic
shell supplies only routing, a booking list, theme and HTTP adapter; these are not
production implementations or evidence of actual Express/Mongo integration.

Prepare a dedicated dependency directory (Node 22 or newer):

```sh
mkdir -p /path/to/agent2-component-deps
cp tools/agent2/admin-component-fixture.package.json /path/to/agent2-component-deps/package.json
cp tools/agent2/admin-component-fixture.package-lock.json /path/to/agent2-component-deps/package-lock.json
npm ci --prefix /path/to/agent2-component-deps --ignore-scripts --no-audit --no-fund
node /path/to/agent2-component-deps/node_modules/@puppeteer/browsers/lib/cjs/main-cli.js install chrome-headless-shell@142.0.7444.175 --path /path/to/browser-cache
node tools/agent2/run_admin_component_validation.mjs /path/to/agent2-component-deps /path/to/browser-cache/chrome-headless-shell/linux-142.0.7444.175/chrome-headless-shell-linux64/chrome-headless-shell /private/path/component-report.json
```

The example browser path is Linux; use the install command's actual executable
path on other supported systems. Keep inherited proxy/CA settings and respect the
execution environment's network and process permissions. The runner itself never
installs packages, changes those restrictions, deploys, or starts a public listener.

The runner verifies the dependency lock and pinned versions, snapshots component,
helper and browser-test bytes, and builds with those snapshots. A unique readiness
marker identifies its loopback-only server. It runs TypeScript checking and all
21 browser cases, rejects source changes during verification, records Git blob IDs
and log SHA256, and removes its disposable app and server. Logs/report contain only
synthetic fixtures. Native dependencies and generated build output are not committed.

A passing `COMPONENT_FIXTURE_ONLY_PASS` verifies this component in the named test
shell. It does **not** replace the purchased Admin app's build, actual API browser
journey, merged-source release checks, hardware/PSP or production acceptance.
The original full-source runner remains `run_all_isolated_validation.sh`.
