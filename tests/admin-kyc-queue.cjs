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
    this.value = '';
    this.attributes = {};
    this._text = '';
    this.disabled = false;
  }
  get textContent() {
    return this._text + this.children.map(x => x.textContent).join('');
  }
  set textContent(value) {
    this._text = String(value);
    this.children = [];
  }
  append(...items) { this.children.push(...items); }
  replaceChildren(...items) { this.children = items; this._text = ''; }
  setAttribute(name, value) { this.attributes[name] = value; }
}
function fixture() {
  const root = new Element('div');
  const window = {};
  const document = {
    getElementById(id) {
      assert.equal(id, 'admin-kyc-queue');
      return root;
    },
    createElement(tag) { return new Element(tag); },
  };
  const source = path.join(__dirname, '../services/business-api/public/admin-kyc-queue.js');
  vm.runInNewContext(fs.readFileSync(source, 'utf8'), {
    window, document, AbortController, URLSearchParams,
  });
  return {root, setActor:window.EvAdminKycQueue.setActor};
}
function nodes(root, tag) {
  const matches = [];
  function walk(node) {
    if (node.tag === tag) matches.push(node);
    node.children.forEach(walk);
  }
  walk(root);
  return matches;
}
const tick = () => new Promise(resolve => setImmediate(resolve));
const entry = {
  id:17, legal_name:'<img onerror=alert(1)>', country:'AE',
  status:'submitted', license_expires_on:'2027-02-01',
  document_count:1, document_kinds:['trade_license'],
  owner:{id:3,name:'Private Owner',email:'review@example.test',
    account_status:'active',email_verified:true},
};
const page = (row = entry) => ({
  current_page:1, last_page:2, total:30, data:[row],
});

test('only Admin actor fetches queue; logout clears private KYC details', async () => {
  const ui = fixture();
  const paths = [];
  ui.setActor({role:'vendor'}, async p => {paths.push(p); return page();});
  await tick();
  assert.equal(paths.length, 0);
  assert.equal(ui.root.children.length, 0);

  ui.setActor({role:'admin'}, async p => {paths.push(p); return page();});
  await tick();
  assert.equal(paths.length, 1);
  assert.match(paths[0], /status=submitted/);
  assert.match(ui.root.textContent, /review@example.test/);
  assert.equal(nodes(ui.root, 'img').length, 0);
  ui.setActor(null, async () => {throw Error('no fetch');});
  assert.equal(ui.root.textContent, '');
  assert.equal(ui.root.children.length, 0);
});

test('evidence is fetched only on request, linked to authenticated same-origin KYC endpoint', async () => {
  const ui = fixture();
  const paths = [];
  ui.setActor({role:'admin'}, async p => {
    paths.push(p);
    if (p.includes('/documents?page=1')) return {
      data:[{id:44,kind:'trade_license',mime_type:'application/pdf',
        storage_path:'PRIVATE-STORAGE-PATH', sha256:'PRIVATE-HASH'}],
    };
    return page();
  });
  await tick();
  assert.equal(paths.length, 1, 'document metadata must not auto-load');
  assert.equal(nodes(ui.root, 'a').length, 0);
  const inspect = nodes(ui.root,'button').find(x=>x.textContent.includes('Inspect private evidence'));
  assert.ok(inspect);
  await inspect.onclick();
  assert.equal(paths.length, 2);
  assert.match(paths[1], /vendor-ops\/companies\/17\/documents/);
  const links = nodes(ui.root,'a');
  assert.equal(links.length,1);
  assert.equal(links[0].href, '/api/vendor-ops/companies/17/documents/44');
  assert.equal(links[0].rel, 'noopener noreferrer');
  assert.equal(nodes(ui.root,'img').length,0);
  assert.doesNotMatch(ui.root.textContent,/PRIVATE-STORAGE-PATH|PRIVATE-HASH/);
});

test('review action requires a reason and posts through existing VendorOps review endpoint', async () => {
  const ui = fixture();
  const calls = [];
  let state = 'submitted';
  ui.setActor({role:'admin'}, async (p, method = 'GET', payload) => {
    calls.push({p, method, payload});
    if (p === 'vendor-ops/companies/17') return {
      ...entry, submission_readiness:{
        license_valid:true, trade_license_integrity_valid:true,
      },
    };
    if (p.endsWith('/review')) {
      state = 'approved';
      return {status:'approved'};
    }
    return state === 'approved' ? {...page(),data:[]} : page();
  });
  await tick();
  const form = nodes(ui.root,'form').find(x=>nodes(x,'textarea').length);
  assert.ok(form);
  nodes(form,'textarea')[0].value = 'short';
  await form.onsubmit({preventDefault() {}});
  assert.equal(calls.filter(x=>x.p.endsWith('/review')).length,0);
  nodes(form,'textarea')[0].value = 'Valid verified KYC review evidence';
  await form.onsubmit({preventDefault() {}});
  await tick();
  const review = calls.filter(x=>x.p.endsWith('/review'));
  assert.equal(review.length,1);
  assert.equal(review[0].p,'vendor-ops/companies/17/review');
  assert.equal(review[0].method,'POST');
  assert.equal(review[0].payload.status,'approved');
  assert.equal(review[0].payload.reason,'Valid verified KYC review evidence');
  assert.match(ui.root.textContent,/No companies in this review queue/);
});

test('approval cannot be submitted if owner is suspended or trade-license evidence absent', async () => {
  for (const company of [
    {...entry, owner:{...entry.owner,account_status:'suspended'}},
    {...entry, owner:{...entry.owner,email_verified:false}},
    {...entry, document_count:0, document_kinds:[]},
  ]) {
    const ui = fixture();
    const calls = [];
    ui.setActor({role:'admin'}, async p => {
      calls.push(p);
      return page(company);
    });
    await tick();
    const form = nodes(ui.root,'form').find(x=>nodes(x,'textarea').length);
    nodes(form,'textarea')[0].value = 'Reviewed complete evidence and credentials';
    await form.onsubmit({preventDefault() {}});
    assert.equal(calls.length,1,'review mutation must be blocked');
    assert.match(ui.root.textContent,/Cannot approve without active owner/);
  }
});

test('KYC status and country filters are scoped and requests are cancelled on signout', async () => {
  const ui = fixture();
  const paths = [];
  ui.setActor({role:'admin'}, async p => {paths.push(p); return page();});
  await tick();
  const filters = nodes(ui.root,'form')[0];
  nodes(filters,'select')[0].value = 'approved';
  nodes(filters,'input')[0].value = 'ca';
  filters.onsubmit({preventDefault() {}});
  await tick();
  assert.match(paths[1], /status=approved/);
  assert.match(paths[1], /country=CA/);
  ui.setActor(null, async()=>{throw Error('not called');});
  assert.equal(ui.root.children.length, 0);
});

test('stale document response cannot render after Admin identity changes', async () => {
  const ui = fixture();
  let release;
  ui.setActor({role:'admin'}, p => {
    if (p.includes('/documents?page=1')) {
      return new Promise(resolve=>{release=resolve;});
    }
    return Promise.resolve(page());
  });
  await tick();
  const button = nodes(ui.root,'button').find(x=>x.textContent.includes('Inspect private evidence'));
  const pending = button.onclick();
  assert.equal(typeof release,'function');
  ui.setActor({role:'customer'},async()=>{throw Error('customer cannot fetch');});
  release({data:[{id:44,kind:'identity',mime_type:'image/png'}]});
  await pending;
  await tick();
  assert.equal(ui.root.textContent,'');
  assert.equal(nodes(ui.root,'a').length,0);
});


test('Admin approval checks fresh encrypted-license integrity, but allows correction decisions', async () => {
  const ui = fixture();
  const calls = [];
  ui.setActor({role:'admin'}, async (p, method = 'GET', payload) => {
    calls.push({p, method, payload});
    if (p === 'vendor-ops/companies/17') return {
      ...entry,
      submission_readiness:{
        license_valid:true,
        trade_license_present:true,
        trade_license_integrity_valid:false,
      },
    };
    if (p.endsWith('/review')) return {status:'changes_requested'};
    return page();
  });
  await tick();
  const form = nodes(ui.root,'form').find(x=>nodes(x,'textarea').length);
  const reason = nodes(form,'textarea')[0];
  reason.value = 'Evidence cannot be verified from encrypted private file.';
  await form.onsubmit({preventDefault(){}});
  assert.equal(calls.filter(x=>x.p.endsWith('/review')).length, 0,
    'Approval must not be POSTed when latest evidence fails integrity validation.');
  assert.equal(calls.filter(x=>x.p === 'vendor-ops/companies/17').length, 1);
  assert.match(ui.root.textContent, /Cannot approve: trade-license evidence/);

  nodes(form,'select')[0].value = 'changes_requested';
  await form.onsubmit({preventDefault(){}});
  const decisions = calls.filter(x=>x.p.endsWith('/review'));
  assert.equal(decisions.length, 1,
    'Reviewers can still request a replacement for invalid evidence.');
  assert.equal(decisions[0].payload.status,'changes_requested');
  assert.equal(calls.filter(x=>x.p === 'vendor-ops/companies/17').length, 1,
    'Non-approval correction must not be blocked by a failed integrity preflight.');
});

test('stale approval preflight cannot POST after Admin logout', async () => {
  const ui = fixture();
  let completeDetail;
  const calls = [];
  ui.setActor({role:'admin'}, (p, method = 'GET', payload) => {
    calls.push({p, method, payload});
    if (p === 'vendor-ops/companies/17') return new Promise(resolve => {
      completeDetail = resolve;
    });
    if (p.endsWith('/review')) throw Error('Stale approval must never post.');
    return Promise.resolve(page());
  });
  await tick();
  const form = nodes(ui.root,'form').find(x=>nodes(x,'textarea').length);
  nodes(form,'textarea')[0].value = 'The document was manually reviewed and verified.';
  const pending = form.onsubmit({preventDefault(){}});
  assert.equal(typeof completeDetail, 'function');
  ui.setActor(null,async()=>{throw Error('Guest access forbidden');});
  completeDetail({...entry,submission_readiness:{
    license_valid:true,trade_license_integrity_valid:true,
  }});
  await pending;
  assert.equal(calls.filter(x=>x.p.endsWith('/review')).length,0);
  assert.equal(ui.root.textContent,'');
});
