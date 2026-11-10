'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { parseArgs, run, fqdn } = require('../scripts/staging-health.cjs');

function responseFor(path) {
  if (path === '/up') return { status: 200, body: 'up' };
  if (path === '/') return { status: 200, headers: { 'content-type': 'text/html; charset=utf-8', 'x-content-type-options': 'nosniff', 'content-security-policy': "default-src 'self'" }, body: '<html>EV</html>' };
  if (path === '/api/stations' || path === '/api/v1/stations') return { status: 200, headers: { 'content-type': 'application/json' }, body: JSON.stringify({ data: [], total: 0 }) };
  return { status: 401, headers: { 'content-type': 'application/json' }, body: JSON.stringify({message:'Unauthenticated.'}) };
}
async function liveServer(callback, overrides = {}) {
  const observed = [];
  const server = http.createServer((req, res) => {
    observed.push({ path: req.url, method: req.method, headers: req.headers });
    const value = overrides[req.url] || responseFor(req.url);
    res.writeHead(value.status, value.headers || {});
    res.end(value.body || '');
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    const url = 'http://127.0.0.1:' + server.address().port + '/';
    return await callback(parseArgs(['--url', url]), observed);
  } finally { await new Promise(resolve => server.close(resolve)); }
}

test('real loopback HTTP smoke passes with public catalog and private access denied', async () => {
  await liveServer(async (config, observed) => {
    const report = await run(config);
    assert.equal(report.ok, true);
    assert.equal(report.results.length, 7);
    assert.equal(observed.length, 7);
    assert.ok(observed.every(x => x.method === 'GET' && !x.headers.authorization && !x.headers.cookie));
  });
});
test('anonymous customer identity leak fails', async () => {
  await liveServer(async config => {
    assert.equal((await run(config)).ok, false);
  }, { '/api/me': { status: 200, headers: {'content-type': 'application/json'}, body: '{"id":1}' } });
});
test('anonymous admin role leak fails', async () => {
  await liveServer(async config => {
    const report = await run(config);
    assert.equal(report.ok, false);
    assert.ok(report.results.some(x => x.path === '/api/admin/overview' && !x.ok));
  }, { '/api/admin/overview': { status: 200, headers: {'content-type': 'application/json'}, body: '{}' } });
});
test('redirects are never followed and are reported as failed boundaries', async () => {
  await liveServer(async config => {
    assert.equal((await run(config)).ok, false);
  }, { '/api/v1/me': { status: 302, headers: {location:'https://external.invalid/'}, body: '' } });
});
test('catalog HTML error page fails JSON/schema validation', async () => {
  await liveServer(async config => {
    assert.equal((await run(config)).ok, false);
  }, { '/api/stations': {status: 200, headers: {'content-type':'text/html'}, body:'<html>error</html>'} });
});
test('catalog pagination schema regression fails', async () => {
  await liveServer(async config => {
    assert.equal((await run(config)).ok, false);
  }, { '/api/v1/stations': {status: 200, headers: {'content-type':'application/json'}, body:'{"results":[]}' } });
});
test('web security headers failure detected', async () => {
  await liveServer(async config => {
    assert.equal((await run(config)).ok, false);
  }, { '/': {status:200,headers:{'content-type':'text/html'},body:'<html></html>'} });
});
test('oversized response fails without exposing content', async () => {
  await liveServer(async config => {
    const report = await run(config);
    assert.equal(report.ok, false);
    assert.ok(report.results.some(x => x.path === '/' && !x.ok));
    assert.equal(JSON.stringify(report).includes('TOP_SECRET'), false);
  }, { '/': {status:200,headers: {'content-type':'text/html','x-content-type-options':'nosniff','content-security-policy':'test'},body:'TOP_SECRET'+ 'x'.repeat(550000)} });
});
test('explicit port, root URL, no credentials/query or remote targets', () => {
  const invalid = [ 'https://foo.example.net:8443/', 'http://0.0.0.0:8080/', 'http://127.0.0.1/', 'http://user:pass@127.0.0.1:8080/', 'http://127.0.0.1:8080/a', 'http://127.0.0.1:8080/?key=secret', 'ftp://127.0.0.1:8080/' ];
  for (const u of invalid) assert.throws(() => parseArgs(['--url',u]));
});
test('TLS tunnel requires valid hostname and certificate verification', () => {
  const good = parseArgs(['--url','https://staging.ev-example.org:8443/','--tls-loopback']);
  assert.equal(good.tlsLoopback,true);
  assert.equal(fqdn('staging.ev-example.org'),true);
  for (const bad of ['http://staging.ev-example.org:8443/','https://staging.example:8443/','https://127.0.0.1:8443/']) assert.throws(() => parseArgs(['--url',bad,'--tls-loopback']));
  assert.throws(() => parseArgs(['--url','https://staging.ev-example.org:8443/','--tls-loopback'],{NODE_TLS_REJECT_UNAUTHORIZED:'0'}));
});
test('unrecognized flags cannot change safety mode', () => {
  assert.throws(() => parseArgs(['--url','http://127.0.0.1:8080/','--insecure']));
});
