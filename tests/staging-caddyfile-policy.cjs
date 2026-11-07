'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const file=path.resolve(__dirname,'../infra/staging/Caddyfile');
const read=()=>fs.readFileSync(file,'utf8');

test('staging proxy uses only reviewed hostname and local application',()=>{
  const s=read();
  assert.match(s,/admin off/);
  assert.match(s,/\{\$STAGING_HOST\}\s*\{/);
  assert.match(s,/reverse_proxy application:80/);
  assert.doesNotMatch(s,/reverse_proxy\s+(?:https?:\/\/)?(?:[^\s]*\.example|127\.0\.0\.1:8080)/);
});
test('staging Caddy supplies HTTP acceptance headers and a bounded CSP',()=>{
  const s=read();
  assert.match(s,/X-Content-Type-Options\s+nosniff/);
  assert.match(s,/X-Frame-Options\s+DENY/);
  assert.match(s,/Referrer-Policy\s+strict-origin-when-cross-origin/);
  assert.match(s,/Content-Security-Policy\s+"default-src 'self';/);
  for (const fragment of ["script-src 'self'", "object-src 'none'", "frame-ancestors 'none'", "connect-src 'self'", "form-action 'self'"]) {
    assert.ok(s.includes(fragment),'missing CSP directive '+fragment);
  }
  assert.doesNotMatch(s,/script-src[^;\n]*'unsafe-inline'/);
  assert.match(s,/Permissions-Policy\s+"camera=\(\), microphone=\(\)/);
});
test('no unreviewed catch-all public hostname or admin endpoint',()=>{
  const s=read();
  assert.equal((s.match(/\breverse_proxy\b/g)||[]).length,1);
  assert.doesNotMatch(s,/\badmin\s+(?!off\b)/);
  assert.doesNotMatch(s,/\b(?:tls\s+internal|:443\s*\{|http:\/\/)\b/);
});
