'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');

class Element {
  constructor(tag) {
    this.tag = tag;
    this.children = [];
    this.value = '';
    this.attributes = {};
    this._text = '';
    this.disabled = false;
  }
  get textContent() {
    return this._text + this.children.map(child => child.textContent).join('');
  }
  set textContent(value) {
    this.children = [];
    this._text = String(value);
  }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this._text = ''; this.children = nodes; }
  setAttribute(name, value) { this.attributes[name] = value; }
}
function fixture() {
  const root = new Element('div');
  const window = {};
  const document = {
    getElementById(id) {
      assert.equal(id, 'admin-accounts');
      return root;
    },
    createElement(tag) { return new Element(tag); },
  };
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../services/business-api/public/admin-accounts.js'), 'utf8'), {
    window, document, AbortController, URLSearchParams,
  });
  return { root, setActor: window.EvAdminAccounts.setActor };
}
function nodes(parent, tag) {
  const all = [];
  function scan(node) {
    if (node.tag === tag) all.push(node);
    node.children.forEach(scan);
  }
  scan(parent);
  return all;
}
const data = {
  current_page: 1, last_page: 2, total: 30,
  data: [{ id: 42, name: '<script>alert(1)</script>', email: 'private@example.test', role: 'vendor', email_verified: false,
    companies: [{ legal_name: '<b>Not markup</b>', country: 'AE', status: 'submitted' }] }],
};
const tick = () => new Promise(resolve => setImmediate(resolve));

test('guest context never fetches and clears private records', async () => {
  const ui = fixture();
  const paths = [];
  ui.setActor({role: 'customer'}, async path => { paths.push(path); return data; });
  await tick();
  assert.equal(paths.length, 0);
  assert.equal(ui.root.children.length, 0);
  ui.setActor({role: 'admin'}, async path => { paths.push(path); return data; });
  await tick();
  assert.equal(paths.length, 1);
  assert.match(ui.root.textContent, /private@example.test/);
  ui.setActor(null, async () => { throw Error('fetch must be disabled'); });
  assert.equal(ui.root.textContent, '');
  assert.equal(ui.root.children.length, 0);
});

test('stale pending Admin reply cannot render after logout', async () => {
  const ui = fixture();
  let release;
  ui.setActor({role: 'admin'}, () => new Promise(resolve => { release = resolve; }));
  ui.setActor({role: 'customer'}, () => { throw Error('not permitted'); });
  release(data);
  await tick();
  assert.equal(ui.root.textContent, '');
  assert.equal(ui.root.children.length, 0);
});

test('search and pagination are scoped to current admin identity and text-only', async () => {
  const ui = fixture();
  const paths = [];
  ui.setActor({role: 'admin'}, async path => { paths.push(path); return data; });
  await tick();
  assert.match(ui.root.textContent, /<script>alert\(1\)<\/script>/);
  assert.match(ui.root.textContent, /<b>Not markup<\/b>/);
  assert.equal(nodes(ui.root, 'script').length, 0);
  assert.equal(nodes(ui.root, 'b').length, 0);
  assert.equal(nodes(ui.root, 'tr').length, 2);
  const search = nodes(ui.root, 'input')[0];
  search.value = 'only my search';
  const selects = nodes(ui.root, 'select');
  selects[0].value = 'vendor';
  selects[1].value = 'no';
  const form = nodes(ui.root, 'form')[0];
  form.onsubmit({preventDefault() {}});
  await tick();
  assert.match(paths[1], /q=only\+my\+search/);
  assert.match(paths[1], /role=vendor/);
  assert.match(paths[1], /verified=no/);
  const next = nodes(ui.root, 'button').find(button => button.textContent.includes('Next'));
  assert.equal(next.disabled, false);
  next.onclick();
  await tick();
  assert.match(paths[2], /page=2/);
  assert.equal(paths.length, 3);
});
