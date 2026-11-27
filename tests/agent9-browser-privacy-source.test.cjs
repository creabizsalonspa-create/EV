'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// Prevent a generated integration patch from turning assertions into a
// comment via literal backslash-n sequences. A syntactically valid JS file
// alone does not detect this accidental loss of security verification.
function validatePrivacyAssertions(source) {
  assert.doesNotMatch(source, /\/\/[^\r\n]*\\n[^\r\n]*(?:for\s*\(|assert\.equal|await\s+)/,
    'Escaped newlines must never comment out browser privacy checks');
  assert.match(source, /^\s*for \(const role of \["admin", "vendor"\]\) \{$/m,
    'Anonymous-role checks must be executable on a real JS source line');
  assert.match(source, /^\s*const restricted = page\.locator\(`\[data-access="\$\{role\}"\]`\);$/m,
    'Check every private control for the selected account role');
  assert.match(source, /^\s*assert\.ok\(count > 0, `Expected \$\{role\}-gated UI controls`\);$/m,
    'The privacy test must reject missing controls, not trivially pass');
  assert.match(source, /^\s*assert\.equal\(await restricted\.nth\(i\)\.isVisible\(\), false\);$/m,
    'The browser must verify all matching controls remain invisible');
}

test('anonymous Admin/Vendor browser privacy assertions cannot be commented out by escaped newlines', () => {
  const source = fs.readFileSync(path.join(__dirname, 'frontend.cjs'), 'utf8');
  validatePrivacyAssertions(source);
});

test('privacy source guard rejects the previously silently commented-out integration patch', () => {
  const good = [
    '    for (const role of ["admin", "vendor"]) {',
    '      const restricted = page.locator(`[data-access="${role}"]`);',
    '      const count = await restricted.count();',
    '      assert.ok(count > 0, `Expected ${role}-gated UI controls`);',
    '      for (let i = 0; i < count; i++) {',
    '        assert.equal(await restricted.nth(i).isVisible(), false);',
    '      }',
    '    }',
  ].join('\n');
  assert.doesNotThrow(() => validatePrivacyAssertions(good));
  const broken = '    // Privileged controls include Admin-only\\n' +
    good.split('\n').join('\\n');
  assert.throws(() => validatePrivacyAssertions(broken), assert.AssertionError);
});
