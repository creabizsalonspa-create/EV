'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
class Element{constructor(tag){this.tag=tag;this.children=[];this.dataset={};this._text='';this.disabled=false;}set textContent(v){this._text=String(v);this.children=[];}get textContent(){return this._text+this.children.map(c=>c.textContent).join('');}append(...c){this.children.push(...c);}replaceChildren(...c){this._text='';this.children=c;}setAttribute(){}}
const nodes=(r,tag)=>[...(r.tag===tag?[r]:[]),...r.children.flatMap(c=>nodes(c,tag))],flush=()=>new Promise(r=>setImmediate(r));
function fixture(ar=false){const root=new Element('div'),events={},windowEvents={};const document={hidden:false,documentElement:{lang:ar?'ar':'en'},getElementById:()=>root,createElement:tag=>new Element(tag),addEventListener:(n,f)=>events[n]=f};const window={addEventListener:(n,f)=>windowEvents[n]=f};vm.runInNewContext(fs.readFileSync('services/business-api/public/admin-operational-health.js','utf8'),{window,document,AbortController});return{root,events,windowEvents,document,setActor:window.EvAdminOperationalHealth.setActor};}
const report=()=>({scope:'default_queue_and_scheduler_observations',as_of:'2026-11-14T00:00:00Z',enabled:true,heartbeat_store_readable:true,max_age_seconds:150,all_workers_verified:false,job_success_verified:false,charger_connectivity_verified:false,production_readiness_verified:false,components:[{component:'queue_worker',state:'unknown',observed_at:null},{component:'scheduler',state:'recent',observed_at:'2026-11-14T00:00:00Z'}],queue:{state:'available',ready:3,reserved:1,delayed:2,failed:null,oldest_ready_age_seconds:30}});
const click=f=>nodes(f.root,'button')[0].onclick();
test('Admin observations require explicit read and remain GET-only in both languages',async()=>{for(const ar of[false,true]){const f=fixture(ar),calls=[];const api=async(...args)=>{calls.push(args);return report();};f.setActor({role:'customer'},api);assert.equal(f.root.children.length,0);f.setActor({role:'admin'},api);assert.equal(calls.length,0);click(f);await flush();assert.equal(calls[0][0],'admin/operational-health');assert.equal(calls[0][1],'GET');assert.equal(nodes(f.root,'article').length,2);assert(f.root.textContent.includes(ar?'غير معروف':'Unknown'));assert(f.root.textContent.includes(ar?'2026-11-14':'Recent observation at snapshot'));}});
test('unknown queue transport never displays a manufactured zero',async()=>{const f=fixture(),d=report();d.queue={state:'unsupported',ready:null,reserved:null,delayed:null,failed:null,oldest_ready_age_seconds:null};f.setActor({role:'admin'},async()=>d);click(f);await flush();assert(f.root.textContent.includes('Unknown is not zero'));assert(!f.root.textContent.includes('Ready jobs: 0'));});
test('late responses and errors cannot cross an account change',async()=>{const f=fixture();let resolve;f.setActor({role:'admin'},()=>new Promise(r=>resolve=r));click(f);f.setActor(null,()=>{});resolve(report());await flush();assert.equal(f.root.children.length,0);});
test('background clears private snapshots and foreground does not silently read again',async()=>{const f=fixture();let reads=0;f.setActor({role:'admin'},async()=>{reads++;return report();});click(f);await flush();assert.equal(nodes(f.root,'article').length,2);f.document.hidden=true;f.events.visibilitychange();assert.equal(nodes(f.root,'article').length,0);f.document.hidden=false;f.events.visibilitychange();await flush();assert.equal(reads,1);});
test('promoted readiness, duplicate components and invalid counts fail closed without raw errors',async()=>{for(const change of[d=>d.job_success_verified=true,d=>d.components[1].component='queue_worker',d=>d.queue.ready=9007199254740992,d=>{d.queue.state='unavailable';}]){const f=fixture(),d=report();change(d);f.setActor({role:'admin'},async()=>d);click(f);await flush();assert.equal(nodes(f.root,'article').length,0);assert(f.root.textContent.includes('could not load'));}const f=fixture();f.setActor({role:'admin'},async()=>{throw Error('PRIVATE-DB-PASSWORD');});click(f);await flush();assert(!f.root.textContent.includes('PRIVATE'));});

test('Admin incident timeline is read-only, localized and excludes payload injection',async()=>{for(const ar of[false,true]){const f=fixture(ar),calls=[],d=report();d.incidents={store_readable:true,monitoring_enabled:true,automatic_retry:false,external_alert_delivery:false,production_recovery_verified:false,open:[{condition_key:'queue_worker_stale',state:'open',opened_at:'2026-11-15T12:00:00Z'}],recent_events:[{id:1,condition_key:'queue_worker_stale',transition:'opened',observed_at:'2026-11-15T12:00:00Z'}]};f.setActor({role:'admin'},async(...args)=>{calls.push(args);return d;});click(f);await flush();assert(f.root.textContent.includes(ar?'السجل الزمني':'Operational incident timeline'));assert(f.root.textContent.includes(ar?'انتهت صلاحية':'observation expired'));assert.equal(calls.length,1);assert.equal(calls[0][1],'GET');assert(!f.root.textContent.includes('SECRET'));}}
);
test('Incident scanner state is localized, read-only and rejects fabricated freshness',async()=>{
    for(const ar of [false,true]){
        const f=fixture(ar),d=report();
        d.incidents={store_readable:true,monitoring_enabled:true,automatic_retry:false,external_alert_delivery:false,production_recovery_verified:false,
            scanner:{state:'stale',last_completed_at:'2026-11-15T12:00:00Z',max_age_seconds:150},open:[],recent_events:[]};
        f.setActor({role:'admin'},async()=>d);click(f);await flush();
        assert(f.root.textContent.includes(ar?'اكتمال فحص الحوادث':'Incident scanner completion'));
        assert(f.root.textContent.includes(ar?'نبضة المجدول':'scheduler heartbeat alone'));
        assert.equal(nodes(f.root,'button').length,1);
        d.incidents.scanner={state:'recent',last_completed_at:null,max_age_seconds:150};
        click(f);await flush();assert(f.root.textContent.includes(ar?'تعذر تحميل':'could not load'));
    }
});
test('Invalid incident provenance and fabricated delivery claims fail closed',async()=>{for(const mutate of[d=>d.incidents.external_alert_delivery=true,d=>d.incidents.open[0].condition_key='SECRET_PRIVATE_JOB',d=>d.incidents.recent_events[0].transition='sent']){const f=fixture(),d=report();d.incidents={store_readable:true,monitoring_enabled:true,automatic_retry:false,external_alert_delivery:false,production_recovery_verified:false,open:[{condition_key:'scheduler_stale',state:'open',opened_at:'2026-11-15Z'}],recent_events:[{id:1,condition_key:'scheduler_stale',transition:'opened',observed_at:'2026-11-15Z'}]};mutate(d);f.setActor({role:'admin'},async()=>d);click(f);await flush();assert(f.root.textContent.includes('could not load'));assert(!f.root.textContent.includes('SECRET_PRIVATE_JOB'));}});

test('Email handoff uncertainty is counted without rendering recipients or auto-resend controls',async()=>{
    for(const ar of [false,true]){
        const f=fixture(ar),d=report(),calls=[];
        d.email_handoff={state:'available',outcome_unknown:2,stale_claimed:1,age_threshold_seconds:900,delivery_confirmed:false,automatic_retry:false};
        d.incidents={store_readable:true,monitoring_enabled:true,automatic_retry:false,external_alert_delivery:false,production_recovery_verified:false,
            open:[{condition_key:'email_handoff_uncertain',state:'open',opened_at:'2026-11-15T12:00:00Z'}],
            recent_events:[{id:4,condition_key:'email_handoff_uncertain',transition:'opened',observed_at:'2026-11-15T12:00:00Z'}]};
        f.setActor({role:'admin'},async(...args)=>{calls.push(args);return d;});click(f);await flush();
        assert(f.root.textContent.includes(ar?'مراجعة تسليم البريد':'Email handoff review'));
        assert(f.root.textContent.includes(ar?'غير المؤكد':'Uncertain email handoff'));
        assert.equal(calls.length,1);assert.equal(calls[0][1],'GET');assert.equal(nodes(f.root,'button').length,1);
    }
});
test('Overdue queued email receipts are localized and never imply external send',async()=>{
    for(const ar of [false,true]){
        const f=fixture(ar),d=report();
        d.email_handoff={state:'available',outcome_unknown:0,stale_claimed:0,overdue_queued:2,
            age_threshold_seconds:900,delivery_confirmed:false,automatic_retry:false};
        d.incidents={store_readable:true,monitoring_enabled:true,automatic_retry:false,external_alert_delivery:false,
            production_recovery_verified:false,scanner:{state:'recent',last_completed_at:'2026-11-15T12:00:00Z',max_age_seconds:150},
            open:[{condition_key:'email_queue_backlog',state:'open',opened_at:'2026-11-15T12:00:00Z'}],
            recent_events:[{id:9,condition_key:'email_queue_backlog',transition:'opened',observed_at:'2026-11-15T12:00:00Z'}]};
        f.setActor({role:'admin'},async()=>d);click(f);await flush();
        assert(f.root.textContent.includes(ar?'انتظار لأكثر من 15 دقيقة':'Queued for more than 15 minutes'));
        assert(f.root.textContent.includes(ar?'تنبيهات البريد':'Email notices still queued'));
        assert.equal(nodes(f.root,'button').length,1);
        d.email_handoff.state='unavailable';d.email_handoff.outcome_unknown=null;d.email_handoff.stale_claimed=null;d.email_handoff.overdue_queued=2;
        click(f);await flush();assert(f.root.textContent.includes(ar?'تعذر تحميل':'could not load'));
    }
});
test('Untrusted email handoff metrics and fabricated delivery claims fail closed',async()=>{
    for(const mutate of [d=>d.email_handoff.delivery_confirmed=true,d=>d.email_handoff.stale_claimed=-1,d=>{d.email_handoff.state='unavailable';}]){
        const f=fixture(),d=report();d.email_handoff={state:'available',outcome_unknown:0,stale_claimed:0,age_threshold_seconds:900,delivery_confirmed:false,automatic_retry:false};
        mutate(d);f.setActor({role:'admin'},async()=>d);click(f);await flush();
        assert(f.root.textContent.includes('could not load'));
    }
});

test('resolved incident state remains visible beyond the bounded recent timeline', async () => {
    for (const ar of [false, true]) {
        const locale = fixture(ar);
        const data = report();
        data.incidents = {
            store_readable: true,
            monitoring_enabled: true,
            automatic_retry: false,
            external_alert_delivery: false,
            production_recovery_verified: false,
            open: [],
            resolved: [{
                condition_key: 'scheduler_stale',
                state: 'resolved',
                opened_at: '2026-11-15T12:00:00Z',
                resolved_at: '2026-11-15T12:30:00Z',
            }],
            recent_events: [],
        };
        locale.setActor({role: 'admin'}, async () => data);
        click(locale);
        await flush();
        assert(locale.root.textContent.includes(ar ? 'الحوادث التي تم حلها' : 'Resolved incidents'));
        assert(locale.root.textContent.includes('2026-11-15T12:30:00Z'));
    }
});

const faultRow=(id,state='recorded_open')=>({
    alert_id:String(id),state,opened_at:'2026-11-14T10:00:00.000000Z',
    closed_at:state==='recorded_closed'?'2026-11-14T11:00:00.000000Z':null,
    resolution_source:state==='recorded_closed'?'operator_acknowledged':null,physical_verification:false,
});
const faultPage=(data,next_before=null,store_readable=true)=>({
    scope:'ASSIGNED_SIMULATOR_ESCALATIONS',current_fault_state_verified:false,physical_verification:false,
    store_readable,data,next_before,
});
test('Assigned escalation history localizes exact IDs, historical closure and exclusive cursor navigation',async()=>{
    for(const ar of [false,true]){
        const f=fixture(ar),calls=[],d=report();
        d.fault_escalations=faultPage([faultRow('9007199254740993','recorded_closed'),...Array.from({length:24},(_,i)=>faultRow(100-i))],'77');
        f.setActor({role:'admin'},async(...args)=>{calls.push(args);return d;});
        click(f);await flush();
        assert(f.root.textContent.includes('9007199254740993'));
        assert(f.root.textContent.includes(ar?'ليس دليلاً على الإصلاح':'not proof of repair'));
        assert(f.root.textContent.includes(ar?'سجل تصعيد أعطال المحاكي':'Assigned simulator fault escalation history'));
        const more=nodes(f.root,'button')[1];
        d.fault_escalations=faultPage([faultRow(76)]);
        more.onclick();await flush();
        assert.equal(calls[1][0],'admin/operational-health?fault_before=77');
        assert.equal(calls[1][1],'GET');
        assert(!f.root.textContent.includes('9007199254740993'));
        assert(f.root.textContent.includes(ar?'العودة إلى أحدث':'Return to latest'));
        d.fault_escalations=faultPage([]);
        nodes(f.root,'button')[1].onclick();await flush();
        assert.equal(calls[2][0],'admin/operational-health');
        assert(f.root.textContent.includes(ar?'لا توجد سجلات تصعيد':'No assigned escalation receipts'));
    }
});
test('Malformed escalation authority, closure and cursor contracts clear the entire private snapshot',async()=>{
    for(const mutate of [
        e=>e.physical_verification=true,
        e=>e.current_fault_state_verified=true,
        e=>e.data[0].alert_id=9007199254740993,
        e=>e.data[0].resolution_source='physical_repair',
        e=>e.data[0].closed_at='2026-11-14T09:00:00.000000Z',
        e=>e.data.push(faultRow('6')),
        e=>e.next_before='5',
        e=>e.store_readable=false,
    ]){
        const f=fixture(),d=report();
        d.fault_escalations=faultPage([faultRow('5','recorded_closed')]);mutate(d.fault_escalations);
        f.setActor({role:'admin'},async()=>d);click(f);await flush();
        assert(f.root.textContent.includes('could not load'));
        assert(!f.root.textContent.includes('physical_repair'));
        assert.equal(nodes(f.root,'article').length,0);
    }
    const f=fixture(),d=report();d.fault_escalations=faultPage([],null,false);
    f.setActor({role:'admin'},async()=>d);click(f);await flush();
    assert(f.root.textContent.includes('Escalation history unavailable'));
    assert(!f.root.textContent.includes('No assigned escalation receipts'));
});
test('A delayed older escalation page cannot survive a role change or backgrounding',async()=>{
    for(const hide of [false,true]){
        const f=fixture(),d=report();d.fault_escalations=faultPage(Array.from({length:25},(_,i)=>faultRow(100-i)),'76');
        let resolve;
        f.setActor({role:'admin'},async path=>path.includes('?')?new Promise(r=>resolve=r):d);
        click(f);await flush();nodes(f.root,'button')[1].onclick();await flush();
        if(hide){f.document.hidden=true;f.events.visibilitychange();}
        else f.setActor({role:'vendor'},async()=>d);
        resolve({...d,fault_escalations:faultPage([faultRow(75)])});await flush();
        assert.equal(nodes(f.root,'article').length,0);
        assert(!f.root.textContent.includes('Recorded open escalation'));
    }
});
