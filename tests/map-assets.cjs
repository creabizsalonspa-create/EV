// Lock the licensed self-hosted map assets to their reviewed upstream bytes.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '../services/business-api/public/vendor/leaflet');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'PROVENANCE.json')));
assert.equal(manifest.version, '1.9.4');
assert.equal(manifest.license, 'BSD-2-Clause');
for (const [name, expected] of Object.entries(manifest.files_sha256)) {
  assert(!name.includes('..') && !path.isAbsolute(name));
  assert.equal(crypto.createHash('sha256').update(fs.readFileSync(path.join(root,name))).digest('hex'), expected, name);
}
assert(Object.hasOwn(manifest.files_sha256,'LICENSE'));
console.log('PASS: self-hosted Leaflet assets and BSD license match the reviewed upstream archive hashes.');
