// Local-only synthetic regression: no extra registration, API or limiter bypass.
const assert=require('node:assert/strict');
const {spawn,execFileSync}=require('node:child_process');
const {resolve}=require('node:path');
const {readFileSync}=require('node:fs');
const {provisionTestRole}=require('./browser-role.cjs');
async function provisionWithSqliteProbe(email,role,{page,runner}){
 const env=readFileSync('services/business-api/.env','utf8');
 assert(/^APP_ENV=local$/m.test(env)&&/^DB_CONNECTION=sqlite$/m.test(env),'Probe requires the disposable local SQLite checkout');
 const db=resolve('services/business-api/database/database.sqlite');
 const configured=env.match(/^DB_DATABASE=(.*)$/m)?.[1]?.trim().replace(/^['"]|['"]$/g,'');
 assert(!configured||resolve('services/business-api',configured)===db||resolve(configured)===db,'Refusing a non-default database probe');
 const url=new URL(page.url());assert(['127.0.0.1','localhost'].includes(url.hostname),'Probe is loopback-only');
 let releaseRoute;const gate=new Promise(r=>releaseRoute=r);
 const held=async route=>{await gate;await route.continue();};
 await page.route('**/api/me',held,{times:1});
 const seen=page.waitForRequest(r=>new URL(r.url()).pathname==='/api/me');
 await page.reload({waitUntil:'domcontentloaded'});await seen;
 const writer=spawn('python3',['-c',[
  'import sqlite3,sys,select',
  'c=sqlite3.connect(sys.argv[1],timeout=5)',
  'c.execute("BEGIN IMMEDIATE")',
  'print("LOCKED",flush=True)',
  'select.select([sys.stdin],[],[],20)',
  'c.rollback()',
  'c.close()',
 ].join('\n'),db],{stdio:['pipe','pipe','pipe']});
 const done=new Promise(resolve=>{writer.once('exit',code=>resolve(code));});
 let mutation=null,calls=0;
 try{
  await new Promise((resolve,reject)=>{
   const timer=setTimeout(()=>reject(Error('SQLite writer probe did not acquire its lock')),10000);
   writer.stdout.once('data',chunk=>{clearTimeout(timer);String(chunk).trim()==='LOCKED'?resolve():reject(Error('Invalid probe evidence'));});
   writer.once('error',error=>{clearTimeout(timer);reject(Error('SQLite writer probe could not start'));});
   writer.once('exit',()=>{clearTimeout(timer);reject(Error('SQLite writer probe exited before evidence'));});
  });
  mutation=provisionTestRole(email,role,{pages:[page],runner,runCommand:(...args)=>{calls++;return execFileSync(...args);}}).then(()=>null,error=>error);
  await Promise.resolve();
  assert.equal(calls,0,'Role mutation must wait while the browser identity GET is pending');
  writer.stdin.end('release\n');assert.equal(await done,0);
  releaseRoute();
  const error=await mutation;if(error)throw error;
  assert.equal(calls,1,'One privileged role transition only');
  const evidence=JSON.parse(execFileSync('python3',['-c',[
   'import sqlite3,sys,json',
   'c=sqlite3.connect(sys.argv[1])',
   'u=c.execute("select id,role from users where email=?",(sys.argv[2],)).fetchone()',
   'n=c.execute("select count(*) from operator_role_changes where target_user_id=?",(u[0],)).fetchone()[0]',
   'print(json.dumps({"role_matches":u[1]==sys.argv[3],"audit_rows":n}))',
  ].join('\n'),db,email,role],{encoding:'utf8'}));
  assert.deepEqual(evidence,{role_matches:true,audit_rows:1});
  console.log('PASS: pending real browser identity + held SQLite writer converge to one audited role change; no mutation replay or limiter reset.');
 }finally{
  releaseRoute();
  if(writer.exitCode===null){writer.stdin.end();await done;}
  if(mutation)await mutation;
  await page.unroute('**/api/me',held);
 }
}
module.exports={provisionWithSqliteProbe};
