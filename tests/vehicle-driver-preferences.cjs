// Isolated regression against the shipped customer web metadata editor.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

class Element {
  constructor(tag) {
    this.tag = tag; this.dataset = {}; this.children = []; this.value = "";
    this.textContent = ""; this.attributes = {}; this.checked = false; this.disabled = false;
  }
  append(...items) { this.children.push(...items); }
  prepend(...items) { this.children.unshift(...items); }
  replaceChildren(...items) { this.children = [...items]; }
  setAttribute(k, v) { this.attributes[k] = v; }
  removeAttribute(k) { delete this.attributes[k]; }
  querySelector(selector) {
    if (selector === "[data-preference-status]") {
      const walk = node => node.dataset?.preferenceStatus ? node :
        node.children?.map(walk).find(Boolean);
      return walk(this) || null;
    }
    return null;
  }
}
const document = {
  documentElement: {lang: "en"},
  createElement: tag => new Element(tag),
  getElementById: () => null,
  querySelector: () => null,
};
const window = {};
const path = "services/business-api/public/driver-preferences.js";
vm.runInNewContext(fs.readFileSync(path,"utf8"),{document,window,Number,Set,Object},{filename:path});
assert.equal(typeof window.VehicleDriverPreferences.create,"function");
let actor = {id:7,role:"customer"}, generation = 1;
const calls = [];
const api = async (path,method,body) => {
  calls.push({path,method,body});
  if (method === "PUT") return {...body,revision:1,vehicle_id:9,applied_to_charging:false};
  return {
    vehicle_id:9,revision:0,preferred_power_kw:null,charging_goal:"balanced",
    accessible_site_required:false,avoid_paid_parking:false,
    scope:"DRIVER_PREFERENCE_ONLY",applied_to_charging:false,
  };
};
const root = document.createElement("section");
const editor = window.VehicleDriverPreferences.create({root,api,actor:()=>actor,epoch:()=>generation});
function locate(node,predicate) {
  if (predicate(node)) return node;
  for (const child of node.children||[]) {
    const found = locate(child,predicate);
    if (found) return found;
  }
  return null;
}
function visible(node) {
  return [node.textContent,...(node.children||[]).map(visible)].join(" ");
}
(async () => {
  await editor.open({id:9,name:"Family EV"});
  const form = locate(root,n=>n.tag==="form");
  assert.ok(form);
  assert.match(visible(root),/Preferences are for planning/);
  const power = locate(form,n=>n.name==="preferred_power_kw");
  const goal = locate(form,n=>n.name==="charging_goal");
  const access = locate(form,n=>n.name==="accessible_site_required");
  assert.ok(power && goal && access);
  power.value = "150";
  goal.value = "fastest";
  access.checked = true;
  await form.onsubmit({preventDefault(){}});
  const submitted = calls.find(c=>c.method==="PUT");
  assert.deepEqual(JSON.parse(JSON.stringify(submitted.body)),{
    revision:0,preferred_power_kw:150,charging_goal:"fastest",
    accessible_site_required:true,avoid_paid_parking:false,
  });
  assert.equal(submitted.path,"vehicles/9/driver-preferences");
  assert.match(visible(root),/Preferences saved/);
  // Opening another vehicle in the same account may reuse this workspace.
  // A detached form must not write its old values against the new vehicle.
  const oldForm=locate(root,n=>n.tag==="form");
  const oldApply=locate(oldForm,n=>n.tag==="button"&&visible(n).includes("Use preferred kW"));
  const oldClose=locate(oldForm,n=>n.tag==="button"&&visible(n).includes("Close"));
  await editor.open({id:10,name:"Other family car"});
  assert.match(visible(root),/Other family car/);
  const writesBefore=calls.filter(c=>c.method==="PUT").length;
  const oldPower=locate(oldForm,n=>n.name==="preferred_power_kw");
  oldPower.value="500";
  await oldForm.onsubmit({preventDefault(){}});
  assert.equal(calls.filter(c=>c.method==="PUT").length,writesBefore,
    "Detached vehicle form must not PUT preferences to the newly opened vehicle");
  let lookupCount=0;
  document.querySelector=()=>{lookupCount++;return null;};
  oldApply.onclick();
  assert.equal(lookupCount,0,
    "Detached preference control must not apply previous vehicle filters");
  oldClose.onclick();
  assert.match(visible(root),/Other family car/,
    "Detached close button must not clear a newly opened vehicle");
  actor = {id:8,role:"customer"}; generation++;
  editor.clear();
  assert.equal(root.children.length,0);
  await editor.open({id:9,name:"Other account EV"});
  assert.doesNotMatch(visible(root),/Preferences saved/);
  assert.doesNotMatch(fs.readFileSync(path,"utf8"),/\.innerHTML\s*=/);
  console.log("PASS: source-backed vehicle driver preference editing, versioned payload, tenant view clearing, safe content.");
})().catch(e=>{console.error(e);process.exitCode=1;});
