#!/usr/bin/env node
'use strict';

// Operator-invoked, read-only smoke check. All sockets resolve to loopback.
const http = require('node:http');
const https = require('node:https');
const { URL } = require('node:url');

const maxBytes = 512 * 1024;
const timeoutMs = 6500;
const checks = [
  { path: '/up', kind: 'ready' },
  { path: '/', kind: 'web' },
  { path: '/api/stations', kind: 'catalog' },
  { path: '/api/v1/stations', kind: 'catalog' },
  { path: '/api/me', kind: 'private' },
  { path: '/api/v1/me', kind: 'private' },
  { path: '/api/admin/overview', kind: 'private' },
];

function fqdn(hostname) {
  return hostname.length <= 253 && hostname.includes('.') && hostname === hostname.toLowerCase() &&
    !['.example', '.invalid', '.test', '.local', '.localhost'].some(s => hostname.endsWith(s)) &&
    hostname.split('.').every(s => s.length <= 63 && /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(s));
}
function parseArgs(args, env = process.env) {
  if (env.NODE_TLS_REJECT_UNAUTHORIZED === '0') throw Error('TLS certificate verification must remain enabled');
  let urlArg, tlsLoopback = false;
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--url' && !urlArg && args[i + 1]) urlArg = args[++i];
    else if (arg === '--tls-loopback' && !tlsLoopback) tlsLoopback = true;
    else throw Error('Usage: node scripts/staging-health.cjs --url <loopback-URL> [--tls-loopback]');
  }
  if (!urlArg) throw Error('A private staging URL is required');
  let u;
  try { u = new URL(urlArg); } catch { throw Error('Invalid URL'); }
  if (!['http:', 'https:'].includes(u.protocol) || u.username || u.password ||
      u.search || u.hash || u.pathname !== '/' || !u.port) throw Error('Unsafe staging URL');
  const local = ['127.0.0.1', 'localhost', '[::1]'].includes(u.hostname);
  if (tlsLoopback) {
    if (u.protocol !== 'https:' || local || !fqdn(u.hostname)) {
      throw Error('TLS loopback mode requires a valid HTTPS staging hostname and explicit port');
    }
  } else if (!local) {
    throw Error('Only loopback target URLs are permitted without --tls-loopback');
  }
  return { url: u, tlsLoopback };
}

function receive({ url, tlsLoopback }, pathname) {
  return new Promise((resolve, reject) => {
    const client = url.protocol === 'https:' ? https : http;
    const req = client.request({
      hostname: url.hostname,
      port: Number(url.port),
      path: pathname,
      method: 'GET',
      agent: false,
      lookup: (_hostname, _opts, cb) => cb(null, '127.0.0.1', 4),
      ...(tlsLoopback ? { servername: url.hostname } : {}),
      timeout: timeoutMs,
      headers: { Accept: 'application/json, text/html;q=0.5', 'Cache-Control': 'no-store', 'User-Agent': 'ev-private-staging-smoke/1' },
    }, res => {
      const chunks = [];
      let total = 0;
      res.on('data', chunk => {
        total += chunk.length;
        if (total > maxBytes) {
          req.destroy(Error('Response limit exceeded'));
          return;
        }
        chunks.push(chunk);
      });
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks).toString('utf8') }));
      res.on('error', reject);
    });
    req.on('timeout', () => req.destroy(Error('Timeout')));
    req.on('error', reject);
    req.end();
  });
}
function validate(check, response) {
  const { status, headers, body } = response;
  if (check.kind === 'private') {
    if (status !== 401 && status !== 403) throw Error('Anonymous private endpoint must deny access');
    return;
  }
  if (status !== 200) throw Error('Unexpected non-200 response');
  if (check.kind === 'web') {
    if (!String(headers['content-type'] || '').includes('text/html')) throw Error('Web page content type unexpected');
    if (headers['x-content-type-options'] !== 'nosniff') throw Error('Web security header missing');
    if (!headers['content-security-policy']) throw Error('Content security policy missing');
  }
  if (check.kind === 'catalog') {
    if (!String(headers['content-type'] || '').includes('application/json')) throw Error('Catalog is not JSON');
    let data;
    try { data = JSON.parse(body); } catch { throw Error('Catalog is not valid JSON'); }
    if (!data || !Array.isArray(data.data) || !Number.isInteger(data.total) || data.total < 0) {
      throw Error('Public catalog pagination contract changed');
    }
  }
}
async function run(config, requester = receive) {
  const results = [];
  for (const check of checks) {
    try {
      const response = await requester(config, check.path);
      validate(check, response);
      results.push({ path: check.path, status: response.status, ok: true });
    } catch {
      results.push({ path: check.path, ok: false, reason: 'Private staging response failed required HTTP, security, authorization or transport validation' });
    }
  }
  return { ok: results.every(r => r.ok), results };
}
async function main(args) {
  let config;
  try { config = parseArgs(args); }
  catch (e) { process.stderr.write('FAIL: ' + e.message + '\n'); return 2; }
  const result = await run(config);
  for (const row of result.results) process.stdout.write((row.ok ? 'PASS' : 'FAIL') + ': GET ' + row.path + (row.status ? ' [' + row.status + ']' : '') + '\n');
  if (!result.ok) {
    process.stderr.write('Private staging smoke incomplete: investigate failing routes without exposing credentials or response bodies.\n');
    return 1;
  }
  process.stdout.write('PASS: loopback staging HTTP, public catalog and anonymous denial checks. Authenticated workflows, hardware, money and production acceptance remain unverified.\n');
  return 0;
}
if (require.main === module) main(process.argv.slice(2)).then(code => { process.exitCode = code; });
module.exports = { checks, fqdn, parseArgs, validate, receive, run, main };
