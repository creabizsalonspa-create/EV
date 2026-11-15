/* Read-only private process observations; no payload inspection or retry controls. */
window.EvAdminOperationalHealth = (() => {
    const root = document.getElementById('admin-operational-health');
    if (!root) return {setActor() {}};
    let revision = 0, sequence = 0, api = null, request = null, output = null, status = null, refresh = null;
    const n = (tag, en, ar = en) => {const e=document.createElement(tag);e.dataset.en=en;e.dataset.ar=ar;e.textContent=document.documentElement.lang==='ar'?ar:en;return e;};
    const plain = (tag, value) => {const e=document.createElement(tag);e.textContent=String(value);return e;};
    const states = {recent:['Recent observation at snapshot','ملاحظة حديثة عند القراءة'],stale:['Observation expired','انتهت صلاحية الملاحظة'],unknown:['Unknown','غير معروف'],disabled:['Monitoring disabled','المراقبة متوقفة']};
    const exact = x => Number.isSafeInteger(x) && x >= 0;
    function erase() {sequence++;request?.abort();request=null;output?.replaceChildren();if(refresh)refresh.disabled=false;}
    function clear() {revision++;erase();api=null;root.replaceChildren();}
    function valid(d) {
        if (d?.scope!=='default_queue_and_scheduler_observations' || typeof d.enabled!=='boolean' || typeof d.heartbeat_store_readable!=='boolean' || typeof d.as_of!=='string' || d.max_age_seconds!==150 ||
            d.all_workers_verified!==false || d.job_success_verified!==false || d.charger_connectivity_verified!==false || d.production_readiness_verified!==false || !Array.isArray(d.components) || d.components.length!==2) return false;
        if(new Set(d.components.map(c=>c.component)).size!==2 || !d.components.every(c=>['queue_worker','scheduler'].includes(c.component)&&Object.hasOwn(states,c.state)&&(c.observed_at===null||typeof c.observed_at==='string')))return false;
        const q=d.queue;if(!q||!['available','unavailable','unsupported','disabled'].includes(q.state))return false;
        const keys=['ready','reserved','delayed','failed','oldest_ready_age_seconds'];
        if(!d.enabled&&(q.state!=='disabled'||!d.components.every(c=>c.state==='disabled')))return false;
        return q.state==='available' ? keys.every(k=>q[k]===null||exact(q[k]))&&['ready','reserved','delayed'].every(k=>exact(q[k])) : keys.every(k=>q[k]===null);
    }
    async function load() {
        if(!api||document.hidden)return;erase();const own=revision, run=sequence, controller=new AbortController();request=controller;refresh.disabled=true;
        status.replaceChildren(n('span','Reading private process observations…','جارٍ قراءة ملاحظات العمليات الخاصة…'));
        try {
            const d=await api('admin/operational-health','GET',undefined,controller.signal);
            if(own!==revision||run!==sequence||controller.signal.aborted||document.hidden)return;
            if(!valid(d))throw Error('Unexpected health contract');
            status.replaceChildren(n('span','Snapshot loaded. Refresh for current observations.','تم تحميل اللقطة. حدّث لقراءة الملاحظات الحالية.'));
            output.append(plain('p',d.as_of),n('p',d.enabled?'Monitoring enabled':'Monitoring disabled by deployment configuration',d.enabled?'المراقبة مفعلة':'المراقبة متوقفة حسب إعداد النشر'));
            if(d.enabled&&!d.heartbeat_store_readable)output.append(n('p','Heartbeat storage is unavailable; observations remain unknown.','تخزين نبضات العمليات غير متاح؛ تبقى الملاحظات مجهولة.'));
            for(const c of d.components){const card=document.createElement('article');const label=c.component==='queue_worker'?['Configured queue worker','عامل قائمة الانتظار المضبوطة']:['Scheduler process','عملية الجدولة'];card.append(n('h4',...label),n('p',...states[c.state]));if(c.observed_at!==null)card.append(plain('p',c.observed_at));output.append(card);}
            const q=d.queue;output.append(n('h4','Default queue snapshot','لقطة قائمة الانتظار الافتراضية'));
            if(q.state!=='available')output.append(n('p','Queue counts unavailable for this snapshot or transport. Unknown is not zero.','أعداد قائمة الانتظار غير متاحة لهذه اللقطة أو وسيلة النقل. المجهول ليس صفراً.'));
            for(const [key,en,ar]of[['ready','Ready jobs','المهام الجاهزة'],['reserved','Reserved jobs','المهام المحجوزة'],['delayed','Delayed jobs','المهام المؤجلة'],['failed','Recorded failed jobs','المهام الفاشلة المسجلة'],['oldest_ready_age_seconds','Oldest ready job age (seconds)','عمر أقدم مهمة جاهزة (ثوانٍ)']]){const line=document.createElement('p');line.append(n('span',en+': ',ar+': '),q[key]===null?n('span','Unknown','غير معروف'):plain('span',q[key]));output.append(line);}
        } catch(error) {
            if(own===revision&&run===sequence&&!controller.signal.aborted){erase();status.replaceChildren(n('span','Process observations could not load. Refresh explicitly to retry.','تعذر تحميل ملاحظات العمليات. حدّث صراحة للمحاولة.'));}
        } finally {if(own===revision&&run===sequence)refresh.disabled=false;}
    }
    function setActor(actor, requestApi) {
        clear();if(actor?.role!=='admin')return;api=requestApi;
        root.append(n('p','Read-only snapshots of the configured default queue and scheduler. A recent process heartbeat does not prove successful jobs, all workers, charger connectivity or production readiness. No job contents are displayed and no retries are dispatched.',
            'لقطات للقراءة فقط لقائمة الانتظار الافتراضية والجدولة. لا تثبت نبضة حديثة نجاح المهام أو جميع العاملين أو اتصال الشواحن أو جاهزية الإنتاج. لا تُعرض محتويات المهام ولا تُرسل إعادة محاولات.'));
        refresh=n('button','Refresh process observations','تحديث ملاحظات العمليات');refresh.type='button';refresh.onclick=load;
        status=document.createElement('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');output=document.createElement('div');root.append(refresh,status,output);
    }
    document.addEventListener('visibilitychange',()=>{if(document.hidden&&api){erase();status.replaceChildren(n('span','Private snapshot cleared. Refresh when ready.','تم مسح اللقطة الخاصة. حدّث عندما تكون مستعداً.'));}});
    window.addEventListener('pagehide',clear);
    return {setActor};
})();
