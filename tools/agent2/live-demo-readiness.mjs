#!/usr/bin/env node
/**
 * Agent 2: safe, read-only live Hasone integration smoke checks.
 *
 * GET-only; no login attempts, bookings, payments, OCPP commands or DB writes.
 * Optional EV_TEST_BEARER enables authenticated GET checks for authorized test users.
 * Never print, persist, or commit access tokens.
 *
 * Usage:
 *   node tools/agent2/live-demo-readiness.mjs
 *   EV_TEST_BEARER='<authorized short-lived test token>' node tools/agent2/live-demo-readiness.mjs
 */
const api = process.env.EV_API_ORIGIN ?? 'https://api.hasone.io';
const landing = process.env.EV_LANDING_ORIGIN ?? 'https://hasone.io';
const admin = process.env.EV_ADMIN_ORIGIN ?? 'https://admin.hasone.io';
const token = process.env.EV_TEST_BEARER ?? '';
const results = [];

function checkedOrigin(label, raw) {
  const parsed = new URL(raw);
  if (parsed.protocol !== 'https:' || parsed.username || parsed.password
      || parsed.pathname !== '/' || parsed.search || parsed.hash) {
    throw new Error(label + ' must be a plain HTTPS origin');
  }
  return parsed.origin;
}

async function check(label, url, expectedStatus, options = {}) {
  try {
    const response = await fetch(url, {
      method: 'GET',
      redirect: 'manual',
      signal: AbortSignal.timeout(10000),
      headers: {
        Accept: options.json ? 'application/json' : 'text/html,application/json',
        ...(options.authenticated ? { Authorization: 'Bearer ' + token } : {}),
      },
    });
    let passed = response.status === expectedStatus;
    let reason = passed ? 'status matched' : 'unexpected HTTP status';
    if (passed && options.json) {
      const body = await response.json();
      passed = !!body && typeof body === 'object';
      if (options.health) {
        passed = passed && body.status === 'ok' && body.database === 'connected';
      }
      if (!passed) reason = 'JSON or health payload did not match';
    }
    results.push({ label, httpStatus: response.status, expectedStatus, passed, reason });
  } catch (error) {
    results.push({ label, expectedStatus, passed: false, reason: error?.name ?? 'request error' });
  }
}

let origins;
try {
  origins = {
    api: checkedOrigin('EV_API_ORIGIN', api),
    landing: checkedOrigin('EV_LANDING_ORIGIN', landing),
    admin: checkedOrigin('EV_ADMIN_ORIGIN', admin),
  };
} catch (error) {
  console.error('Unsafe origin:', error.message);
  process.exit(2);
}

await check('Live API health / Mongo connection', origins.api + '/health', 200, { json: true, health: true });
await check('Application settings endpoint', origins.api + '/api/v1/app/settings', 200, { json: true });
await check('Landing website', origins.landing + '/', 200);
await check('Admin dashboard HTTP route', origins.admin + '/dashboard', 200);

// Unauthorized access MUST be blocked. Authenticated checks require a separately approved test account.
const protectedRoutes = [
  ['/api/v1/NearestStations', 'Station discovery'],
  ['/api/v1/VehicleBrands', 'Vehicle brands'],
  ['/api/v1/payment-methods', 'Payment methods GET'],
];
for (const [path, title] of protectedRoutes) {
  await check((token ? 'Authorized ' : 'Unauthorized ') + title,
    origins.api + path, token ? 200 : 401, { authenticated: !!token, json: false });
}

const passing = results.filter(x => x.passed).length;
const report = {
  kind: 'read-only live integration smoke test',
  whenUtc: new Date().toISOString(),
  apiOrigin: origins.api,
  checkCount: results.length,
  passed: passing,
  failed: results.length - passing,
  authenticatedGetChecks: !!token,
  bookingChargingPaymentTested: false,
  physicalChargerTested: false,
  ocpiPartnerTested: false,
  demoAccepted: false,
  results,
};
console.log(JSON.stringify(report, null, 2));
process.exitCode = report.failed ? 1 : 0;
