'use strict';

// Offline, dependency-free regression for three private binary-download
// surfaces. This is supplemental source checking: full Laravel HTTP, tenant
// authorization, and encryption-integrity tests remain mandatory.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const controllerRoot = path.join(root, 'services/business-api/app/Http/Controllers');
const read = name => fs.readFileSync(path.join(controllerRoot, name + '.php'), 'utf8');
function downloadMethod(file) {
  const content = read(file);
  const match = /\n    public function download\(/.exec(content);
  assert.ok(match, file + ': explicit guarded download action is required');
  const remainder = content.slice(match.index + 1);
  const next = remainder.slice(1).search(/\n    public function /);
  return next < 0 ? remainder : remainder.slice(0, next + 1);
}

function denyExternalSideEffects(body, scope) {
  assert.doesNotMatch(body, /\bHttp::|\->(?:post|put|patch|delete)\s*\(/,
    scope + ': private downloads may not dispatch external calls');
  assert.doesNotMatch(body, /\b(?:FinanceService|GatewayService|PaymentRecovery)::/,
    scope + ': read-only downloads must not create payment evidence');
}

test('private dispute evidence requires tenant authorization, authenticated ciphertext and attachment sandboxing', () => {
  const body = downloadMethod('PaymentCaseEvidenceController');
  assert.match(body, /\$this->access\(\$r,\s*\$case\)/);
  assert.match(body, /where\(['"]case_id['"],\s*\$case\)/);
  assert.match(body, /where\(['"]id['"],\s*\$evidence\)/);
  assert.match(body, /Crypt::decryptString\(\$cipher\)/);
  assert.match(body, /hash_equals\(\$row->sha256,\s*hash\(['"]sha256['"],\s*\$content\)\)/);
  for (const expected of [/Content-Disposition/, /attachment;/, /Content-Security-Policy/, /sandbox; default-src/, /no-store, private/, /nosniff/]) {
    assert.match(body, expected);
  }
  denyExternalSideEffects(body, 'private dispute evidence');
});

test('diagnostic logs remain vendor-scoped, integrity checked, non-executable attachments', () => {
  const body = downloadMethod('ChargerDiagnosticLogController');
  assert.match(body, /\$this->scope\(\$r,\s*\$company,\s*\$charger/);
  assert.match(body, /\[['"]can_download['"]\]/);
  assert.match(body, /Crypt::decryptString\(\$op->file_ciphertext\)/);
  assert.match(body, /hash_equals\(\$op->file_sha256,\s*hash\(['"]sha256['"],\s*\$bytes\)\)/);
  for (const expected of [/Content-Disposition/, /attachment;/, /Content-Security-Policy/, /sandbox; default-src/, /private, no-store/, /no-referrer/, /nosniff/]) {
    assert.match(body, expected);
  }
  denyExternalSideEffects(body, 'diagnostic logs');
});

test('signed firmware downloads require verified token, expiry, release state, immutable bytes and no referrer', () => {
  const body = downloadMethod('ChargerFirmwareController');
  assert.match(body, /\$this->enabled\(\)/);
  assert.match(body, /hash\(['"]sha256['"],\s*\$token\)/);
  assert.match(body, /whereNull\(['"]reviewed_at['"]\)/);
  assert.match(body, /where\(['"]download_expires_at['"],\s*['"]>['"],\s*now\(\)\)/);
  assert.match(body, /whereIn\(['"]status['"]/);
  assert.match(body, /whereNull\(['"]revoked_at['"]\)/);
  assert.match(body, /\$this->bytes\(\$release\)/);
  for (const expected of [/response\(\)->download\(/, /application\/octet-stream/, /private, no-store/, /no-referrer/, /nosniff/]) {
    assert.match(body, expected);
  }
  denyExternalSideEffects(body, 'signed firmware');
});
