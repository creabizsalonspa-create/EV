// Source-backed aggregate-only vendor web contract; no customer data exposure.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
  constructor(tag) {
    this.tag = tag; this.dataset = {}; this.children = []; this.textContent = '';
    this.attributes = {}; this.value = '';
  }
  append(...items) { this.children.push(...items); }
  prepend(...items) { this.children.unshift(...items); }
  replaceChildren(...items) { this.children = [...items]; }
  setAttribute(key,value) { this.attributes[key] = value; }
  removeAttribute(key) { delete this.attributes[key]; }
  querySelector(selector) {
    if (selector !== '[data-feedback-status]') return null;
    const walk = node => node.dataset?.feedbackStatus ? node
      : node.children?.map(walk).find(Boolean);
    return walk(this) || null;
  }
}
const document = {documentElement:{lang:'en'},createElement:tag=>new Element(tag)};
const window = {};
const path='services/business-api/public/vendor-feedback-analytics.js';
vm.runInNewContext(fs.readFileSync(path,'utf8'),{window,document,Number,Object},{filename:path});
assert.equal(typeof window.VendorFeedbackAnalytics.create,'function');
const sample = {
  source:'MODERATED_SIMULATOR_REVIEWS_ONLY',
  physical_charging_verified:false,
  days:30, station_id:null, total:2,average_rating:4,
  star_distribution:{1:0,2:0,3:0,4:2,5:0},
  evidence_counts:{SIMULATED_APP_ONLY:2,SIMULATED_SOURCE_OBSERVED:0},
  stations:[{station_id:10,station_name:'Demo station',total:2,average_rating:4}],
};
const root=document.createElement('section');
let actor={role:'vendor',id:11},epoch=1;
const calls=[];
const api=async path=>{calls.push(path);return sample;};
const view=window.VendorFeedbackAnalytics.create({root,api,actor:()=>actor,epoch:()=>epoch});
function walk(node) {return [node.textContent,...node.children.map(walk)].join(' ');}
(async()=>{
  await view.setActor(actor);
  assert.equal(calls.length,1);
  assert.equal(calls[0],'vendor/feedback-analytics?days=30');
  assert.match(walk(root),/Published reviews: 2/);
  assert.match(walk(root),/Demo station/);
  assert.match(walk(root),/Not physical charging/);
  assert.doesNotMatch(walk(root),/user_id|customer_email/);
  actor={role:'customer',id:22};epoch++;
  await view.setActor(actor);
  assert.equal(root.children.length,0);
  assert.doesNotMatch(fs.readFileSync(path,'utf8'),/\.innerHTML\s*=/);
  console.log('PASS: qualified vendor feedback analytics, no identities, vendor-role clear and safe text.');
})().catch(e=>{console.error(e);process.exitCode=1;});
