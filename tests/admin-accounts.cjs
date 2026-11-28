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
  selects[2].value = 'suspended';
  const form = nodes(ui.root, 'form')[0];
  form.onsubmit({preventDefault() {}});
  await tick();
  assert.match(paths[1], /q=only\+my\+search/);
  assert.match(paths[1], /role=vendor/);
  assert.match(paths[1], /verified=no/);
  assert.match(paths[1], /account_status=suspended/);
  const next = nodes(ui.root, 'button').find(button => button.textContent.includes('Next'));
  assert.equal(next.disabled, false);
  next.onclick();
  await tick();
  assert.match(paths[2], /page=2/);
  assert.equal(paths.length, 3);
});


test('Admin moderation requires confirmation, sends expected status and refreshes result', async () => {
  const ui = fixture();
  let state = 'active';
  const calls = [];
  const api = async (path, method = 'GET', payload) => {
    calls.push({path, method, payload});
    if (path.endsWith('/moderate')) {
      assert.equal(method, 'PATCH');
      assert.equal(payload.expected_status, 'active');
      assert.equal(payload.account_status, 'suspended');
      assert.equal(payload.reason, 'Verified security incident on vendor account');
      state = payload.account_status;
      return {id:42, account_status:state};
    }
    return {current_page:1, last_page:1, total:1,
      data:[{...data.data[0], account_status:state}]};
  };
  ui.setActor({role:'admin'}, api);
  await tick();
  const suspend = nodes(ui.root, 'button').find(button => button.textContent.includes('Suspend'));
  assert.ok(suspend, 'Suspend action should be available only for non-Admin accounts');
  suspend.onclick();
  const form = nodes(ui.root, 'form').find(x => nodes(x,'textarea').length);
  assert.ok(form, 'Suspension must not occur from a single click');
  assert.equal(calls.filter(x=>x.path.endsWith('/moderate')).length, 0);
  nodes(form,'textarea')[0].value = 'Verified security incident on vendor account';
  await form.onsubmit({preventDefault(){}});
  await tick();
  assert.equal(calls.filter(x=>x.path.endsWith('/moderate')).length, 1);
  assert.match(ui.root.textContent, /Suspended/);
  assert.ok(nodes(ui.root, 'button').some(button => button.textContent.includes('Restore')));
});

test('Admin moderation history stays text-only and is cleared on signout', async () => {
  const ui = fixture();
  const marker = '<img src=x onerror=alert(1)>';
  ui.setActor({role:'admin'}, async path => {
    if (path.endsWith('/moderations')) {
      return {current_page:1,last_page:1,data:[{id:2, from_status:'active', to_status:'suspended',
        reason:marker, created_at:'2026-11-07T12:00:00Z'}]};
    }
    return {current_page:1, last_page:1, total:1,
      data:[{...data.data[0], account_status:'suspended'}]};
  });
  await tick();
  const history = nodes(ui.root, 'button').find(button => button.textContent.includes('Review history'));
  assert.ok(history);
  await history.onclick();
  assert.match(ui.root.textContent, /<img src=x onerror=alert\(1\)>/);
  assert.equal(nodes(ui.root, 'img').length, 0);
  ui.setActor(null, async () => { throw Error('must not fetch'); });
  assert.equal(ui.root.children.length, 0);
  assert.equal(ui.root.textContent, '');
});

test('Pending moderation response cannot restore private UI after Admin logout', async () => {
  const ui = fixture();
  let release;
  ui.setActor({role:'admin'}, async path => {
    if (path.endsWith('/moderate')) {
      return new Promise(resolve => { release = resolve; });
    }
    return {current_page:1, last_page:1, total:1,
      data:[{...data.data[0], account_status:'active'}]};
  });
  await tick();
  nodes(ui.root, 'button').find(button => button.textContent.includes('Suspend')).onclick();
  const form = nodes(ui.root, 'form').find(x => nodes(x,'textarea').length);
  nodes(form,'textarea')[0].value = 'Documented verified security review';
  const pending = form.onsubmit({preventDefault(){}});
  assert.equal(typeof release, 'function');
  ui.setActor(null, async () => { throw Error('must not fetch'); });
  release({id:42, account_status:'suspended'});
  await pending;
  await tick();
  assert.equal(ui.root.textContent, '');
  assert.equal(ui.root.children.length, 0);
});


test('business directory combines owner lookup with one-company country and KYC state filters', async () => {
  const ui = fixture();
  const requests = [];
  ui.setActor({role:'admin'}, async path => { requests.push(path); return data; });
  await tick();
  const inputs = nodes(ui.root, 'input');
  assert.equal(inputs.length, 3);
  inputs[1].value = 'Retail East';
  inputs[2].value = 'ca';
  const selects = nodes(ui.root, 'select');
  selects[3].value = 'changes_requested';
  const form = nodes(ui.root, 'form')[0];
  form.onsubmit({preventDefault() {}});
  await tick();
  assert.match(requests[1], /company_q=Retail\+East/);
  assert.match(requests[1], /company_country=CA/);
  assert.match(requests[1], /company_status=changes_requested/);
  assert.equal(nodes(ui.root, 'script').length, 0);
  ui.setActor({role:'vendor'}, () => { throw Error('vendor must not query Admin accounts'); });
  assert.equal(ui.root.children.length, 0);
});

test('history pagination is bound to account, page and current directory controls',async()=>{
  const ui=fixture(),calls=[];
  let release;
  ui.setActor({role:'admin'},async path=>{
    calls.push(path);
    if(path.endsWith('/moderations'))return {current_page:1,last_page:2,data:[{id:1,from_status:'active',to_status:'suspended',reason:'first page',created_at:'2026-11-07'}]};
    if(path.endsWith('/moderations?page=2'))return new Promise(resolve=>{release=resolve;});
    return {current_page:1,last_page:1,total:1,data:[{...data.data[0],account_status:'active'}]};
  });
  await tick();
  await nodes(ui.root,'button').find(b=>b.textContent.includes('Review history')).onclick();
  const oldAction=nodes(ui.root,'button').find(b=>b.textContent.includes('Suspend'));
  const oldNext=nodes(ui.root,'button').find(b=>b.textContent.includes('Next history'));
  oldNext.onclick();
  nodes(ui.root,'form')[0].onsubmit({preventDefault(){}});
  await tick();
  oldAction.onclick();
  assert.equal(nodes(ui.root,'textarea').length,0);
  release({current_page:2,last_page:2,data:[{id:2,from_status:'suspended',to_status:'active',reason:'STALE PRIVATE REASON',created_at:'2026-11-08'}]});
  await tick();
  assert(!ui.root.textContent.includes('STALE PRIVATE REASON'));
  oldNext.onclick();
  assert.equal(calls.filter(p=>p.endsWith('/moderations?page=2')).length,1);
});
test('wrong history page fails closed without rendering private reason',async()=>{
  const ui=fixture();
  ui.setActor({role:'admin'},async path=>path.endsWith('/moderations')?
    {current_page:2,last_page:2,data:[{from_status:'active',to_status:'suspended',reason:'WRONG PAGE PRIVATE',created_at:'2026-11-07'}]}:
    {current_page:1,last_page:1,total:1,data:[{...data.data[0],account_status:'active'}]});
  await tick();
  await nodes(ui.root,'button').find(b=>b.textContent.includes('Review history')).onclick();
  assert(!ui.root.textContent.includes('WRONG PAGE PRIVATE'));
  assert(ui.root.textContent.includes('Private moderation history unavailable'));
});
