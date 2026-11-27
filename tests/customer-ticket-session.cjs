"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const {test} = require("node:test");
const src = fs.readFileSync("services/business-api/public/platform.js","utf8");
const stateStart = src.indexOf("let pendingTicketCreate = null");
const stateEnd = src.indexOf("let unconfirmedFavorite",stateStart);
const start = src.indexOf("async function ticketDetail(");
const end = src.indexOf('el("verify-email").onclick',start);
assert(stateStart>=0 && stateEnd>stateStart && start>stateEnd && end>start,
    "support ticket source boundary");
const section = "let ticketDetailGeneration=0, ticketDetailRequest=null, supportPage=1;\n" +
    src.slice(stateStart,stateEnd) + "\n" + src.slice(start,end);
function harness() {
    const client={sessionEpoch:1},user={id:7,role:"customer"};
    const root={children:[],dataset:{},setAttribute(){},removeAttribute(){},replaceChildren(...items){this.children=items;},append(...items){this.children.push(...items);}};
    const pending=[], notices=[];
    const form={disabled:false,isConnected:true};
    const fields={subject:{value:"New case",readOnly:false},body:{value:"Help",readOnly:false}};
    const createForm={isConnected:true,resetCount:0,elements:{namedItem:name=>fields[name]},querySelector:()=>form,reset(){this.resetCount++;}};
    const node=(tag,value)=>({tag,value,dataset:{},setAttribute(){},removeAttribute(){},children:[],append(...items){this.children.push(...items);}});
    const api=(path,method,payload)=>new Promise(resolve=>pending.push({path,method,payload,resolve}));
    const env={client,user,api,el:id=>id==="support-create"?createForm:root,node,ui:node,batteryNode:node,
        document:{createElement:tag=>node(tag)},
        window:{EvStationTime:{displayStationTime:()=>null}},AbortController,
        EvLocale:{attribute:()=>{}},notice:e=>notices.push(e),
        FormData:class { *[Symbol.iterator](){yield ["subject","New case"];yield ["body","Help"]; } },
        crypto:{randomUUID:()=> "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"}};
    const detail=new Function("env","with(env){"+section+"return ticketDetail;}")(env);
    return {detail,client,user,root,pending,notices,form,createForm,fields};
}
const result=(subject,id=17)=>({ticket:{id,subject,status:"closed"},messages:{data:[{body:subject,internal:false}],current_page:1,last_page:1,total:1}});
test("late ticket response cannot expose previous customer's messages",async()=>{
    const h=harness(),p=h.detail(17);
    h.client.sessionEpoch++;h.user.id=8;
    h.root.replaceChildren(); // normal account-change cleanup
    h.pending[0].resolve(result("Old customer's private case"));
    await p;
    assert.equal(h.root.children.length,0);
});
test("newer ticket selection wins over an older delayed response",async()=>{
    const h=harness(),first=h.detail(17),second=h.detail(18);
    h.pending[1].resolve(result("Current ticket",18));await second;
    h.pending[0].resolve(result("Old ticket"));await first;
    assert.equal(h.root.children[0].value,"{subject} · {status}");
    assert.equal(h.root.children[0].children.length,0);
    assert.equal(h.root.children[1].dataset.supportMessage,"true");
    assert.equal(h.root.children[1].children[0].value,"Current ticket");
    assert.equal(h.root.children[1].children[1].value,"Message time unavailable");
    assert.equal(h.root.children.length,3); // title, message, pagination
});

test("ticket create is single-flight and old account confirmation cannot open a case",async()=>{
    const h=harness(),event={preventDefault(){},target:h.createForm};
    const first=h.createForm.onsubmit(event);
    await h.createForm.onsubmit(event);
    assert.equal(h.pending.length,1);
    assert.equal(h.form.disabled,true);
    assert.equal(h.pending[0].method,"POST");
    h.client.sessionEpoch++;h.user.id=8;
    // Simulate the successor account's own form state. The old response
    // must not unlock its submission or touch its draft.
    h.fields.subject.value="Successor case";
    h.fields.body.value="Successor draft";
    h.pending[0].resolve({id:91,user_id:7,subject:"New case",status:"open"});
    await first;
    assert.equal(h.createForm.resetCount,0);
    assert.equal(h.root.children.length,0);
    assert.equal(h.form.disabled,true);
    assert.equal(h.fields.subject.readOnly,true);
    assert.equal(h.fields.body.readOnly,true);
    assert.equal(h.fields.subject.value,"Successor case");
    assert.equal(h.fields.body.value,"Successor draft");
    assert.equal(h.pending.length,1);
});
