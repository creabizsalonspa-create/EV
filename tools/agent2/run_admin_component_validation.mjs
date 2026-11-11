#!/usr/bin/env node
/** Build the shipped Admin component in a disposable Next shell and run the
 * existing mocked-HTTP browser suite. This is COMPONENT_FIXTURE_ONLY acceptance,
 * not the purchased Admin app build or actual Express/Mongo integration.
 *
 * npm install --prefix DEPENDENCIES --ignore-scripts using the adjacent pinned
 * admin-component-fixture.package.json. No dependency install/deploy in this runner.
 * Usage: node run_admin_component_validation.mjs DEPENDENCIES CHROME_PATH REPORT.json
 */
import {mkdtemp,mkdir,readFile,writeFile,symlink,rm,stat} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve,join,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import {spawn} from 'node:child_process';
import {createServer} from 'node:net';
const here=dirname(fileURLToPath(import.meta.url)),repo=resolve(here,'../..');
const [depsArg,chromeArg,reportArg]=process.argv.slice(2);
if(!depsArg||!chromeArg||!reportArg)throw Error('Provide dependency root, Chrome executable and report JSON path');
const deps=resolve(depsArg),chrome=resolve(chromeArg),reportPath=resolve(reportArg);
const packages=JSON.parse(await readFile(join(here,'admin-component-fixture.package.json'),'utf8'));
const pinnedLock=await readFile(join(here,'admin-component-fixture.package-lock.json'));
if(!(await readFile(join(deps,'package-lock.json'))).equals(pinnedLock))
 throw Error('Dependency lock does not match the pinned component fixture; prepare it with npm ci');
const versions={};
for(const [name,version] of Object.entries(packages.dependencies)){
 const found=JSON.parse(await readFile(join(deps,'node_modules',name,'package.json'),'utf8'));
 if(found.version!==version)throw Error('Dependency does not match pinned component fixture: '+name);
 versions[name]=found.version;
}
if(!(await stat(chrome)).isFile())throw Error('Chrome executable required; no download is performed');
const work=await mkdtemp(join(tmpdir(),'agent2-admin-component-'));
await mkdir(dirname(reportPath),{recursive:true});
const logs=reportPath.replace(/\.json$/,'')+'-logs';await mkdir(logs,{recursive:true,mode:0o700});
const sourceFiles=['integrations/hasone-next-admin/OcppBookingWorkflow.tsx',
 'integrations/hasone-next-admin/ocppWorkflowEvidence.mjs','integrations/hasone-next-admin/ocppWorkflowEvidence.d.mts',
 'tools/agent2/test_next_admin_multicharger_browser.mjs','tools/agent2/run_admin_component_validation.mjs',
 'tools/agent2/admin-component-fixture.package.json','tools/agent2/admin-component-fixture.package-lock.json',
 'integrations/hasone-next-admin/ocpp-history-page.tsx','integrations/hasone-next-admin/ocppHistoryEvidence.mjs',
 'integrations/hasone-next-admin/ocppHistoryEvidence.d.mts','tools/agent2/test_admin_history_browser.mjs'];
const blobs={},snapshots=new Map();for(const name of sourceFiles){const bytes=await readFile(join(repo,name));snapshots.set(name,bytes);
 blobs[name]=createHash('sha1').update('blob '+bytes.length+'\0').update(bytes).digest('hex');}
let server;
async function run(label,command,args){
 const child=spawn(command,args,{cwd:work,env:{...process.env,NEXT_TELEMETRY_DISABLED:'1',
  PUPPETEER_PATH:join(deps,'node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js'),CHROME_PATH:chrome},stdio:['ignore','pipe','pipe']});
 let output='';child.stdout.on('data',d=>{output+=d;});child.stderr.on('data',d=>{output+=d;});
 const result=await new Promise((done,reject)=>{child.once('error',reject);child.once('exit',(code,signal)=>done({code,signal}));});
 const logPath=join(logs,label+'.log');await writeFile(logPath,output,{mode:0o600});
 if(result.code!==0||result.signal){console.error(output.split(/\r?\n/).slice(-25).join('\n'));throw Error(label+' failed; see '+logPath);}
 console.log('PASS '+label);return {output,logPath,sha256:createHash('sha256').update(output).digest('hex')};
}
try{
 for(const dir of ['app/bookings','app/login','app/ocpp-history','components','lib'])await mkdir(join(work,dir),{recursive:true});
 await symlink(join(deps,'node_modules'),join(work,'node_modules'),'dir');
 await writeFile(join(work,'package.json'),JSON.stringify(packages));
 await writeFile(join(work,'next.config.mjs'),`export default {outputFileTracingRoot:${JSON.stringify(work)},experimental:{cpus:2}};\n`);
 await writeFile(join(work,'tsconfig.json'),JSON.stringify({compilerOptions:{target:'ES2022',lib:['dom','esnext'],strict:true,
  noEmit:true,esModuleInterop:true,module:'esnext',moduleResolution:'bundler',jsx:'react-jsx',resolveJsonModule:true,
  allowJs:true,skipLibCheck:true,isolatedModules:true,baseUrl:'.',paths:{'@/*':['./*']}},include:['**/*.ts','**/*.tsx','.next/types/**/*.ts'],exclude:['node_modules']}));
 for(const name of sourceFiles.slice(0,3))await writeFile(join(work,'components',name.split('/').at(-1)),snapshots.get(name));
 await writeFile(join(work,'mocked-browser.mjs'),snapshots.get('tools/agent2/test_next_admin_multicharger_browser.mjs'));
 await writeFile(join(work,'history-browser.mjs'),snapshots.get('tools/agent2/test_admin_history_browser.mjs'));
 for(const name of ['ocpp-history-page.tsx','ocppHistoryEvidence.mjs','ocppHistoryEvidence.d.mts'])
  await writeFile(join(work,'app/ocpp-history',name==='ocpp-history-page.tsx'?'page.tsx':name),
    snapshots.get('integrations/hasone-next-admin/'+name));
 await writeFile(join(work,'app/layout.tsx'),`export default function Root({children}:{children:React.ReactNode}){return <html lang="en"><body>{children}</body></html>}`);
 const marker='agent2-fixture-'+createHash('sha256').update(work).digest('hex').slice(0,16);
 await writeFile(join(work,'app/login/page.tsx'),`export default function Login(){return <main>Synthetic test login ${marker}</main>}`);
 await writeFile(join(work,'lib/ThemeContext.tsx'),`"use client";export const useTheme=()=>({theme:"light"});`);
 await writeFile(join(work,'lib/api.ts'),`"use client";
async function request(path:string,body?:unknown,params?:Record<string,string|number>){
 const query=params?new URLSearchParams(Object.entries(params).map(([k,v])=>[k,String(v)])).toString():'';
 const res=await fetch('/api/v1'+path+(query?(path.includes('?')?'&':'?')+query:''),{method:body===undefined?'GET':'POST',headers:{
 'Content-Type':'application/json',Authorization:'Bearer '+(localStorage.getItem('admin_token')||'')},
 ...(body===undefined?{}:{body:JSON.stringify(body)})});
 const data=await res.json();if(!res.ok)throw Object.assign(new Error('Synthetic HTTP failure'),{response:{data,status:res.status}});
 return {data,status:res.status};
}
export default {get:(path:string,options?:{params?:Record<string,string|number>})=>request(path,undefined,options?.params),post:(path:string,body:unknown)=>request(path,body)};
`);
 await writeFile(join(work,'app/bookings/page.tsx'),`"use client";
import {useEffect,useState} from 'react';import {useRouter} from 'next/navigation';
import OcppBookingWorkflow from '@/components/OcppBookingWorkflow';import api from '@/lib/api';
type Booking={_id:string;stationId:string;stationName:string;status:string;isPaid:boolean;hasArrived:boolean};
export default function Bookings(){const router=useRouter();const [rows,setRows]=useState<Booking[]>([]);
 async function load(){const reply=await api.get('/admin/bookings');setRows(reply.data.data);}
 useEffect(()=>{if(!localStorage.getItem('admin_token')){router.replace('/login');return;}void load();},[router]);
 return <main><h1>Isolated component fixture</h1>{rows.map(b=><section key={b._id}><p>{b.stationName}</p>
 <OcppBookingWorkflow booking={b} onUpdated={load}/></section>)}</main>;
}
`);
 const build=await run('next-build',process.execPath,[join(deps,'node_modules/next/dist/bin/next'),'build','--webpack']);
 const types=await run('typescript',process.execPath,[join(deps,'node_modules/typescript/bin/tsc'),'--noEmit','--pretty','false']);
 const probe=createServer();await new Promise(done=>probe.listen(0,'127.0.0.1',done));const port=probe.address().port;
 await new Promise(done=>probe.close(done));
 server=spawn(process.execPath,[join(deps,'node_modules/next/dist/bin/next'),'start','--hostname','127.0.0.1','--port',String(port)],
  {cwd:work,env:{...process.env,NEXT_TELEMETRY_DISABLED:'1'},stdio:'ignore'});
 let ready=false;for(let n=0;n<100;n++){
  if(server.exitCode!==null||server.signalCode!==null)throw Error('Disposable Next server exited before readiness');
  try{const response=await fetch('http://127.0.0.1:'+port+'/login',{signal:AbortSignal.timeout(2000)});
    if(response.ok&&(await response.text()).includes(marker)){ready=true;break;}
  }catch{/* Only the disposable server marker can establish readiness. */}
  await new Promise(done=>setTimeout(done,100));
 }
 if(!ready)throw Error('Disposable loopback Next server did not start');
 const browser=await run('mocked-browser',process.execPath,[join(work,'mocked-browser.mjs'),'http://127.0.0.1:'+port]);
 const count=Number(browser.output.match(/STAGED_NEXT_ADMIN_BROWSER_TESTS_PASSED (\d+)/)?.[1]);
 if(!Number.isInteger(count)||count<21)throw Error('Expanded mocked-browser suite did not report all expected cases');
 const history=await run('history-browser',process.execPath,[join(work,'history-browser.mjs'),'http://127.0.0.1:'+port]);
 const historyCount=Number(history.output.match(/ADMIN_HISTORY_BROWSER_PASSED (\d+)/)?.[1]);
 if(!Number.isInteger(historyCount)||historyCount<12)throw Error('History browser suite did not report all expected cases');
 for(const [name,bytes] of snapshots)if(!(await readFile(join(repo,name))).equals(bytes))
  throw Error('Source changed during component verification: '+name);
 if(server.exitCode!==null||server.signalCode!==null)throw Error('Disposable Next server exited during browser checks');
 await writeFile(reportPath,JSON.stringify({status:'COMPONENT_FIXTURE_ONLY_PASS',source_blobs:blobs,versions,dependency_lock_sha256:createHash('sha256').update(pinnedLock).digest('hex'),
  results:{next_build:'passed',typescript:'passed',mocked_browser:count,history_browser:historyCount},logs_sha256:{
  next_build:build.sha256,typescript:types.sha256,mocked_browser:browser.sha256,history_browser:history.sha256},
  limits:['Synthetic shell, theme and HTTP adapter; actual shipped component/helper copied unchanged',
  'Not the purchased Admin application build or actual Express/Mongo/browser acceptance',
  'No physical chargers, production services or PSP actions']},null,2)+'\n',{mode:0o600});
 console.log('ADMIN_COMPONENT_FIXTURE_PASS '+count+' HISTORY '+historyCount);
}finally{
 if(server&&!server.killed&&server.exitCode===null&&server.signalCode===null){const exited=new Promise(done=>server.once('exit',done));server.kill('SIGTERM');await exited;}
 await rm(work,{recursive:true,force:true});
}
