"use strict";
window.EvStationBlocks = (() => {
    const text = (tag, en, ar) => { const node = document.createElement(tag); node.dataset.en = en; node.dataset.ar = ar; node.textContent = document.documentElement.lang === 'ar' ? ar : en; return node; };
    function show(root, rows) {
        for (const row of Array.isArray(rows) ? rows : []) {
            if (row?.scope !== 'STATION_WIDE_OPERATOR_POLICY' || row.physical_availability_verified !== false
                || ![row.starts_at, row.ends_at, row.reason_en, row.reason_ar].every(value => typeof value === 'string')) continue;
            root.append(text('p', 'Operator block (live charger status unknown, UTC): ' + row.starts_at + ' → ' + row.ends_at + ' — ' + row.reason_en,
                'حجب من المشغل (حالة الشاحن المباشرة غير معروفة، UTC): ' + row.starts_at + ' ← ' + row.ends_at + ' — ' + row.reason_ar));
        }
    }
    function mount(root, {api, stationId, current, initialRows = null}) {
        let rows = [], busy = false, pending = null, renderVersion = 0;
        const path = 'stations/' + stationId + '/availability-blocks';
        const validReceipt = (row, cancelled) => row && typeof row === 'object' &&
            row.station_id === stationId &&
            typeof row.id === 'string' &&
            /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(row.id) &&
            typeof row.starts_at === 'string' &&
            typeof row.ends_at === 'string' &&
            typeof row.reason_en === 'string' &&
            typeof row.reason_ar === 'string' &&
            Number.isSafeInteger(row.revision) && row.revision > 0 &&
            row.scope === 'STATION_WIDE_OPERATOR_POLICY' &&
            row.cancelled === cancelled &&
            row.physical_availability_verified === false;
        const validatedRows = data => data?.station_id === stationId &&
            Array.isArray(data.data) && data.data.length <= 50 &&
            data.data.every(row => validReceipt(row, false)) &&
            new Set(data.data.map(row => row.id)).size === data.data.length;
        async function read(confirmation = null) {
            const data = await api(path);
            if (!current()) return;
            if (!validatedRows(data)) throw Error('Invalid station block response');
            rows = data.data; render(confirmation);
        }
        async function readAfterWrite(confirmation) {
            try {
                await read(confirmation);
            } catch {
                if (!current()) return;
                rows = [];
                render([
                    confirmation[0] + ' The block list is unavailable. Refresh to view current blocks.',
                    confirmation[1] + ' قائمة الفترات غير متاحة. حدّث الصفحة لعرض الفترات الحالية.',
                ]);
            }
        }
        function render(confirmation = null) {
            if (!current()) return;
            const view = ++renderVersion;
            root.replaceChildren();
            root.append(text('h3', 'Station availability blocks', 'فترات حجب المحطة'));
            root.append(text('p', 'Blocks prevent new bookings and starts; existing bookings remain and active charging can finish. Times are UTC. No physical charger command is sent.',
                'يمنع الحجب الحجوزات وبدء الشحن الجديدة. تبقى الحجوزات السابقة ويمكن إكمال الشحن الجاري. الأوقات بتوقيت UTC ولا ترسل أوامر فعلية للشاحن.'));
            const notice = text('p', confirmation?.[0] ?? '', confirmation?.[1] ?? ''); notice.setAttribute('role', 'status'); root.append(notice);
            async function act(task) {
                if (busy || !current() || view !== renderVersion) return; busy = true; root.querySelectorAll('button').forEach(b => b.disabled = true);
                try { await task(); }
                catch (error) {
                    if (current()) {
                        // A failed source read or rejected write makes previous
                        // cancel targets uncertain. Never retain them as live.
                        rows = [];
                        if (error.status === 422) pending = null;
                        render();
                        root.prepend(text('p',
                            'Action not confirmed. Refresh status or retry the same request.',
                            'لم يتأكد الإجراء. حدّث الحالة أو أعد محاولة الطلب نفسه.'));
                    }
                }
                finally { if (current()) { busy = false; root.querySelectorAll('button').forEach(b => b.disabled = false); } }
            }
            const refresh = text('button', 'Refresh station blocks', 'تحديث فترات الحجب'); refresh.type = 'button'; refresh.onclick = () => act(read); root.append(refresh);
            for (const row of rows) {
                const item = document.createElement('div'); show(item, [row]);
                const cancel = text('button', 'Cancel this block', 'إلغاء فترة الحجب'); cancel.type = 'button';
                cancel.onclick = () => act(async () => {
                    const result = await api(path + '/' + encodeURIComponent(row.id) + '/cancel', 'POST', {revision:row.revision});
                    if (!current()) return;
                    if (!validReceipt(result, true) || result.id !== row.id || result.revision !== row.revision + 1 ||
                        result.starts_at !== row.starts_at || result.ends_at !== row.ends_at ||
                        result.reason_en !== row.reason_en || result.reason_ar !== row.reason_ar) throw Error('Invalid cancellation receipt');
                    await readAfterWrite(['Station block cancelled.', 'أُلغيت فترة حجب المحطة.']);
                });
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
                    if (!validReceipt(result, result?.cancelled === true) ||
                        result.revision !== (result.cancelled ? 2 : 1) ||
                        Date.parse(result.starts_at) !== Date.parse(pending.starts_at) ||
                        Date.parse(result.ends_at) !== Date.parse(pending.ends_at) ||
                        result.reason_en !== pending.reason_en || result.reason_ar !== pending.reason_ar) throw Error('Invalid block receipt');
                    pending = null;
                    const confirmation = result.cancelled
                        ? ['This station block was already cancelled.', 'أُلغيت فترة حجب المحطة سابقاً.']
                        : ['Station block published.', 'نُشرت فترة حجب المحطة.'];
                    await readAfterWrite(confirmation); });
            };
            root.append(form);
        }
        if (Array.isArray(initialRows) && validatedRows({ station_id: stationId, data: initialRows })) {
            rows = initialRows;
            render();
        } else {
            read().catch(() => {
                if (current()) root.replaceChildren(text('p',
                    'Station blocks are unavailable. Refresh the station before editing.',
                    'فترات الحجب غير متاحة. حدّث المحطة قبل التعديل.'));
            });
        }
    }
    return {show, mount};
})();
