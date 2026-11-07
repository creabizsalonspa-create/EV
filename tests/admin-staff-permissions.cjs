'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const {test} = require('node:test');

class Element {
  constructor(tag) {
    this.tag = tag;
    this.children = [];
    this._text = '';
    this.value = '';
    this.attributes = {};
    this.disabled = false;
  }
  get textContent() {
    return this._text + this.children.map(c => c.textContent).join('');
  }
  set textContent(value) { this._text = String(value); this.children = []; }
  append(...children) { this.children.push(...children); }
  replaceChildren(...children) { this._text = ''; this.children = children; }
  setAttribute(key, value) { this.attributes[key] = value; }
}
function fixture() {
  const root = new Element('div');
  const window = {};
  const document = {
    getElementById(key) {
      assert.equal(key, 'admin-staff-permissions');
      return root;
    },
    createElement(tag) { return new Element(tag); },
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,
    '../services/business-api/public/admin-staff-permissions.js'), 'utf8'), {
    window, document, AbortController, URLSearchParams,
  });
  return {root, setActor:window.EvAdminStaffPermissions.setActor};
}
function nodes(root, tag) {
  const found = [];
  function traverse(el) {
    if (el.tag === tag) found.push(el);
    el.children.forEach(traverse);
  }
  traverse(root);
  return found;
}
const tick = () => new Promise(resolve => setImmediate(resolve));
const grant = {
  id:17, company_id:2, user_id:3, status:'active', invitation_version:4,
  permissions:['company.read', 'inventory.write'],
  company:{id:2,name:'<script>Company</script>',country:'AE'},
  user:{id:3,name:'Staff Member',email:'private-staff@example.test',account_status:'active'},
};
const page = (data = [grant]) => ({current_page:1,last_page:2,total:30,data});

test('guest/vendor never fetch permissions; logout discards records', async () => {
  const ui=fixture(),calls=[];
  ui.setActor(null, async p=>{calls.push(p); return page();});
  ui.setActor({role:'vendor'},async p=>{calls.push(p); return page();});
  await tick();
  assert.equal(calls.length,0);
  ui.setActor({role:'admin'},async p=>{calls.push(p); return page();});
  await tick();
  assert.equal(calls.length,1);
  assert.match(ui.root.textContent,/private-staff@example.test/);
  assert.match(ui.root.textContent,/<script>Company<\/script>/);
  assert.equal(nodes(ui.root,'script').length,0);
  ui.setActor(null, async()=>{throw Error('must not call');});
  assert.equal(ui.root.children.length,0);
  assert.equal(ui.root.textContent,'');
});

test('revocation requires confirmation and sends exact expected version and private reason', async () => {
  const ui=fixture(),calls=[];
  let currentStatus='active';
  ui.setActor({role:'admin'}, async (p,method='GET',body) => {
    calls.push({p,method,body});
    if(p.endsWith('/revoke')) {
      assert.equal(method,'POST');
      assert.equal(body.expected_version,4);
      assert.equal(body.reason,'Documented security investigation for company staff');
      currentStatus='revoked';
      return {id:17,status:'revoked',invitation_version:5};
    }
    return page([{...grant,status:currentStatus}]);
  });
  await tick();
  const revoke=nodes(ui.root,'button').find(n=>n.textContent.includes('Emergency revoke'));
  assert.ok(revoke);
  revoke.onclick();
  assert.equal(calls.filter(c=>c.p.endsWith('/revoke')).length,0);
  const form=nodes(ui.root,'form').find(f=>nodes(f,'textarea').length);
  assert.ok(form);
  const reason=nodes(form,'textarea')[0];
  reason.value='short';
  await form.onsubmit({preventDefault(){}});
  assert.equal(calls.filter(c=>c.p.endsWith('/revoke')).length,0);
  reason.value='Documented security investigation for company staff';
  await form.onsubmit({preventDefault(){}});
  await tick();
  assert.equal(calls.filter(c=>c.p.endsWith('/revoke')).length,1);
  assert.equal(calls.find(c=>c.p.endsWith('/revoke')).p,'admin/permission-grants/17/revoke');
  assert.match(ui.root.textContent,/revoked/);
  assert.equal(nodes(ui.root,'button').filter(n=>n.textContent.includes('Emergency revoke')).length,0);
});

test('cancel reverts confirmation without revoking access', async () => {
  const ui=fixture(),calls=[];
  ui.setActor({role:'admin'}, async p=>{calls.push(p); return page();});
  await tick();
  nodes(ui.root,'button').find(n=>n.textContent.includes('Emergency revoke')).onclick();
  const cancel=nodes(ui.root,'button').find(n=>n.textContent.includes('Cancel'));
  assert.ok(cancel);
  cancel.onclick();
  assert.equal(nodes(ui.root,'textarea').length,0);
  assert.ok(nodes(ui.root,'button').some(n=>n.textContent.includes('Emergency revoke')));
  assert.equal(calls.length,1);
});

test('private revocation history is text-only and stale responses cannot survive logout', async () => {
  const ui=fixture();
  let release;
  ui.setActor({role:'admin'},p=>{
    if (p.endsWith('/revocations')) return new Promise(resolve=>{release=resolve;});
    return Promise.resolve(page());
  });
  await tick();
  const button=nodes(ui.root,'button').find(n=>n.textContent.includes('Private revocation history'));
  const pending=button.onclick();
  assert.equal(typeof release,'function');
  ui.setActor({role:'vendor'}, async()=>{throw Error('not permitted');});
  release({data:[{id:1,reason:'<img src=x onerror=alert(1)>',created_at:'2026-11-07',
    replaced_version:4}]});
  await pending;
  await tick();
  assert.equal(ui.root.textContent,'');
  assert.equal(nodes(ui.root,'img').length,0);
});

test('filter scoping and pagination preserve bounded review arguments', async () => {
  const ui=fixture(),paths=[];
  ui.setActor({role:'admin'},async p=>{paths.push(p); return page();});
  await tick();
  const filters=nodes(ui.root,'form')[0];
  nodes(filters,'select')[0].value='active';
  nodes(filters,'input')[0].value='2';
  filters.onsubmit({preventDefault(){}});
  await tick();
  assert.match(paths[1],/status=active/);
  assert.match(paths[1],/company_id=2/);
  const next=nodes(ui.root,'button').find(n=>n.textContent.includes('Next'));
  next.onclick();
  await tick();
  assert.match(paths[2],/page=2/);
});

test('stale revoke reply cannot repopulate private controls after identity changes', async () => {
  const ui=fixture();
  let release;
  ui.setActor({role:'admin'},p=>{
    if (p.endsWith('/revoke')) return new Promise(resolve=>{release=resolve;});
    return Promise.resolve(page());
  });
  await tick();
  nodes(ui.root,'button').find(n=>n.textContent.includes('Emergency revoke')).onclick();
  const form=nodes(ui.root,'form').find(x=>nodes(x,'textarea').length);
  nodes(form,'textarea')[0].value='Verified emergency security review of access';
  const pending=form.onsubmit({preventDefault(){}});
  assert.equal(typeof release,'function');
  ui.setActor(null, async()=>{throw Error('must not fetch');});
  release({id:17,status:'revoked'});
  await pending;
  await tick();
  assert.equal(ui.root.children.length,0);
  assert.equal(ui.root.textContent,'');
});
