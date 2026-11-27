"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const {test} = require("node:test");
const src = fs.readFileSync("services/business-api/public/platform.js", "utf8");
const stateBegin = src.indexOf("let fleetPage = 1");
const stateEnd = src.indexOf("let unconfirmedFavorite",stateBegin);
const begin = src.indexOf("function resetCustomerMutationState()");
const end = src.indexOf("async function support()", begin);
assert(stateBegin >= 0 && stateEnd > stateBegin &&
    begin > stateEnd && end > begin, "customer garage source boundary");
const ticketBegin = src.indexOf('el("support-create").onsubmit');
const ticketEnd = src.indexOf('el("verify-email")',ticketBegin);
assert(ticketBegin > end && ticketEnd > ticketBegin, "customer support source boundary");
const code = "let garagePage=1, garageGeneration=0, garageRequest=null, garageOwner=null;\n" +
    src.slice(stateBegin,stateEnd) + "\n" +
    src.slice(begin,end) + "\n" + src.slice(ticketBegin,ticketEnd);
function harness() {
    const elements = new Map();
    const el = id => {
        if (!elements.has(id)) elements.set(id,{
            children:[],
            isConnected:true,
            elements:{name:{value:""},locale:{value:""},subject:{value:"Reviewed support issue",readOnly:false},body:{value:"Exact support request",readOnly:false},namedItem(name){return this[name]??null;}},
            setAttribute(){},removeAttribute(){},dataset:{},
            replaceChildren(...children){this.children=children;},
            append(...children){this.children.push(...children);},
            querySelector(selector){return selector === "button" ? this.submit || null : null;},
            reset(){this.resetCount=(this.resetCount||0)+1;}
        });
        return elements.get(id);
    };
    const user = {id:7,role:"customer"};
    const client = {sessionEpoch:1};
    let reads = [], creates = [], profiles = [], tickets = [],
        requests = [], notices = [], localeUpdates = [],
        supportReloads = 0, openedTickets = [], keyCount = 0;
    const api = (path,method,data) => {
        requests.push({path,method,data});
        if (path === "vehicles" && method === "POST")
            return new Promise(resolve => {creates.push(resolve);});
        if (path === "profile" && method === "PATCH")
            return new Promise(resolve => {profiles.push(resolve);});
        if (path === "support" && method === "POST")
            return new Promise(resolve => {tickets.push(resolve);});
        if (path === "vehicles" || path.startsWith("vehicles?page="))
            return new Promise(resolve => {reads.push(resolve);});
        throw Error("unexpected "+path);
    };
    const node = (tag,value) => ({tag,value,dataset:{},children:[],setAttribute(){},removeAttribute(){},append(...items){this.children.push(...items);}});
    const env = {el,client,user,api,node,ui:node,batteryNode:node,supportPage:1,
        batteryEditor:()=>{},driverPreferences:{open:()=>{}},
        vehicleModelCatalog:{renderGarage:()=>{}},
        vehicleManualSoc:{renderGarage:()=>{}},
        document:{createElement:tag=>node(tag),documentElement:{lang:"en"}},
        notice:e=>notices.push(e),
        EvLocale:{set:value=>localeUpdates.push(value)},
        identity:async()=>{},
        support:async()=>{supportReloads++;},
        ticketDetail:async id=>{openedTickets.push(id);},
        crypto:{randomUUID:()=>String(++keyCount).padStart(32,"0")},
        FormData:class {constructor(){} *[Symbol.iterator](){}}
    };
    const methods = new Function("env", "with(env){"+code+"return {garage,resetCustomerMutationState};}")(env);
    return {methods,el,client,user,requests,notices,localeUpdates,
        resolveRead:(index,value)=>reads[index]({current_page:1,last_page:1,total:value?.data?.length??0,...value}),
        resolveCreate:(index=0)=>creates[index](),
        resolveProfile:(value,index=0)=>profiles[index](value),
        resolveTicket:(index,value)=>tickets[index](value),
        supportReloads:()=>supportReloads,
        openedTickets:()=>openedTickets};
}
test("late garage response cannot render a previous customer's private vehicles",async()=>{
    const h=harness(),p=h.methods.garage();
    h.client.sessionEpoch++;
    h.user.id=8;
    h.el("vehicle-list").replaceChildren(); // normal account-switch cleanup
    h.resolveRead(0,{data:[{id:55,name:"Private",connector_type:"CCS2"}]});
    await p;
    assert.equal(h.el("vehicle-list").children.length,0);
});
test("overlapping garage reads cannot replace the newest list",async()=>{
    const h=harness(),first=h.methods.garage();
    const second=h.methods.garage();
    h.resolveRead(1,{data:[]});await second;
    h.resolveRead(0,{data:[{id:55,name:"Old",connector_type:"CCS2"}]});await first;
    assert.equal(h.el("vehicle-list").children.some(row=>String(row.value).includes("Old")),false);
});
test("vehicle create submits only once while pending and does not render after account switch",async()=>{
    const h=harness(),form=h.el("vehicle-create");
    form.submit={disabled:false,isConnected:true};
    const event={preventDefault(){},target:form};
    const first=form.onsubmit(event);
    await form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.method==="POST").length,1);
    assert.equal(form.submit.disabled,true);
    h.client.sessionEpoch++;
    h.user.id=8;
    h.resolveCreate();
    await first;
    assert.equal(h.requests.filter(x=>x.path==="vehicles" && !x.method).length,0);
    assert.equal(form.submit.disabled,false);
});

test("late profile PATCH cannot overwrite another signed-in account or locale",async()=>{
    const h=harness(),form=h.el("profile-form");
    form.submit={disabled:false,isConnected:true};
    const event={preventDefault(){},target:form};
    const first=form.onsubmit(event);
    await form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.method==="PATCH").length,1);
    assert.equal(form.submit.disabled,true);
    h.client.sessionEpoch++;
    h.user.id=8;
    h.resolveProfile({id:7,name:"Old account",locale:"ar"});
    await first;
    assert.equal(h.user.id,8);
    assert.equal(h.localeUpdates.length,0);
    assert.equal(form.submit.disabled,false);
});

test("starting a garage refresh immediately clears previously displayed private rows",async()=>{
    const h=harness(),list=h.el("vehicle-list");
    list.append({value:"Prior account vehicle"});
    const pending=h.methods.garage();
    assert.equal(list.children.some(row=>String(row.value).includes("Prior account vehicle")),false);
    h.client.sessionEpoch++;
    h.resolveRead(0,{data:[{id:1,name:"Previous",connector_type:"CCS2"}]});
    await pending;
    assert.equal(list.children.some(row=>String(row.value).includes("Previous")),false);
});

test("new account vehicle create stays locked independently of old pending acknowledgement",async()=>{
    const h=harness(),form=h.el("vehicle-create");
    form.submit={disabled:false,isConnected:true};
    const event={preventDefault(){},target:form};
    const former=form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.path==="vehicles"&&x.method==="POST").length,1);
    h.client.sessionEpoch++;
    h.user.id=8;
    h.methods.resetCustomerMutationState();
    const current=form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.path==="vehicles"&&x.method==="POST").length,2);
    assert.equal(form.submit.disabled,true);
    h.resolveCreate(0);
    await former;
    assert.equal(form.submit.disabled,true,
        "late former actor cannot release the current actor's in-flight lock");
    h.resolveCreate(1);
    await Promise.resolve();
    h.resolveRead(0,{data:[]});
    await current;
    assert.equal(form.submit.disabled,false);
});
test("new account profile PATCH survives older account acknowledgement",async()=>{
    const h=harness(),form=h.el("profile-form");
    form.submit={disabled:false,isConnected:true};
    const event={preventDefault(){},target:form};
    const former=form.onsubmit(event);
    h.client.sessionEpoch++;
    h.user.id=8;
    h.methods.resetCustomerMutationState();
    const current=form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.path==="profile"&&x.method==="PATCH").length,2);
    assert.equal(form.submit.disabled,true);
    h.resolveProfile({id:7,locale:"ar"},0);
    await former;
    assert.equal(form.submit.disabled,true);
    assert.equal(h.localeUpdates.length,0);
    h.resolveProfile({id:8,locale:"en"},1);
    await current;
    assert.equal(form.submit.disabled,false);
    assert.equal(h.localeUpdates.length,1);
    assert.equal(h.localeUpdates[0],"en");
});

test("support ticket creation uses independent account-scoped pending tokens",async()=>{
    const h=harness(),form=h.el("support-create");
    form.submit={disabled:false,isConnected:true};
    const event={preventDefault(){},target:form};
    const old=form.onsubmit(event);
    await form.onsubmit(event);
    assert.equal(h.requests.filter(x=>x.path==="support"&&x.method==="POST").length,1);
    h.client.sessionEpoch++;
    h.user.id=8;
    h.methods.resetCustomerMutationState();
    const current=form.onsubmit(event);
    const posts=h.requests.filter(x=>x.path==="support"&&x.method==="POST");
    assert.equal(posts.length,2);
    assert.notEqual(posts[0].data.idempotency_key,posts[1].data.idempotency_key);
    assert.equal(form.submit.disabled,true);
    h.resolveTicket(0,{id:70,user_id:7,subject:posts[0].data.subject,status:"open"});
    await old;
    assert.equal(form.submit.disabled,true);
    assert.equal(h.supportReloads(),0);
    h.resolveTicket(1,{id:71,user_id:8,subject:posts[1].data.subject,status:"open"});
    await current;
    assert.equal(form.submit.disabled,false);
    assert.equal(h.supportReloads(),1);
    assert.deepEqual(h.openedTickets(),[71]);
    assert.equal(form.resetCount,1);
    assert.equal(form.elements.subject.readOnly,false);
    assert.equal(form.elements.body.readOnly,false);
});

test("pending-logout startup has initialized mutation locks before private cleanup",async()=>{
    assert(stateBegin < src.indexOf("function clearPrivateViews()"),
        "startup cleanup must never reference uninitialized let bindings");
    assert(stateBegin < src.indexOf("(async () => {"),
        "startup async signOut may clear identity synchronously");
    const h=harness();
    for(const id of ["vehicle-create","profile-form","support-create"]) {
        h.el(id).submit={disabled:true,isConnected:true};
    }
    h.methods.resetCustomerMutationState();
    for(const id of ["vehicle-create","profile-form","support-create"])
        assert.equal(h.el(id).submit.disabled,false);
});
