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
        const direct = node('button', t('Read direct charger inventory', 'قراءة مخزون الشواحن المباشرة')); direct.type = 'button';
        direct.onclick = () => {
            if (!form.isConnected || actor() !== viewer || document.hidden) return;
            const id = station.value.trim();
            if (!validStation(id)) return;
            selectedStation = draftStation = id;
            if (filterState) { filterState.selectedStation = id; filterState.draftStation = id; }
            void loadDirect(1);
        };
        form.append(field, submit, direct); panel.append(form);
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
    async function loadDirect(page) {
        const n = ++generation;
        request?.abort(); request = new AbortController();
        if (!current(n)) return;
        const query = new URLSearchParams({page: String(page), ...(selectedStation ? {station_id: selectedStation} : {})});
        panel.replaceChildren(node('p', t('Reading direct inventory…', 'جارٍ قراءة المخزون المباشر…')));
        const unknown = t('Registered source unavailable or unverified; status unknown.', 'المصدر المسجل غير متاح أو غير متحقق؛ الحالة مجهولة.');
        const timestamp = value => typeof value === 'string' && value.length <= 50 &&
            /^\d{4}-\d\d-\d\dT/.test(value) && Number.isFinite(Date.parse(value));
        try {
            const data = await api('charging-operations/registered-chargers?' + query, 'GET', undefined, request.signal);
            if (!current(n)) return;
            const p = data?.chargers, ids = new Set();
            if (data?.scope !== 'direct_charger_fleet' || data.evidence !== 'INVENTORY_ONLY' ||
                ['physical_availability_verified','remote_control_authorized','billing_authority'].some(k => data[k] !== false) ||
                !timestamp(data.as_of) || !Array.isArray(p?.data) || p.data.length > 25 ||
                p.current_page !== page || !Number.isSafeInteger(p.last_page) || p.last_page < 1) throw Error('Invalid direct inventory');
            for (const row of p.data) {
                if (!row || !['company_id','charger_id','station_id'].every(k => Number.isSafeInteger(row[k]) && row[k] > 0) ||
                    ids.has(row.charger_id) || !['1.6','2.0.1'].includes(row.protocol) ||
                    typeof row.station_name !== 'string' || typeof row.asset_reference !== 'string' ||
                    row.state !== 'not_inspected' || (selectedStation && String(row.station_id) !== selectedStation)) throw Error('Invalid direct target');
                ids.add(row.charger_id);
            }
            reset();
            panel.append(node('h4', t('Direct charger fleet inventory', 'مخزون أسطول الشواحن المباشرة')),
                node('p', t('Inventory only; registration, physical availability and command authority are not verified.', 'المخزون فقط؛ لم يتم التحقق من التسجيل أو التوفر الفعلي أو صلاحية الأوامر.')));
            const cells = new Map();
            for (const row of p.data) {
                const card = document.createElement('article'), cell = document.createElement('section');
                card.append(node('h4', row.station_name + ' · ' + row.asset_reference),
                    node('p', 'OCPP ' + row.protocol), cell);
                cell.append(node('p', t('Source not inspected', 'لم يُفحص المصدر')));
                cells.set(row.charger_id, cell);
                panel.append(card);
            }
            if (p.data.length) {
                const read = button(t('Read registered sources for this page', 'قراءة المصادر المسجلة لهذه الصفحة'), async () => {
                    if (read.disabled || !read.isConnected || actor() !== viewer || document.hidden) return;
                    const epoch = ++generation;
                    request?.abort(); request = new AbortController();
                    const signal = request.signal;
                    read.disabled = true;
                    for (const cell of cells.values()) cell.replaceChildren(node('p', t('Reading live source…', 'جارٍ قراءة المصدر المباشر…')));
                    const results = new Map();
                    try {
                        for (let i = 0; i < p.data.length; i += 4) {
                            if (!current(epoch)) return;
                            await Promise.all(p.data.slice(i, i + 4).map(async target => {
                                try {
                                    const reply = await api('vendor-ops/companies/' + target.company_id + '/chargers/' + target.charger_id + '/live-connectors', 'GET', undefined, signal);
                                    if (!current(epoch)) return;
                                    const statuses = target.protocol === '1.6'
                                        ? ['Available','Preparing','Charging','SuspendedEV','SuspendedEVSE','Finishing','Reserved','Unavailable','Faulted']
                                        : ['Available','Occupied','Reserved','Unavailable','Faulted'];
                                    if (!reply || reply.company_id !== target.company_id || reply.charger_id !== target.charger_id ||
                                        reply.protocol !== target.protocol || reply.evidence !== 'REGISTERED_DIRECT_SOURCE' ||
                                        reply.source_identity_rechecked !== true || reply.local_mapping_verified !== false ||
                                        reply.source_topology_matches_local !== false || typeof reply.source_topology_complete !== 'boolean' ||
                                        typeof reply.source_online !== 'boolean' ||
                                        ['physical_availability_verified','remote_control_authorized','commands_sent'].some(k => reply[k] !== false) ||
                                        !timestamp(reply.observed_at) || !Array.isArray(reply.connectors) || reply.connectors.length > 100 ||
                                        (reply.source_topology_complete && !reply.connectors.length)) throw Error('Invalid direct source');
                                    const seen = new Set();
                                    for (const row of reply.connectors) {
                                        if (!row || row.mapping_id !== null || row.operator_maintenance_blocked !== null ||
                                            !Number.isSafeInteger(row.source_evse_number) ||
                                            (target.protocol === '1.6' ? row.source_evse_number !== 0 : row.source_evse_number < 1 || row.source_evse_number > 1000) ||
                                            !Number.isSafeInteger(row.source_connector_number) || row.source_connector_number < 1 || row.source_connector_number > 100 ||
                                            !['recent','stale','unknown'].includes(row.state) ||
                                            row.physical_available !== false || row.remote_control_authorized !== false ||
                                            (row.error_code !== null && (typeof row.error_code !== 'string' || row.error_code.length > 80 ||
                                                (!['','NoError'].includes(row.error_code) && row.reported_status !== 'Faulted'))) ||
                                            (row.state !== 'recent' && row.error_code !== null) ||
                                            (row.state === 'recent' ? (!reply.source_online || !reply.source_topology_complete ||
                                                !statuses.includes(row.reported_status) || !timestamp(row.source_event_at)) : row.reported_status !== null)) throw Error('Invalid direct connector');
                                        const key = row.source_evse_number + ':' + row.source_connector_number;
                                        if (seen.has(key)) throw Error('Duplicate target');
                                        seen.add(key);
                                    }
                                    results.set(target.charger_id, reply);
                                } catch (e) {
                                    if ([401,403,419].includes(e.status)) throw e;
                                }
                            }));
                        }
                        if (!current(epoch)) return;
                        for (const [id, cell] of cells) {
                            const reply = results.get(id);
                            cell.replaceChildren();
                            if (!reply) { cell.append(node('p', unknown)); continue; }
                            cell.append(node('p', (reply.source_topology_complete
                                ? t('Source topology reported; local mappings not verified', 'أبلغ المصدر عن البنية؛ الربط المحلي غير متحقق')
                                : t('Source topology incomplete; live status unknown', 'بنية المصدر غير مكتملة؛ الحالة مجهولة')) + ' · ' + reply.observed_at));
                            for (const row of reply.connectors) cell.append(node('p', row.source_evse_number + '/' + row.source_connector_number + ' · ' +
                                (row.state === 'recent' ? (window.EvConnectorFreshness?.label(row.reported_status, document.documentElement.lang === 'ar') ?? row.reported_status)
                                    : t('Unknown or stale', 'مجهول أو قديم'))));
                        }
                    } catch {
                        if (current(epoch)) { reset(); panel.append(node('p', unknown)); }
                    } finally { if (current(epoch)) read.disabled = false; }
                });
            } else panel.append(node('p', t('No direct chargers in this scope.', 'لا توجد شواحن مباشرة في هذا النطاق.')));
            const snapshot = selectedStation;
            for (const [label,next,enabled] of [[t('Previous','السابق'),page-1,page>1],[t('Next','التالي'),page+1,page<p.last_page]])
                button(label, () => { selectedStation = snapshot; void loadDirect(next); }).disabled = !enabled;
        } catch {
            if (current(n)) { reset(); panel.append(node('p', unknown)); }
        }
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
            const mappingIds = new Set();
            for (const row of data.connectors.data) {
                if (!row || mappingIds.has(row.mapping_id) || row.mapping_id <= 0) throw Error('Duplicate mapping');
                mappingIds.add(row.mapping_id);
                const f = row.status_freshness;
                if (row.operations_target != null && !['company_id','charger_id'].every(k => Number.isSafeInteger(row.operations_target[k]) && row.operations_target[k] > 0)) throw Error('Invalid navigation target');
                if (!Number.isSafeInteger(row.mapping_id) || !Number.isSafeInteger(row.station_id) || typeof row.station_name !== 'string' ||
                    ![null,'1.6','2.0.1'].includes(row.protocol) || typeof row.inventory_eligible_at_read !== 'boolean' ||
                    !f || !['recent','stale','unknown'].includes(f.state) || f.evidence !== 'SIMULATED_SOURCE' || f.physical_availability_verified !== false ||
                    (f.state === 'recent' && !['Available','Occupied','Preparing','Charging','SuspendedEV','SuspendedEVSE','Finishing','Reserved','Unavailable','Faulted'].includes(f.reported_status))) throw Error('Invalid source');
            }
            reset();
            panel.append(node('p', t('Snapshot time: ', 'وقت اللقطة: ') + data.as_of));
            const groups = new Map(), liveCells = new Map();
            for (const row of data.connectors.data) {
                const f = row.status_freshness, card = document.createElement('article');
                card.append(node('h4', `${row.station_name} · #${row.mapping_id}`), node('p', `${t('Protocol', 'البروتوكول')}: ${row.protocol ?? t('No qualified source', 'لا يوجد مصدر مؤهل')}`));
                card.append(node('p', !row.inventory_eligible_at_read ? t('Inventory unavailable or maintenance blocked', 'المخزون غير متاح أو محظور للصيانة') :
                    f.state === 'recent' ? t('Recent report at snapshot: ', 'تقرير حديث وقت اللقطة: ') + (window.EvConnectorFreshness?.label(f.reported_status, document.documentElement.lang === 'ar') ?? f.reported_status) :
                    f.state === 'stale' ? t('Stale report; current state unknown', 'تقرير قديم؛ الحالة الحالية غير معروفة') : t('Source state unknown', 'حالة المصدر غير معروفة')));
                if (f.observed_at) card.append(node('p', `${t('Source timestamp', 'وقت المصدر')}: ${f.observed_at}`));
                if (row.operations_target && typeof openDiagnostics === 'function') {
                    const b = node('button', t('Open source diagnostics', 'فتح تشخيص المصدر')); b.type = 'button';
                    b.onclick = async () => {
                        if (actor() !== viewer || !panel.isConnected || document.hidden || b.disabled) return;
                        b.disabled = true;
                        try { await openDiagnostics(row.operations_target.company_id, row.operations_target.charger_id); }
                        catch { if (actor() === viewer && b.isConnected) card.append(node('p', t('Diagnostics unavailable. Recheck current access.', 'التشخيص غير متاح. تحقق من الوصول الحالي.'))); }
                        finally { if (b.isConnected) b.disabled = false; }
                    };
                    card.append(b);
                }
                const liveCell = node('p', '');
                liveCell.dataset.fleetLive = String(row.mapping_id);
                card.append(liveCell);
                liveCells.set(row.mapping_id, liveCell);
                if (row.operations_target) {
                    const {company_id, charger_id} = row.operations_target;
                    const key = company_id + ':' + charger_id;
                    if (!groups.has(key)) groups.set(key, {company_id, charger_id, rows: []});
                    groups.get(key).rows.push(row);
                }
                panel.append(card);
            }
            if (groups.size) {
                const unavailable = t('Live source unavailable or unqualified; state unknown.', 'المصدر المباشر غير متاح أو غير مؤهل؛ الحالة غير معروفة.');
                const liveRead = button(t('Read live sources for this page', 'قراءة المصادر المباشرة لهذه الصفحة'), async () => {
                    if (liveRead.disabled || !liveRead.isConnected || actor() !== viewer || document.hidden) return;
                    const liveEpoch = ++generation;
                    request?.abort(); request = new AbortController();
                    const signal = request.signal;
                    liveRead.disabled = true;
                    for (const cell of liveCells.values()) cell.textContent = t('Reading live source…', 'جارٍ قراءة المصدر المباشر…');
                    const results = new Map();
                    const all = [...groups.values()];
                    try {
                        // At most four owned charger requests at a time; deduplicate by exact target.
                        // The existing per-charger API independently checks ownership and source identity.
                        for (let i = 0; i < all.length; i += 4) {
                            if (!current(liveEpoch)) return;
                            await Promise.all(all.slice(i, i + 4).map(async group => {
                                try {
                                    const reply = await api('vendor-ops/companies/' + group.company_id + '/chargers/' + group.charger_id + '/live-connectors', 'GET', undefined, signal);
                                    if (!current(liveEpoch)) return;
                                    const statuses = reply?.protocol === '1.6'
                                        ? ['Available','Preparing','Charging','SuspendedEV','SuspendedEVSE','Finishing','Reserved','Unavailable','Faulted']
                                        : ['Available','Occupied','Reserved','Unavailable','Faulted'];
                                    const timestamp = v => typeof v === 'string' && v.length <= 50 && /^\d{4}-\d\d-\d\dT/.test(v) && Number.isFinite(Date.parse(v));
                                    if (!reply || reply.company_id !== group.company_id || reply.charger_id !== group.charger_id ||
                                        !['1.6','2.0.1'].includes(reply.protocol) ||
                                        group.rows.some(row => row.protocol !== null && row.protocol !== reply.protocol) ||
                                        reply.evidence !== 'SIMULATED_CSMS_SOURCE' || reply.source_identity_rechecked !== true ||
                                        typeof reply.source_online !== 'boolean' || typeof reply.source_topology_matches_local !== 'boolean' ||
                                        ['physical_availability_verified','remote_control_authorized','commands_sent'].some(k => reply[k] !== false) ||
                                        !timestamp(reply.observed_at) || !Array.isArray(reply.connectors) ||
                                        reply.connectors.length < 1 || reply.connectors.length > 100) throw Error('Invalid live source');
                                    const byId = new Map();
                                    for (const row of reply.connectors) {
                                        if (!row || !Number.isSafeInteger(row.mapping_id) || row.mapping_id <= 0 || byId.has(row.mapping_id) ||
                                            !['recent','stale','unknown'].includes(row.state) ||
                                            row.physical_available !== false || row.remote_control_authorized !== false ||
                                            (row.state === 'recent'
                                                ? (!reply.source_online || !reply.source_topology_matches_local || !statuses.includes(row.reported_status) || !timestamp(row.source_event_at))
                                                : row.reported_status !== null)) throw Error('Invalid live connector');
                                        byId.set(row.mapping_id, row);
                                    }
                                    if (group.rows.some(row => !byId.has(row.mapping_id))) throw Error('Missing fleet mapping');
                                    for (const row of group.rows) results.set(row.mapping_id, {row: byId.get(row.mapping_id), at: reply.observed_at});
                                } catch (e) {
                                    if ([401, 403, 419].includes(e.status)) throw e;
                                    // Leave the entire affected charger unknown; never fall back to stored status.
                                }
                            }));
                        }
                        if (!current(liveEpoch)) return;
                        for (const [id, cell] of liveCells) {
                            const result = results.get(id);
                            if (!result) { cell.textContent = unavailable; continue; }
                            const row = result.row;
                            const label = row.state === 'recent'
                                ? t('Live source report at read: ', 'تقرير المصدر المباشر وقت القراءة: ') + (window.EvConnectorFreshness?.label(row.reported_status, document.documentElement.lang === 'ar') ?? row.reported_status)
                                : t('Live source state unknown or stale', 'حالة المصدر المباشر غير معروفة أو قديمة');
                            cell.textContent = label + ' · ' + result.at;
                        }
                    } catch {
                        if (current(liveEpoch)) {
                            reset();
                            panel.append(node('p', unavailable));
                        }
                    } finally {
                        if (current(liveEpoch)) liveRead.disabled = false;
                    }
                });
                panel.append(node('p', t('Live reads are separate simulator-source snapshots; no physical or command authority.', 'القراءات المباشرة لقطات مستقلة من المحاكي؛ لا تثبت التوفر الفعلي ولا تمنح صلاحية الأوامر.')));
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
