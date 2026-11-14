/* Explicit owner polling consent; never a charging or financial command. */
window.EvOcpiSyncPlans = {
    async open({host, providerId, api, current}) {
        const root = document.createElement('section'); host.replaceChildren(root);
        let sequence = 0, busy = false;
        const t = (en, ar) => document.documentElement.lang === 'ar' ? ar : en;
        const node = (tag, value) => { const n = document.createElement(tag); if (value !== undefined) n.textContent = value; return n; };
        const valid = n => root.isConnected && current() && !document.hidden && n === sequence;
        const base = `ocpi/providers/${providerId}/sync-plans`;
        const modules = () => ({locations:t('Stations','المحطات'),tariffs:t('Tariffs','التعرفات'),sessions:t('Partner sessions','جلسات الشريك'),cdrs:t('Charge detail records','سجلات تفاصيل الشحن')});
        const state = value => ({claimed:t('Reading partner','قراءة الشريك'),completed:t('Reports synchronized','تمت مزامنة التقارير'),failed:t('Read failed; review partner','فشلت القراءة؛ راجع الشريك'),cancelled:t('Cancelled after consent or authority changed','ألغي بعد تغيير الموافقة أو الصلاحية'),interrupted:t('Interrupted; review before enabling','انقطع؛ راجع قبل التفعيل')}[value] || t('Review required','تلزم المراجعة'));
        const stopped = value => ({operator_disabled:t('Disabled by an operator','عطله المشغّل'),consent_or_partner_changed:t('Approval or partner configuration changed. Review and renew consent.','تغيرت الموافقة أو إعدادات الشريك. راجع وجدد الموافقة.'),interrupted_poll:t('Interrupted poll. Review the earlier run before enabling.','انقطعت القراءة. راجع العملية السابقة قبل التفعيل.')}[value] || t('Review required','تلزم المراجعة'));
        const counts = value => {
            if (value == null) return '';
            try {
                const c = typeof value === 'string' ? JSON.parse(value) : value;
                const keys = ['created','updated','duplicate','stale'];
                if (!keys.every(k => Number.isSafeInteger(c[k]) && c[k] >= 0)) return t('Counts unavailable','الأعداد غير متاحة');
                return t(`Created ${c.created} · updated ${c.updated} · unchanged ${c.duplicate} · stale skipped ${c.stale}`, `جديد ${c.created} · محدث ${c.updated} · دون تغيير ${c.duplicate} · قديم متجاوز ${c.stale}`);
            } catch { return t('Counts unavailable','الأعداد غير متاحة'); }
        };
        function button(parent, label, action) {
            const b = node('button', label); b.type = 'button'; parent.append(b);
            b.onclick = async () => {
                if (busy || !valid(sequence)) return;
                busy = true; b.disabled = true; const n = sequence;
                try { await action(); } catch { fail(n); }
                finally { busy = false; if (b.isConnected) b.disabled = false; }
            }; return b;
        }
        function fail(n) {
            if (!valid(n)) return;
            root.replaceChildren(node('p', t('Outcome unavailable. Refresh recorded plans before another change.', 'النتيجة غير متاحة. حدّث الخطط المسجلة قبل أي تغيير آخر.')));
            button(root, t('Refresh', 'تحديث'), () => load());
        }
        async function load(page = 1) {
            const n = ++sequence; root.replaceChildren();
            const data = await api(`${base}?page=${page}`);
            if (!valid(n)) return;
            if (data.read_only_polling !== true || data.remote_control !== false || data.billing_authority !== false) throw Error('Invalid polling scope');
            root.append(node('h3', t('Scheduled partner synchronization', 'مزامنة الشريك المجدولة')),
                node('p', t('Optional bounded read-only polling of negotiated partner modules. Reports stay unverified. No charging commands, reservation, payment or invoice posting. Changed partner credentials or account authority require renewed consent.', 'قراءة دورية اختيارية ومحدودة لوحدات الشريك المتفاوض عليها. تبقى التقارير غير موثقة. لا أوامر شحن أو حجوزات أو مدفوعات أو ترحيل فواتير. تغيير بيانات الشريك أو صلاحية الحساب يتطلب موافقة جديدة.')));
            button(root, t('Refresh', 'تحديث'), () => load(page));
            if (!data.configured) root.append(node('p', t('Background polling is disabled on this server.', 'القراءة الدورية معطلة على هذا الخادم.')));
            for (const plan of data.plans) {
                if (!['locations','tariffs','sessions','cdrs'].includes(plan.module) || typeof plan.revision !== 'string' || !/^(0|[1-9][0-9]{0,18})$/.test(plan.revision)) throw Error('Invalid plan');
                const card = node('article'); root.append(card); card.append(node('h4', modules()[plan.module]), node('p', plan.consent_current ? t('Current consent', 'موافقة حالية') : t('No current consent', 'لا توجد موافقة حالية')));
                if (plan.next_run_at) card.append(node('p', `${t('Next eligible poll (UTC)', 'موعد القراءة المتاحة التالي (UTC)')}: ${plan.next_run_at}`));
                if (plan.running) card.append(node('p', t('Poll in progress; an interrupted run requires review.', 'قراءة قيد التنفيذ؛ الانقطاع يتطلب المراجعة.')));
                if (plan.stopped_reason) card.append(node('p', `${t('Paused', 'متوقف')}: ${stopped(plan.stopped_reason)}`));
                if (plan.enabled) button(card, t('Disable module polling', 'تعطيل قراءة الوحدة'), async () => {
                    await api(`${base}/${plan.module}`, 'PUT', {enabled:false,interval_minutes:plan.interval_minutes,expected_revision:plan.revision}); if (valid(n)) await load(page);
                });
                if (data.configured && plan.negotiated && !plan.running) {
                    const interval = node('select'); interval.setAttribute('aria-label', t('Polling interval in minutes', 'فاصل القراءة بالدقائق'));
                    for (const minutes of data.intervals) { const option = node('option', `${minutes} ${t('minutes', 'دقيقة')}`); option.value = minutes; interval.append(option); }
                    interval.value = String(plan.interval_minutes); card.append(interval);
                    const consent = node('input'); consent.type = 'checkbox'; const label = node('label'); label.append(consent, document.createTextNode(t('I approve this module and polling interval.', 'أوافق على هذه الوحدة وفاصل القراءة.'))); card.append(label);
                    const save = button(card, t('Enable reviewed polling', 'تفعيل القراءة المراجعة'), async () => {
                        if (!consent.checked || !valid(n)) return;
                        await api(`${base}/${plan.module}`, 'PUT', {enabled:true,confirmed:true,interval_minutes:Number(interval.value),expected_revision:plan.revision}); if (valid(n)) await load(page);
                    }); save.disabled = true; consent.onchange = () => save.disabled = !consent.checked || busy;
                    interval.onchange = () => { consent.checked = false; save.disabled = true; };
                }
            }
            root.append(node('h4', t('Private polling history', 'سجل القراءة الخاص')));
            for (const run of data.history.data) root.append(node('p', `${run.started_at} UTC · ${modules()[run.module] || ''} · ${state(run.state)} · ${counts(run.counts)}`));
            if (page > 1) button(root, t('Previous', 'السابق'), () => load(page - 1));
            if (data.history.current_page < data.history.last_page) button(root, t('Next', 'التالي'), () => load(page + 1));
        }
        const hide = () => { if (document.hidden || !current()) { sequence++; root.replaceChildren(); } if (!root.isConnected) document.removeEventListener('visibilitychange', hide); };
        document.addEventListener('visibilitychange', hide);
        try { await load(); } catch { fail(sequence); }
    },
};
