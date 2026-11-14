"use strict";
window.EvStationBlocks = (() => {
    const text = (tag, en, ar) => { const node = document.createElement(tag); node.dataset.en = en; node.dataset.ar = ar; node.textContent = document.documentElement.lang === 'ar' ? ar : en; return node; };
    function show(root, rows) {
        for (const row of Array.isArray(rows) ? rows : []) {
            if (row.scope !== 'STATION_WIDE_OPERATOR_POLICY') continue;
            root.append(text('p', 'Operator block (UTC): ' + row.starts_at + ' → ' + row.ends_at + ' — ' + row.reason_en,
                'حجب من المشغل (UTC): ' + row.starts_at + ' ← ' + row.ends_at + ' — ' + row.reason_ar));
        }
    }
    function mount(root, {api, stationId, current, initialRows = null}) {
        let rows = [], busy = false, pending = null, renderVersion = 0;
        const path = 'stations/' + stationId + '/availability-blocks';
        async function read() {
            const data = await api(path);
            if (!current()) return;
            if (data?.station_id !== stationId || !Array.isArray(data.data)) throw Error('Invalid station block response');
            rows = data.data; render();
        }
        function render() {
            if (!current()) return;
            const view = ++renderVersion;
            root.replaceChildren();
            root.append(text('h3', 'Station availability blocks', 'فترات حجب المحطة'));
            root.append(text('p', 'Blocks prevent new bookings and starts; existing bookings remain and active charging can finish. Times are UTC. No physical charger command is sent.',
                'يمنع الحجب الحجوزات وبدء الشحن الجديدة. تبقى الحجوزات السابقة ويمكن إكمال الشحن الجاري. الأوقات بتوقيت UTC ولا ترسل أوامر فعلية للشاحن.'));
            const notice = text('p', '', ''); notice.setAttribute('role', 'status'); root.append(notice);
            async function act(task) {
                if (busy || !current() || view !== renderVersion) return; busy = true; root.querySelectorAll('button').forEach(b => b.disabled = true);
                try { await task(); }
                catch (error) { if (current()) { if (error.status === 422) { pending = null; root.querySelectorAll('input').forEach(input => input.disabled = false); } else if (pending) render(); root.prepend(text('p', 'Action not confirmed. Refresh status or retry the same request.', 'لم يتأكد الإجراء. حدّث الحالة أو أعد محاولة الطلب نفسه.')); } }
                finally { if (current()) { busy = false; root.querySelectorAll('button').forEach(b => b.disabled = false); } }
            }
            const refresh = text('button', 'Refresh station blocks', 'تحديث فترات الحجب'); refresh.type = 'button'; refresh.onclick = () => act(read); root.append(refresh);
            for (const row of rows) {
                const item = document.createElement('div'); show(item, [row]);
                const cancel = text('button', 'Cancel this block', 'إلغاء فترة الحجب'); cancel.type = 'button';
                cancel.onclick = () => act(async () => { await api(path + '/' + encodeURIComponent(row.id) + '/cancel', 'POST', {revision:row.revision}); if (current()) await read(); });
                item.append(cancel); root.append(item);
            }
            const form = document.createElement('form'), fields = {};
            for (const [key,en,ar] of [['starts_at','Start (UTC, YYYY-MM-DDTHH:mm:ssZ)','البداية (UTC، YYYY-MM-DDTHH:mm:ssZ)'],['ends_at','End (UTC, YYYY-MM-DDTHH:mm:ssZ)','النهاية (UTC، YYYY-MM-DDTHH:mm:ssZ)'],['reason_en','Public reason (English)','السبب المنشور (بالإنجليزية)'],['reason_ar','Public reason (Arabic)','السبب المنشور (بالعربية)']]) {
                const label = text('label', en, ar), input = document.createElement('input'); input.name = key; input.required = true; input.maxLength = key.startsWith('reason') ? 300 : 20;
                input.value = pending?.[key] || ''; input.disabled = pending !== null; label.append(input); form.append(label); fields[key] = input;
            }
            const submit = text('button', pending ? 'Retry same block request' : 'Publish station block', pending ? 'إعادة محاولة طلب الحجب نفسه' : 'نشر حجب المحطة'); submit.type = 'submit'; form.append(submit);
            form.onsubmit = event => { event.preventDefault(); if (busy || !current() || view !== renderVersion) return;
                pending ??= {idempotency_key:crypto.randomUUID(), ...Object.fromEntries(Object.entries(fields).map(([key,input]) => [key,input.value.trim()]))};
                Object.values(fields).forEach(input => input.disabled = true);
                act(async () => { const result = await api(path, 'POST', pending); if (!current()) return;
                    if (result?.station_id !== stationId || typeof result.id !== 'string') throw Error('Invalid block receipt');
                    pending = null; await read(); });
            };
            root.append(form);
        }
        if (Array.isArray(initialRows)) { rows = initialRows; render(); }
        else read().catch(() => { if (current()) root.replaceChildren(text('p', 'Station blocks are unavailable. Refresh the station before editing.', 'فترات الحجب غير متاحة. حدّث المحطة قبل التعديل.')); });
    }
    return {show, mount};
})();
