'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
class Element{
 constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.value='';this.text='';}
 append(...nodes){this.children.push(...nodes);}
 replaceChildren(...nodes){this.children=nodes;this.text='';}
 setAttribute(){}
 set textContent(value){this.text=String(value);this.children=[];}
 get textContent(){return this.text+this.children.map(x=>x.textContent||'').join('');}
}
const sample={data:[{key:'catalog:7',kind:'catalog',station:{id:7,name:'<b>Catalog</b>',evidence:'SIMULATED'}},{key:'partner:7',kind:'partner',record_id:7,provider_label:'Private operator label',location:{name:'<img src=x>',address:'Synthetic',country:'ARE'},availability:'unknown',remote_control:false,reservable:false}],current_page:1,last_page:2,total:3};
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function fixture(lang='en',api=async()=>sample){const events=[],window={addEventListener(){},dispatchEvent(event){events.push(event);}},document={getElementById:()=>null,documentElement:{lang},createElement:tag=>new Element(tag)};vm.runInNewContext(fs.readFileSync('services/business-api/public/unified-discovery.js','utf8'),{window,document,URLSearchParams,CustomEvent:class{constructor(type,data){this.type=type;this.detail=data.detail;}}});const root=new Element('section'),chosen=[],calls=[];const control=window.EvUnifiedDiscovery.install(root,async(...args)=>{calls.push(args);return api(...args);},id=>chosen.push(id));return{root,chosen,calls,control,events,form:root.children[2],list:root.children[4],nav:root.children[5]};}
for(const lang of ['en','ar'])test('actual unified DOM query, typed identities and safe source text '+lang,async()=>{
 const f=fixture(lang);assert.equal(f.calls.length,0);f.form.children[0].children[0].value='100%_hub';f.form.onsubmit({preventDefault(){}});await tick();assert.equal(new URLSearchParams(f.calls[0][0].split('?')[1]).get('q'),'100%_hub');assert.equal(f.list.children.length,2);assert.match(f.list.textContent,/<img src=x>/);assert.equal(f.list.children[1].children.some(n=>n.tag==='button'),false);f.list.children[0].children.find(n=>n.tag==='button').onclick();assert.deepEqual(f.chosen,[7]);assert.equal(f.list.children[0].children[0].innerHTML,undefined);assert.match(f.list.textContent,lang==='ar'?/التوفر غير معروف/:/availability unknown/);f.nav.children.find(n=>n.tag==='button').onclick();await tick();assert.equal(new URLSearchParams(f.calls[1][0].split('?')[1]).get('page'),'2');assert(f.calls.every(args=>args.length===1));
});
test('late results are discarded after explicit clear without another lookup',async()=>{
 let finish;const f=fixture('en',()=>new Promise(resolve=>finish=resolve));f.form.onsubmit({preventDefault(){}});f.control.clear();finish(sample);await tick();assert.equal(f.list.children.length,0);assert.equal(f.calls.length,1);
});
test('partner authority claims never render an actionable card',async()=>{
 const f=fixture('en',async()=>({...sample,data:[{...sample.data[1],reservable:true}]}));f.form.onsubmit({preventDefault(){}});await tick();assert.equal(f.list.children.length,0);assert.deepEqual(f.chosen,[]);
});

test('mapping the mixed page is explicit, preserves typed sources and performs no lookup',async()=>{
 const f=fixture();assert.equal(f.events.length,0);f.form.onsubmit({preventDefault(){}});await tick();assert.equal(f.events.length,0);f.root.children[6].onclick();assert.equal(f.events.length,1);assert.equal(f.events[0].type,'unified-map-data');assert.deepEqual(Array.from(f.events[0].detail,row=>row.key),['catalog:7','partner:7']);assert.equal(f.calls.length,1);f.control.clear();assert.equal(f.root.children[6].disabled,true);
});
