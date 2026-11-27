/* Read-only private process observations; no payload inspection or retry controls. */
window.EvAdminOperationalHealth = (() => {
    const root = document.getElementById('admin-operational-health');
    if (!root) return {setActor() {}};
    let revision = 0, sequence = 0, api = null, request = null, output = null, status = null, refresh = null;
    const n = (tag, en, ar = en) => {const e=document.createElement(tag);e.dataset.en=en;e.dataset.ar=ar;e.textContent=document.documentElement.lang==='ar'?ar:en;return e;};
    const plain = (tag, value) => {const e=document.createElement(tag);e.textContent=String(value);return e;};
    const states = {recent:['Recent observation at snapshot','ملاحظة حديثة عند القراءة'],stale:['Observation expired','انتهت صلاحية الملاحظة'],unknown:['Unknown','غير معروف'],disabled:['Monitoring disabled','المراقبة متوقفة']};
    const exact = x => Number.isSafeInteger(x) && x >= 0;
    const incidentNames = {queue_worker_stale:['Queue worker observation expired','انتهت صلاحية ملاحظة عامل قائمة الانتظار'],scheduler_stale:['Scheduler observation expired','انتهت صلاحية ملاحظة المجدول'],queue_backlog:['Ready queue is backed up','تراكم المهام الجاهزة'],queue_failed_jobs:['Recorded queue failures','إخفاقات مسجلة في قائمة الانتظار'],email_handoff_uncertain:['Uncertain email handoff needs operator review','تسليم البريد الإلكتروني غير المؤكد يحتاج إلى مراجعة المشغل'],email_queue_backlog:['Email notices still queued past review threshold','تنبيهات البريد ما زالت قيد الانتظار بعد مهلة المراجعة']};
    function erase() {sequence++;request?.abort();request=null;output?.replaceChildren();if(refresh)refresh.disabled=false;}
    function clear() {revision++;erase();api=null;root.replaceChildren();}
    const decimalId = x => typeof x==='string' && /^[1-9][0-9]{0,18}$/.test(x) && (x.length<19 || x<='9223372036854775807');
    const earlierId = (a,b) => a.length<b.length || (a.length===b.length && a<b);
    const receiptTime = x => typeof x==='string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(x) && Number.isFinite(Date.parse(x));
    const escalationStates = {recorded_open:['Recorded open escalation','تصعيد مفتوح مسجل'],recorded_closed:['Recorded escalation closure','إغلاق تصعيد مسجل'],requires_review:['Historical receipt needs review','السجل التاريخي يحتاج إلى مراجعة']};
    const escalationReasons = {operator_acknowledged:['Operator acknowledged; not proof of repair','أقر المشغل بالتنبيه؛ ليس دليلاً على الإصلاح'],source_recovered:['Source recovery was recorded; not current clearance','تم تسجيل تعافٍ من المصدر؛ ليس دليلاً على الحالة الحالية']};
    function validEscalations(e,before=null) {
        if(!e || e.scope!=='ASSIGNED_SIMULATOR_ESCALATIONS' || e.current_fault_state_verified!==false || e.physical_verification!==false ||
            typeof e.store_readable!=='boolean' || !Array.isArray(e.data) || e.data.length>25 ||
            (e.next_before!==null && (!decimalId(e.next_before)||e.data.length!==25||e.next_before!==e.data.at(-1)?.alert_id)))return false;
        if(!e.store_readable)return e.data.length===0 && e.next_before===null;
        return e.data.every((v,i)=>{
            if(!v||!decimalId(v.alert_id)|| (i>0&&!earlierId(v.alert_id,e.data[i-1].alert_id)) ||
                (before!==null&&!earlierId(v.alert_id,before)) || !Object.hasOwn(escalationStates,v.state) || v.physical_verification!==false ||
                (v.opened_at!==null&&!receiptTime(v.opened_at)))return false;
            if(v.state==='recorded_closed')return receiptTime(v.opened_at)&&receiptTime(v.closed_at)&&v.closed_at>=v.opened_at&&Object.hasOwn(escalationReasons,v.resolution_source);
            return v.closed_at===null&&v.resolution_source===null&&(v.state!=='recorded_open'||receiptTime(v.opened_at));
        });
    }
    function valid(d,before=null) {
        if (d?.scope!=='default_queue_and_scheduler_observations' || typeof d.enabled!=='boolean' || typeof d.heartbeat_store_readable!=='boolean' || typeof d.as_of!=='string' || d.max_age_seconds!==150 ||
            d.all_workers_verified!==false || d.job_success_verified!==false || d.charger_connectivity_verified!==false || d.production_readiness_verified!==false || !Array.isArray(d.components) || d.components.length!==2) return false;
        if(new Set(d.components.map(c=>c.component)).size!==2 || !d.components.every(c=>['queue_worker','scheduler'].includes(c.component)&&Object.hasOwn(states,c.state)&&(c.observed_at===null||typeof c.observed_at==='string')))return false;
        const q=d.queue;if(!q||!['available','unavailable','unsupported','disabled'].includes(q.state))return false;
        const keys=['ready','reserved','delayed','failed','oldest_ready_age_seconds'];
        if(!d.enabled&&(q.state!=='disabled'||!d.components.every(c=>c.state==='disabled')))return false;
        const validQueue=q.state==='available' ? keys.every(k=>q[k]===null||exact(q[k]))&&['ready','reserved','delayed'].every(k=>exact(q[k])) : keys.every(k=>q[k]===null);
        const i=d.incidents;
        if(i!==undefined) {
            if(!i || typeof i.store_readable!=='boolean' || typeof i.monitoring_enabled!=='boolean' || i.automatic_retry!==false || i.external_alert_delivery!==false || i.production_recovery_verified!==false || !Array.isArray(i.open) || !Array.isArray(i.recent_events) || i.open.length>6 || i.recent_events.length>20) return false;
            if(!i.open.every(v=>Object.hasOwn(incidentNames,v.condition_key)&&v.state==='open'&&typeof v.opened_at==='string'))return false;
            const resolved = i.resolved === undefined ? [] : i.resolved;
            if (!Array.isArray(resolved) || i.open.length + resolved.length > 6 ||
                !resolved.every(v => v && Object.hasOwn(incidentNames, v.condition_key) && v.state === 'resolved' && typeof v.opened_at === 'string' && typeof v.resolved_at === 'string') ||
                new Set([...i.open, ...resolved].map(v => v.condition_key)).size !== i.open.length + resolved.length) return false;
            if(!i.recent_events.every(v=>exact(v.id)&&Object.hasOwn(incidentNames,v.condition_key)&&['opened','resolved'].includes(v.transition)&&typeof v.observed_at==='string'))return false;
            if(i.scanner!==undefined && (!i.scanner || !['recent','stale','unknown'].includes(i.scanner.state) || i.scanner.max_age_seconds!==150 ||
                (i.scanner.state==='unknown')!==(i.scanner.last_completed_at===null) ||
                (i.scanner.last_completed_at!==null&&typeof i.scanner.last_completed_at!=='string')))return false;
        }
        const e=d.email_handoff;
        if(e!==undefined && (!e || !['available','unavailable'].includes(e.state) || e.age_threshold_seconds!==900 ||
            e.delivery_confirmed!==false || e.automatic_retry!==false || (e.state==='available' ?
            !exact(e.outcome_unknown)||!exact(e.stale_claimed) : e.outcome_unknown!==null||e.stale_claimed!==null)))return false;
        if(e!==undefined && e.overdue_queued!==undefined && (e.state==='available'?!exact(e.overdue_queued):e.overdue_queued!==null))return false;
        if(d.fault_escalations!==undefined&&!validEscalations(d.fault_escalations,before))return false;
        return validQueue;
    }
    async function load(before=null) {
        if(!api||document.hidden)return;erase();const own=revision, run=sequence, controller=new AbortController();request=controller;refresh.disabled=true;
        status.replaceChildren(n('span','Reading private process observations…','جارٍ قراءة ملاحظات العمليات الخاصة…'));
        try {
            const d=await api('admin/operational-health'+(before===null?'':'?fault_before='+encodeURIComponent(before)),'GET',undefined,controller.signal);
            if(own!==revision||run!==sequence||controller.signal.aborted||document.hidden)return;
            if(!valid(d,before))throw Error('Unexpected health contract');
            status.replaceChildren(n('span','Snapshot loaded. Refresh for current observations.','تم تحميل اللقطة. حدّث لقراءة الملاحظات الحالية.'));
            output.append(plain('p',d.as_of),n('p',d.enabled?'Monitoring enabled':'Monitoring disabled by deployment configuration',d.enabled?'المراقبة مفعلة':'المراقبة متوقفة حسب إعداد النشر'));
            if(d.enabled&&!d.heartbeat_store_readable)output.append(n('p','Heartbeat storage is unavailable; observations remain unknown.','تخزين نبضات العمليات غير متاح؛ تبقى الملاحظات مجهولة.'));
            for(const c of d.components){const card=document.createElement('article');const label=c.component==='queue_worker'?['Configured queue worker','عامل قائمة الانتظار المضبوطة']:['Scheduler process','عملية الجدولة'];card.append(n('h4',...label),n('p',...states[c.state]));if(c.observed_at!==null)card.append(plain('p',c.observed_at));output.append(card);}
            const q=d.queue;output.append(n('h4','Default queue snapshot','لقطة قائمة الانتظار الافتراضية'));
            if(q.state!=='available')output.append(n('p','Queue counts unavailable for this snapshot or transport. Unknown is not zero.','أعداد قائمة الانتظار غير متاحة لهذه اللقطة أو وسيلة النقل. المجهول ليس صفراً.'));
            for(const [key,en,ar]of[['ready','Ready jobs','المهام الجاهزة'],['reserved','Reserved jobs','المهام المحجوزة'],['delayed','Delayed jobs','المهام المؤجلة'],['failed','Recorded failed jobs','المهام الفاشلة المسجلة'],['oldest_ready_age_seconds','Oldest ready job age (seconds)','عمر أقدم مهمة جاهزة (ثوانٍ)']]){const line=document.createElement('p');line.append(n('span',en+': ',ar+': '),q[key]===null?n('span','Unknown','غير معروف'):plain('span',q[key]));output.append(line);}
            if(d.email_handoff) {
                output.append(n('h4','Email handoff review (no automatic resend)','مراجعة تسليم البريد الإلكتروني (دون إعادة إرسال تلقائي)'));
                const e=d.email_handoff;
                if(e.state!=='available')output.append(n('p','Email handoff receipts unavailable. Unknown is not zero.','سجلات تسليم البريد غير متاحة. المجهول ليس صفراً.'));
                else for(const [key,en,ar] of [['outcome_unknown','Unknown transport outcomes','نتائج نقل غير مؤكدة'],['stale_claimed','Claims awaiting review (15+ minutes)','مطالبات تنتظر المراجعة (15 دقيقة فأكثر)'],...(e.overdue_queued!==undefined?[['overdue_queued','Queued for more than 15 minutes (not sent)','انتظار لأكثر من 15 دقيقة (لم تُرسل)']]:[])]) {
                    const line=document.createElement('p');line.append(n('span',en+': ',ar+': '),plain('span',e[key]));output.append(line);
                }
                output.append(n('p','Handoff never confirms mailbox delivery. No automatic retries occur.','تسليم الرسالة إلى الناقل لا يؤكد وصولها للبريد. لا تحدث إعادات إرسال تلقائية.'));
            }
            if(d.incidents) {
                const i=d.incidents;output.append(n('h4','Operational incident timeline (read-only)','السجل الزمني للحوادث التشغيلية (للقراءة فقط)'));
                output.append(n('p','Conditions are derived from local observations only. No external alert was delivered or command retried.','الحالات مستمدة من الملاحظات المحلية فقط. لم يُرسل تنبيه خارجي ولم تُعَد أي أوامر.'));
                if(i.scanner){output.append(n('h5','Incident scanner completion','اكتمال فحص الحوادث'),n('p',...states[i.scanner.state]));
                    if(i.scanner.last_completed_at!==null)output.append(plain('p',i.scanner.last_completed_at));
                    if(i.scanner.state!=='recent')output.append(n('p','No fresh committed scan; scheduler heartbeat alone is insufficient.','لا يوجد فحص مكتمل حديث؛ نبضة المجدول وحدها غير كافية.'));
                }
                if(!i.store_readable)output.append(n('p','Incident history unavailable; do not assume there are no incidents.','سجل الحوادث غير متاح؛ لا تفترض عدم وجود حوادث.'));
                else {
                    if(!i.open.length)output.append(n('p','No currently recorded open incidents. Unknown monitoring is not proof of health.','لا توجد حوادث مفتوحة مسجلة حالياً. عدم معرفة حالة المراقبة ليس دليلاً على السلامة.'));
                    for(const item of i.open){const a=document.createElement('article');a.append(n('h5',...incidentNames[item.condition_key]),n('p','Open / مفتوح','مفتوح / Open'),plain('p',item.opened_at));output.append(a);}
                    if (i.resolved?.length) {
                        output.append(n('h5', 'Resolved incidents', 'الحوادث التي تم حلها'));
                        for (const item of i.resolved) {
                            const row = document.createElement('article');
                            row.append(n('p', ...incidentNames[item.condition_key]), plain('time', item.resolved_at));
                            output.append(row);
                        }
                    }
                    output.append(n('h5','Recent incident transitions','التغييرات الأخيرة في الحوادث'));
                    for(const event of i.recent_events){const a=document.createElement('article');a.append(n('p',...incidentNames[event.condition_key]),n('p',event.transition==='opened'?'Opened':'Resolved',event.transition==='opened'?'فُتحت':'تم حلها'),plain('p',event.observed_at));output.append(a);}
                }
            }
            if(d.fault_escalations) {
                const e=d.fault_escalations;
                output.append(n('h4','Assigned simulator fault escalation history','سجل تصعيد أعطال المحاكي المعينة'));
                output.append(n('p','Historical records only. Closure is not proof of present charger availability, repair, or physical recovery.','سجلات تاريخية فقط. الإغلاق لا يثبت توفر الشاحن حالياً أو إصلاحه أو تعافيه المادي.'));
                if(!e.store_readable)output.append(n('p','Escalation history unavailable; do not assume there are no incidents.','سجل التصعيد غير متاح؛ لا تفترض عدم وجود حوادث.'));
                else {
                    if(!e.data.length)output.append(n('p','No assigned escalation receipts on this page.','لا توجد سجلات تصعيد معينة في هذه الصفحة.'));
                    for(const v of e.data){
                        const card=document.createElement('article');
                        card.append(n('h5','Alert reference','مرجع التنبيه'),plain('p',v.alert_id),n('p',...escalationStates[v.state]));
                        if(v.opened_at!==null)card.append(n('p','Opened (UTC)','فتح (UTC)'),plain('time',v.opened_at));
                        if(v.closed_at!==null)card.append(n('p','Closure recorded (UTC)','سجل الإغلاق (UTC)'),plain('time',v.closed_at),n('p',...escalationReasons[v.resolution_source]));
                        output.append(card);
                    }
                    if(e.next_before!==null){
                        const more=n('button','Older escalation receipts','سجلات التصعيد الأقدم');more.type='button';
                        more.onclick=()=>load(e.next_before);output.append(more);
                    }
                    if(before!==null){const latest=n('button','Return to latest escalation receipts','العودة إلى أحدث سجلات التصعيد');latest.type='button';latest.onclick=()=>load();output.append(latest);}
                }
            }
        } catch(error) {
            if(own===revision&&run===sequence&&!controller.signal.aborted){erase();status.replaceChildren(n('span','Process observations could not load. Refresh explicitly to retry.','تعذر تحميل ملاحظات العمليات. حدّث صراحة للمحاولة.'));}
        } finally {if(own===revision&&run===sequence)refresh.disabled=false;}
    }
    function setActor(actor, requestApi) {
        clear();if(actor?.role!=='admin')return;api=requestApi;
        root.append(n('p','Read-only snapshots of the configured default queue and scheduler. A recent process heartbeat does not prove successful jobs, all workers, charger connectivity or production readiness. No job contents are displayed and no retries are dispatched.',
            'لقطات للقراءة فقط لقائمة الانتظار الافتراضية والجدولة. لا تثبت نبضة حديثة نجاح المهام أو جميع العاملين أو اتصال الشواحن أو جاهزية الإنتاج. لا تُعرض محتويات المهام ولا تُرسل إعادة محاولات.'));
        refresh=n('button','Refresh process observations','تحديث ملاحظات العمليات');refresh.type='button';refresh.onclick=()=>load();
        status=document.createElement('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');output=document.createElement('div');root.append(refresh,status,output);
    }
    document.addEventListener('visibilitychange',()=>{if(document.hidden&&api){erase();status.replaceChildren(n('span','Private snapshot cleared. Refresh when ready.','تم مسح اللقطة الخاصة. حدّث عندما تكون مستعداً.'));}});
    window.addEventListener('pagehide',clear);
    return {setActor};
})();
