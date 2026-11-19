'use strict';
// Validate JSON structure, rejecting duplicated decoded object keys before JSON.parse.
// The input-size limit and stable descriptor are enforced by readCoverageFile.
const assert = require('node:assert/strict');
function parseJsonStrict(source) {
  assert.equal(typeof source, 'string', 'JSON source must be text');
  let pos = 0;
  const numberToken = /-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/y;
  const space = () => {while (pos < source.length && /[\t\n\r ]/.test(source[pos])) pos++;};
  const fail = () => {throw Error('Malformed or ambiguous JSON metadata');};
  function quoted() {
    if (source[pos] !== '"') fail();
    const start = pos++;
    while (pos < source.length) {
      const c = source[pos++];
      if (c === '"') return JSON.parse(source.slice(start, pos));
      if (c === '\\') {if (pos >= source.length) fail(); pos++;}
      else if (c.charCodeAt(0) < 32) fail();
    }
    fail();
  }
  function value(depth) {
    if (depth > 128) throw Error('JSON nesting limit exceeded');
    space();
    const c = source[pos];
    if (c === '"') {quoted(); return;}
    if (c === '{') {
      pos++; space();
      if (source[pos] === '}') {pos++; return;}
      const seen = new Set();
      for (;;) {
        space();
        const key = quoted();
        if (seen.has(key)) throw Error('Duplicate JSON object key');
        seen.add(key);
        space();
        if (source[pos++] !== ':') fail();
        value(depth + 1); space();
        const next = source[pos++];
        if (next === '}') return;
        if (next !== ',') fail();
      }
    }
    if (c === '[') {
      pos++; space();
      if (source[pos] === ']') {pos++; return;}
      for (;;) {
        value(depth + 1); space();
        const next = source[pos++];
        if (next === ']') return;
        if (next !== ',') fail();
      }
    }
    for (const literal of ['true','false','null']) {
      if (source.startsWith(literal, pos)) {pos += literal.length; return;}
    }
    numberToken.lastIndex = pos;
    const number = numberToken.exec(source);
    if (number) {pos = numberToken.lastIndex; return;}
    fail();
  }
  value(0); space();
  if (pos !== source.length) fail();
  // The native parser independently enforces JSON grammar, Unicode escapes,
  // control-character rules, and value conversion after key uniqueness checks.
  return JSON.parse(source);
}
module.exports = {parseJsonStrict};
