/* Explicit stored-source snapshots; no polling or operational commands. */
window.renderConnectorFleetAction = (root, { api, actor }) => {
    const viewer = actor();
    if (!viewer || !['vendor', 'admin'].includes(viewer.role)) return;
    const panel = document.createElement('section');
    root.append(panel);
    let generation = 0, request, selectedStation = '', disposed = false;
    const t = (en, ar) => document.documentElement.lang === 'ar' ? ar : en;
    const node = (tag, text) => { const n = document.createElement(tag); n.textContent = text; return n; };
    const current = n => !disposed && panel.isConnected && !document.hidden && actor() === viewer && n === generation;
    const button = (label, fn) => {
        const b = node('button', label); b.type = 'button'; b.onclick = fn; panel.append(b); return b;
    };
    function clear() { generation++; request?.abort(); panel.replaceChildren(); }
    function reset() {
        clear();
        panel.append(node('h3', t('Recorded connector fleet', 'سجل موصلات الأسطول')),
            node('p', t('Snapshot of simulator reports only. No physical availability, connectivity or command authority is verified.', 'لقطة لتقارير المحاكي فقط. لم يُتحقق من التوفر أو الاتصال الفعلي ولا تمنح صلاحية الأوامر.')));
        if (actor() !== viewer || document.hidden) return;
        const form = document.createElement('form');
        const field = node('label', t('Station ID (optional)', 'معرف المحطة (اختياري)'));
        const station = document.createElement('input'); station.name = 'fleet-station'; station.type = 'number'; station.min = '1'; station.step = '1'; station.value = selectedStation; field.append(station);
        const submit = node('button', t('Read connector snapshot', 'قراءة لقطة الموصلات')); submit.type = 'submit';
        form.append(field, submit); panel.append(form);
        form.onsubmit = e => {
            e.preventDefault();
            const id = station.value.trim();
            if (id && (!/^[1-9][0-9]*$/.test(id) || !Number.isSafeInteger(Number(id)))) return;
            selectedStation = id; void load(1);
        };
    }
    async function load(page) {
        const n = ++generation;
        request?.abort(); request = new AbortController();
        if (!current(n)) return;
        const query = new URLSearchParams({page: String(page), ...(selectedStation ? {station_id: selectedStation} : {})});
        panel.replaceChildren(node('p', t('Reading recorded connectors…', 'جارٍ قراءة الموصلات المسجلة…')));
        try {
            const data = await api('charging-operations/connectors?' + query, 'GET', undefined, request.signal);
            if (!current(n)) return;
            if (data?.scope !== 'recorded_connector_fleet' || data.evidence !== 'SIMULATED_SOURCE' ||
                ['physical_availability_verified','remote_control','billing_authority'].some(k=>data[k] !== false) ||
                !Array.isArray(data.connectors?.data) || data.connectors.data.length > 25 ||
                !Number.isSafeInteger(data.connectors.current_page) || !Number.isSafeInteger(data.connectors.last_page) ||
                data.connectors.current_page !== page || data.connectors.last_page < 1 || typeof data.as_of !== 'string') throw Error('Invalid snapshot');
            for (const row of data.connectors.data) {
                const f = row.status_freshness;
                if (!Number.isSafeInteger(row.mapping_id) || !Number.isSafeInteger(row.station_id) || typeof row.station_name !== 'string' ||
                    ![null,'1.6','2.0.1'].includes(row.protocol) || typeof row.inventory_eligible_at_read !== 'boolean' ||
                    !f || !['recent','stale','unknown'].includes(f.state) || f.evidence !== 'SIMULATED_SOURCE' || f.physical_availability_verified !== false ||
                    (f.state === 'recent' && !['Available','Occupied','Preparing','Charging','SuspendedEV','SuspendedEVSE','Finishing','Reserved','Unavailable','Faulted'].includes(f.reported_status))) throw Error('Invalid source');
            }
            reset();
            panel.append(node('p', t('Snapshot time: ', 'وقت اللقطة: ') + data.as_of));
            for (const row of data.connectors.data) {
                const f = row.status_freshness, card = document.createElement('article');
                card.append(node('h4', `${row.station_name} · #${row.mapping_id}`), node('p', `${t('Protocol', 'البروتوكول')}: ${row.protocol ?? t('No qualified source', 'لا يوجد مصدر مؤهل')}`));
                card.append(node('p', !row.inventory_eligible_at_read ? t('Inventory unavailable or maintenance blocked', 'المخزون غير متاح أو محظور للصيانة') :
                    f.state === 'recent' ? t('Recent report at snapshot: ', 'تقرير حديث وقت اللقطة: ') + f.reported_status :
                    f.state === 'stale' ? t('Stale report; current state unknown', 'تقرير قديم؛ الحالة الحالية غير معروفة') : t('Source state unknown', 'حالة المصدر غير معروفة')));
                if (f.observed_at) card.append(node('p', `${t('Source timestamp', 'وقت المصدر')}: ${f.observed_at}`));
                panel.append(card);
            }
            if (!data.connectors.data.length) panel.append(node('p', t('No mapped connectors in this scope.', 'لا توجد موصلات مرتبطة ضمن هذا النطاق.')));
            const stationSnapshot = selectedStation;
            for (const [label, next, enabled] of [[t('Previous','السابق'), page - 1, page > 1], [t('Next','التالي'), page + 1, page < data.connectors.last_page]]) {
                button(label, () => {selectedStation = stationSnapshot; void load(next);}).disabled = !enabled;
            }
        } catch (e) {
            if (!current(n)) return;
            reset(); panel.append(node('p', t('Connector snapshot unavailable. Refresh explicitly.', 'لقطة الموصلات غير متاحة. حدّث صراحةً.')));
        }
    }
    const visibility = () => reset();
    document.addEventListener('visibilitychange', visibility);
    window.addEventListener('pagehide', visibility);
    const observer = new MutationObserver(() => {
        if (!panel.isConnected) { disposed = true; clear(); observer.disconnect(); document.removeEventListener('visibilitychange', visibility); window.removeEventListener('pagehide', visibility); }
    });
    observer.observe(document.body, {childList: true, subtree: true});
    reset();
};
