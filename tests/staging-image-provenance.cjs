'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {parseArgs,parseInspect,validateImageContainers,readPrivate,localInspect,main} = require('../scripts/staging-image-provenance.cjs');
const SHA='f'.repeat(40),ID='sha256:'+'a'.repeat(64);
const ROLES=['application','worker','scheduler'];
function data() {
  const image = [{Id:ID,Config:{Labels:{
    'org.opencontainers.image.revision':SHA,
    'org.opencontainers.image.source':'https://github.com/creabizsalonspa-create/EV',
    'com.creabiz.ev.staging.review':'true'
  }}}];
  const containers = ROLES.map((role,i)=>({Id:String(i+1).repeat(64),Image:ID,
    State:{Running:true,Status:'running'},Config:{Image:'creabiz-ev-business:review',Labels:{
      'com.docker.compose.project':'ev-staging','com.docker.compose.service':role}}}));
  return {image,containers};
}
function withMutation(mut,term) {
  const {image,containers}=data();mut(image,containers);
  const res=validateImageContainers(image,containers,SHA);
  assert.equal(res.ok,false);assert.ok(res.errors.some(e=>e.includes(term)),JSON.stringify(res.errors));
}
function privateDir(fn){const d=fs.mkdtempSync(path.join(os.tmpdir(),'ev-prov-'));fs.chmodSync(d,0o700);try{return fn(d);}finally{fs.rmSync(d,{recursive:true,force:true})}}
function composeRows(containers) {
  const rows=containers.map(c=>({ID:c.Id,Service:c.Config.Labels['com.docker.compose.service'],
    Project:'ev-staging',State:'running',Health:'',Image:c.Config.Image,Publishers:[]}));
  rows[0].Health='healthy';
  rows.push({ID:'5'.repeat(64),Service:'database',Project:'ev-staging',State:'running',Health:'healthy',Image:'postgres:17-alpine@sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b',Publishers:[]});
  rows.push({ID:'6'.repeat(64),Service:'gateway',Project:'ev-staging',State:'running',Health:'',Image:'caddy:2-alpine@sha256:78bd35025c7ba85961dd149ae670315129954e77abe2383ac47c89106b35ade9',Publishers:[{URL:'127.0.0.1',TargetPort:443,PublishedPort:8443,Protocol:'tcp'}]});
  return rows;
}
test('valid triple application container IDs and labeled image pass',()=>{
  const {image,containers}=data();const r=validateImageContainers(image,containers,SHA);
  assert.equal(r.ok,true);assert.equal(r.image_id,ID);
});
test('revision/source labels and review flag are mandatory',()=>{
  withMutation(x=>x[0].Config.Labels['org.opencontainers.image.revision']='0'.repeat(40),'revision');
  withMutation(x=>delete x[0].Config.Labels['org.opencontainers.image.source'],'revision');
  withMutation(x=>x[0].Config.Labels['com.creabiz.ev.staging.review']='false','review');
});
test('reject running container image ID mismatch or mutable image role mismatch',()=>{
  withMutation((_a,c)=>c[1].Image='sha256:'+'b'.repeat(64),'image differs');
  withMutation((_a,c)=>c[1].Config.Image='creabiz-ev-business:old','different image tags');
});
test('reject stopped or duplicate container roles and IDs',()=>{
  withMutation((_a,c)=>c[0].State.Running=false,'Stopped');
  withMutation((_a,c)=>c[1].Config.Labels['com.docker.compose.service']='application','duplicate');
  withMutation((_a,c)=>c[2].Id=c[1].Id,'container ID');
});
test('reject non-staging project, unknown image tags, extra containers',()=>{
  withMutation((_a,c)=>c[2].Config.Labels['com.docker.compose.project']='production','project');
  withMutation((_a,c)=>c[0].Config.Image='evil:latest','Unrecognized');
  withMutation((_a,c)=>c.pop(),'Incomplete');
});
test('invalid snapshot forms and malformed inputs reject',()=>{
  assert.throws(()=>parseInspect('not json'));
  assert.throws(()=>parseInspect('{}'));
  assert.throws(()=>parseInspect('[{}]'.repeat(900000)));
  assert.throws(()=>parseArgs(['--source-sha',SHA,'--live']));
  assert.throws(()=>parseArgs(['--live','--source-sha','e']));
  assert.equal(parseArgs(['--live','--source-sha',SHA]).mode,'live');
  assert.equal(parseArgs(['--source-sha',SHA,'--image-snapshot','a','--containers-snapshot','b']).mode,'snapshot');
});
test('private snapshot requirements reject symlinks and public-readable files',()=>privateDir(dir=>{
  const a=path.join(dir,'image.json');fs.writeFileSync(a,JSON.stringify(data().image),{mode:0o600});
  assert.ok(readPrivate(a).startsWith('['));
  const link=path.join(dir,'link.json');fs.symlinkSync(a,link);assert.throws(()=>readPrivate(link));
  if(process.platform!=='win32'){fs.chmodSync(a,0o644);assert.throws(()=>readPrivate(a));}
}));
test('live mode uses only inspect commands and detects source/runtime races',()=>{
  const {image,containers}=data();let cmds=[],snapshots=0;
  const deps={
    getCompose:()=>{snapshots++;return JSON.stringify(composeRows(containers));},
    run:(cmd,args)=>{cmds.push([cmd,...args]); if(args[0]==='container')return JSON.stringify(containers); if(args[0]==='image')return JSON.stringify(image); throw Error('Unexpected call');}
  };
  assert.equal(localInspect(SHA,deps).ok,true);
  assert.deepEqual(cmds.map(x=>x[1]),['container','image']);assert.equal(snapshots,2);
  const fail={...deps,getCompose:()=>JSON.stringify(composeRows(containers).map(x=>({...x,ID:x.Service==='worker'?'9'.repeat(64):x.ID})))};
  assert.throws(()=>localInspect(SHA,fail));
});
test('live mode checks snapshot timing and denies app exposed externally',()=>{
  const {containers}=data();let i=0;
  const deps={run:(_cmd,args)=>args[0]==='container'?JSON.stringify(containers):JSON.stringify(data().image),getCompose:()=>{
    i++;const rows=composeRows(containers);if(i===2)rows[0].ID='9'.repeat(64);return JSON.stringify(rows);
  }};
  assert.throws(()=>localInspect(SHA,deps));
  assert.throws(()=>localInspect(SHA,{...deps,getCompose:()=>{const x=composeRows(containers);x[0].Publishers=[{URL:'0.0.0.0',PublishedPort:8080,TargetPort:80,Protocol:'tcp'}];return JSON.stringify(x)}}));
});
test('CLI offline snapshot pass, bad SHA fails without leaking content',()=>privateDir(dir=>{
  const {image,containers}=data(),a=path.join(dir,'image.json'),b=path.join(dir,'containers.json');
  fs.writeFileSync(a,JSON.stringify(image),{mode:0o600});fs.writeFileSync(b,JSON.stringify(containers),{mode:0o600});
  assert.equal(main(['--source-sha',SHA,'--image-snapshot',a,'--containers-snapshot',b]),0);
  assert.equal(main(['--source-sha','0'.repeat(40),'--image-snapshot',a,'--containers-snapshot',b]),1);
}));
