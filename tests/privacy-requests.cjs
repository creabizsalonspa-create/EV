// Read the shipped browser source; isolated DOM contract without a deployed backend.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
  constructor(tag) {
    this.tag = tag; this.children = []; this.dataset = {};
    this.textContent = ''; this.className = ''; this.value = '';
    this.style = {}; this.attributes = {};
  }
  append(...values) {this.children.push(...values);}
  replaceChildren(...values) {this.children = values;}
  setAttribute(key, value) {this.attributes[key] = value;}
  querySelector() {return null;}
}
const doc = {documentElement: {lang:'en'},createElement: tag => new Element(tag)};
const window = {};
const path = 'services/business-api/public/privacy-requests.js';
vm.runInNewContext(fs.readFileSync(path,'utf8'),{document:doc,window,Object,Uint8Array},{filename:path});
assert.equal(typeof window.createPrivacyWorkspace, 'function');
const user1 = {role:'customer',id:1}, user2 = {role:'customer',id:2};
let actor = user1, epoch = 1;
const calls = [];
const api = async path => {
  calls.push(path);
  return {
    data:actor.id === 1 ? [{
      id:100, request_type:'account_deletion', status:'submitted',
      reason:'<img src=x onerror=alert(1)> secret-private-note',
      resolution:null,decision_note:null,
    }] : [],
    last_page:1,current_page:1,total:actor.id===1?1:0,
  };
};
const mine = doc.createElement('section'), admin = doc.createElement('section');
const ui = window.createPrivacyWorkspace({selfRoot:mine,adminRoot:admin,api,actor:()=>actor,epoch:()=>epoch});
const content = root => {
  function walk(node) {return [node.textContent, ...node.children.flatMap(walk)].join(' ');}
  return walk(root);
};
(async () => {
  await ui.setActor(user1);
  assert.match(content(mine), /secret-private-note/);
  assert.match(content(mine), /not instant account deletion/);
  assert.match(content(mine), /Withdraw request/);
  const initialCalls = calls.length;
  const find = (node, predicate) => predicate(node) ? node :
    node.children.map(child => find(child, predicate)).find(Boolean);
  const staleForm = find(mine, node => node.tag === 'form');
  assert.ok(staleForm);
  epoch++;
  await staleForm.onsubmit({preventDefault(){}});
  assert.equal(calls.length, initialCalls,
    'Detached previous-session form may not POST private data');
  await ui.setActor(user1);
  assert.ok(calls.length > initialCalls,
    'Same actor object in a new session must perform a fresh private request');
  epoch++;
  await ui.refreshMine();
  assert.equal(mine.children.length, 0,
    'Old-session private request view must be cleared before stale refresh');
  await ui.setActor(user1);
  assert.match(content(mine), /secret-private-note/);
  actor = user2;
  await ui.setActor(user2);
  assert.doesNotMatch(content(mine), /secret-private-note/);
  assert.match(content(mine), /No privacy requests submitted/);
  // Admin has TWO separate workspaces. Refreshing one must not invalidate
  // the other's still-visible controls during the same authenticated session.
  const adminUser = {role:'admin',id:3};
  actor=adminUser;epoch++;
  await ui.setActor(adminUser);
  assert.match(content(admin),/Privacy request reviews/);
  const ownRefresh=find(mine,node=>node.tag==='button' &&
    content(node).includes('Refresh my requests'));
  const adminRefresh=find(admin,node=>node.tag==='button' &&
    content(node).includes('Refresh review queue'));
  assert.ok(ownRefresh&&adminRefresh);
  const ownCount=()=>calls.filter(path=>path==='privacy-requests?page=1').length;
  const adminCount=()=>calls.filter(path=>path==='admin/privacy-requests?page=1').length;
  const beforeMineRefresh=ownCount();
  await ownRefresh.onclick();
  assert.equal(ownCount(),beforeMineRefresh+1,
    'Admin own privacy list must remain refreshable after Admin queue initializes');
  const beforeAdminRefresh=adminCount();
  await adminRefresh.onclick();
  assert.equal(adminCount(),beforeAdminRefresh+1,
    'Admin review queue must remain refreshable after own list refresh');
  assert.match(content(mine),/Privacy and data requests/);
  assert.match(content(admin),/Privacy request reviews/);
  ui.clear();
  assert.equal(mine.children.length, 0);
  assert.doesNotMatch(fs.readFileSync(path,'utf8'), /\.innerHTML\s*=/);
  console.log('PASS: scoped privacy web request view, safe text, cross-account clearing and explicit non-deletion notice.');
})().catch(e => {console.error(e);process.exitCode=1;});
