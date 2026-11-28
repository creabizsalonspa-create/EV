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


test('operator review binds the exact occurrence and leaves the incident open in EN/AR',async()=>{
    for(const ar of [false,true]){
        const f=fixture(ar),d=report(),calls=[];
        const item={condition_key:'scheduler_stale',state:'open',opened_at:'2026-11-15T12:00:00Z',transition_revision:3,acknowledged_at:null};
        d.incidents={store_readable:true,monitoring_enabled:true,automatic_retry:false,external_alert_delivery:false,production_recovery_verified:false,open:[item],recent_events:[]};
        f.setActor({role:'admin'},async(...args)=>{
            calls.push(args);
            if(args[1]==='POST'){
                assert.equal(args[0],'admin/operational-health/acknowledge');
                assert.equal(args[2].condition_key,item.condition_key);
                assert.equal(args[2].transition_revision,3);
                item.acknowledged_at='2026-11-15T12:05:00Z';
                return {...args[2],acknowledged_at:item.acknowledged_at,incident_state:'open',external_actions:false};
            }
            return d;
        });
        click(f);await flush();
        const action=nodes(f.root,'button')[1];assert(action);
        await action.onclick();await flush();
        assert.equal(calls.length,3);
        assert(f.root.textContent.includes(ar?'الحادث ما زال مفتوحاً':'incident remains open'));
        assert.equal(nodes(f.root,'button').length,1);
        await action.onclick();assert.equal(calls.length,3);
    }
});
test('operator review rejects forged receipts and late callbacks after account change',async()=>{
    const f=fixture(),d=report(),calls=[];
    d.incidents={store_readable:true,monitoring_enabled:true,automatic_retry:false,external_alert_delivery:false,production_recovery_verified:false,open:[{condition_key:'scheduler_stale',state:'open',opened_at:'2026-11-15T12:00:00Z',transition_revision:1,acknowledged_at:null}],recent_events:[]};
    let release;
    f.setActor({role:'admin'},async(...args)=>{calls.push(args);if(args[1]==='POST')return new Promise(r=>release=r);return d;});
    click(f);await flush();
    const action=nodes(f.root,'button')[1],pending=action.onclick();
    release({condition_key:'scheduler_stale',transition_revision:2,incident_state:'open',external_actions:false,acknowledged_at:'2026-11-15T12:05:00Z'});
    await pending;await flush();assert(f.root.textContent.includes('Review not confirmed'));assert.equal(calls.length,2);
    click(f);await flush();
    const late=nodes(f.root,'button')[1].onclick();f.setActor(null,()=>{});
    release({condition_key:'scheduler_stale',transition_revision:1,incident_state:'open',external_actions:false,acknowledged_at:'2026-11-15T12:05:00Z'});
    await late;await flush();assert.equal(f.root.children.length,0);assert.equal(calls.length,4);
});

test('Admin can opt in for new inbox alerts explicitly and refreshes saved state', async () => {
    const local = fixture(), calls = [], data = report();
    data.incidents = {
        store_readable: true, monitoring_enabled: true, automatic_retry: false,
        external_alert_delivery: false, production_recovery_verified: false,
        inbox_alerts_enabled: false, open: [], recent_events: [],
    };
    local.setActor({role: 'admin'}, async (path, method, payload) => {
        calls.push([path, method]);
        if (method === 'PUT') {
            assert.equal(payload.enabled, true);
            data.incidents.inbox_alerts_enabled = true;
            return {enabled: true};
        }
        return data;
    });
    click(local);
    await flush();
    const enable = nodes(local.root, 'button').find(button => button.textContent === 'Turn on incident inbox alerts');
    assert(enable);
    await enable.onclick();
    assert.deepEqual(calls, [
        ['admin/operational-health', 'GET'], ['admin/operational-health/subscription', 'PUT'],
        ['admin/operational-health', 'GET'],
    ]);
    assert(local.root.textContent.includes('Turn off incident inbox alerts'));
});
