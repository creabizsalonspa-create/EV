/* Explicit stored-source snapshots; no polling or operational commands. */
window.renderConnectorFleetAction = (root, { api, actor, openDiagnostics, filterState }) => {
    const viewer = actor();
    if (!viewer || !['vendor', 'admin'].includes(viewer.role)) return;
    const panel = document.createElement('section');
    root.append(panel);
    // A locale/command-history refresh re-creates this widget. Preserve only
    // the current authenticated actor's in-memory form draft; never issue a
    // station read automatically or persist the scoped ID across accounts.
    const validStation = value => value === '' ||
        (typeof value === 'string' && /^[1-9][0-9]*$/.test(value) &&
         Number.isSafeInteger(Number(value)));
    let selectedStation = validStation(filterState?.selectedStation)
        ? filterState.selectedStation : '';
    let draftStation = validStation(filterState?.draftStation)
        ? filterState.draftStation : selectedStation;
    let generation = 0, request, disposed = false;
    const t = (en, ar) => document.documentElement.lang === 'ar' ? ar : en;
    const node = (tag, text) => { const n = document.createElement(tag); n.textContent = text; return n; };
    const current = n => !disposed && panel.isConnected && !document.hidden && actor() === viewer && n === generation;
    const sourceClock = raw => typeof raw === 'string' && raw === raw.trim() &&
        window.EvStationTime?.displayStationTime(raw,'UTC','en') ? Date.parse(raw) : NaN;
    const clockLine = (en, ar, raw) => {
        const p = node('p','');
        const label = language => window.EvStationTime?.serverEventTime(raw,language) ||
            (language === 'ar' ? 'وقت الحدث غير متاح' : 'Event time unavailable');
        p.dataset.en = en + ': ' + label('en');
        p.dataset.ar = ar + ': ' + label('ar');
        p.textContent = t(p.dataset.en,p.dataset.ar);
        return p;
    };
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
        const station = document.createElement('input'); station.name = 'fleet-station'; station.type = 'number'; station.min = '1'; station.step = '1'; station.value = draftStation; field.append(station);
        // Input is draft-only, not permission to send a request. Rebuilding
        // this same-actor panel must not silently erase an explicitly typed
        // station before the user clicks Read.
        station.oninput = () => {
            if (!form.isConnected || actor() !== viewer || document.hidden) return;
            draftStation = station.value.trim();
            if (filterState) filterState.draftStation = draftStation;
        };
        const submit = node('button', t('Read connector snapshot', 'قراءة لقطة الموصلات')); submit.type = 'submit';
        form.append(field, submit); panel.append(form);
        form.onsubmit = e => {
            e.preventDefault();
            if (!form.isConnected || actor() !== viewer || document.hidden) return;
            const id = station.value.trim();
            if (!validStation(id)) return;
            selectedStation = id;
            draftStation = id;
            if (filterState) {
                filterState.selectedStation = id;
                filterState.draftStation = id;
            }
            void load(1);
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
                data.connectors.current_page !== page || data.connectors.last_page < page ||
                !Number.isFinite(sourceClock(data.as_of)) ||
                new Set(data.connectors.data.map(row => row?.mapping_id)).size !== data.connectors.data.length) throw Error('Invalid snapshot');
            for (const row of data.connectors.data) {
                const f = row.status_freshness;
                if (row.operations_target != null && !['company_id','charger_id'].every(k => Number.isSafeInteger(row.operations_target[k]) && row.operations_target[k] > 0)) throw Error('Invalid navigation target');
                if (!Number.isSafeInteger(row.mapping_id) || row.mapping_id < 1 ||
                    !Number.isSafeInteger(row.station_id) || row.station_id < 1 ||
                    (selectedStation && row.station_id !== Number(selectedStation)) || typeof row.station_name !== 'string' ||
                    ![null,'1.6','2.0.1'].includes(row.protocol) || typeof row.inventory_eligible_at_read !== 'boolean' ||
                    !f || !['recent','stale','unknown'].includes(f.state) || f.evidence !== 'SIMULATED_SOURCE' || f.physical_availability_verified !== false ||
                    (f.state === 'recent' && !['Available','Occupied','Preparing','Charging','SuspendedEV','SuspendedEVSE','Finishing','Reserved','Unavailable','Faulted'].includes(f.reported_status))) throw Error('Invalid source');
            }
            reset();
            const rendered = generation;
            panel.append(clockLine('Snapshot time','وقت اللقطة',data.as_of));
            for (const row of data.connectors.data) {
                const f = row.status_freshness, card = document.createElement('article');
                card.append(node('h4', `${row.station_name} · #${row.mapping_id}`), node('p', `${t('Protocol', 'البروتوكول')}: ${row.protocol ?? t('No qualified source', 'لا يوجد مصدر مؤهل')}`));
                // Check the existing strict source freshness contract at the
                // recorded read instant, never against the device clock.
                const sourceState = sourceClock(f.checked_at) <= sourceClock(data.as_of)
                    ? window.EvConnectorFreshness?.state(f,0) ?? 'unknown' : 'unknown';
                card.append(node('p', !row.inventory_eligible_at_read ? t('Inventory unavailable or maintenance blocked', 'المخزون غير متاح أو محظور للصيانة') :
                    f.state === 'recent' && sourceState === f.reported_status ? t('Recent report at snapshot: ', 'تقرير حديث وقت اللقطة: ') + window.EvConnectorFreshness.label(f.reported_status, document.documentElement.lang === 'ar') :
                    sourceState === 'stale' ? t('Stale report; current state unknown', 'تقرير قديم؛ الحالة الحالية غير معروفة') : t('Source state unknown', 'حالة المصدر غير معروفة')));
                if (f.observed_at != null) card.append(clockLine('Source timestamp','وقت المصدر',f.observed_at));
                if (row.operations_target && typeof openDiagnostics === 'function') {
                    const b = node('button', t('Open source diagnostics', 'فتح تشخيص المصدر')); b.type = 'button';
                    b.onclick = async () => {
                        if (!current(rendered) || !b.isConnected || b.disabled) return;
                        b.disabled = true;
                        try { await openDiagnostics(row.operations_target.company_id, row.operations_target.charger_id); }
                        catch { if (current(rendered) && b.isConnected) card.append(node('p', t('Diagnostics unavailable. Recheck current access.', 'التشخيص غير متاح. تحقق من الوصول الحالي.'))); }
                        finally { if (b.isConnected) b.disabled = false; }
                    };
                    card.append(b);
                }
                panel.append(card);
            }
            if (!data.connectors.data.length) panel.append(node('p', t('No mapped connectors in this scope.', 'لا توجد موصلات مرتبطة ضمن هذا النطاق.')));
            const stationSnapshot = selectedStation;
            for (const [label, next, enabled] of [[t('Previous','السابق'), page - 1, page > 1], [t('Next','التالي'), page + 1, page < data.connectors.last_page]]) {
                const b = button(label, () => {
                    if (!current(rendered) || !b.isConnected || b.disabled || !enabled) return;
                    selectedStation = stationSnapshot; void load(next);
                });
                b.disabled = !enabled;
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
