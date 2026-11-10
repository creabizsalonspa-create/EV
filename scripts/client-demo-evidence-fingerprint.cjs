'use strict';
// Immutable source binding across privately generated synthetic demonstration reports.
// Digest is not a signature; it exposes no fixture accounts/passwords.
const fs=require('node:fs');
const crypto=require('node:crypto');
const {load}=require('./staging-synthetic-role-smoke.cjs');

function fixtureSha256(manifest) {
  // Canonical guard enforces loopback-only fixture, 0600 private file and example.test identities.
  load(manifest);
  const stat=fs.lstatSync(manifest);
  if(!stat.isFile()||stat.isSymbolicLink()||stat.size<1||stat.size>64*1024 ||
    (process.platform!=='win32' && (stat.mode&0o077)!==0)) {
    throw Error('Private fixture bytes cannot be bound to evidence');
  }
  const bytes=fs.readFileSync(manifest);
  return crypto.createHash('sha256').update(bytes).digest('hex');
}
module.exports={fixtureSha256};
