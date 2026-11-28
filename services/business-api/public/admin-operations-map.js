/* Private Admin inventory. Self-hosted Leaflet; external tiles require explicit consent. */
window.EvAdminOperationsMap = (() => {
    const root = document.getElementById('admin-operations-map');
    if (!root) return {setActor() {}};
    let epoch = 0, sequence = 0, api = null, pending = null, map = null, markers = null;
    let fields, output, status, canvas, viewport, enable, disable, applied = {}, currentPage = 1;
    const text = (en, ar) => document.documentElement.lang === 'ar' ? ar : en;
    const node = (tag, en, ar = en) => {
        const element = document.createElement(tag);
        element.dataset.en = en; element.dataset.ar = ar; element.textContent = text(en, ar);
        return element;
    };
    const plain = (tag, value) => { const element = document.createElement(tag); element.textContent = String(value); return element; };
    const button = (en, ar, handler) => { const element = node('button', en, ar); element.type = 'button'; element.onclick = handler; return element; };
    const say = (en, ar) => { status.replaceChildren(node('span', en, ar)); };
    function erase() {
        sequence++; pending?.abort(); pending = null;
        output?.replaceChildren(); markers?.clearLayers();
    }
    function stopMap() {
        map?.remove(); map = markers = null;
        if (canvas) {
            // Leaflet removes its panes but retains container classes. Clear
            // the detached projection and focus state before another consent.
            canvas.replaceChildren(); canvas.className = ''; canvas.hidden = true;
            canvas.removeAttribute?.('tabindex');
        }
        if (viewport) { viewport.checked = false; viewport.disabled = true; }
        if (enable) enable.hidden = false;
        if (disable) disable.hidden = true;
    }
    function clear() { epoch++; erase(); stopMap(); api = null; root.replaceChildren(); }
    function collect() {
        const query = {};
        for (const [key, input] of Object.entries(fields)) {
            if (!input.disabled && input.value.trim()) query[key] = input.value.trim();
        }
        if (viewport.checked && map) {
            const b = map.getBounds(), width = b.getEast() - b.getWest();
            const normalize = value => ((value + 180) % 360 + 360) % 360 - 180;
            Object.assign(query, {south: String(Math.max(-90, b.getSouth())), north: String(Math.min(90, b.getNorth())),
                west: String(width >= 360 ? -180 : normalize(b.getWest())), east: String(width >= 360 ? 180 : normalize(b.getEast()))});
        }
        return query;
    }
    const safeInt = value => Number.isSafeInteger(value) && value >= 0;
    function safe(data, partner) {
        const expectedScope = partner ? 'published_partner_catalog' : 'platform_inventory_all_companies';
        if (data?.scope !== expectedScope || data.charging_authority_granted !== false || data.payment_authority_granted !== false ||
            !Array.isArray(data.data) || data.data.length > 25 || !safeInt(data.current_page) || !safeInt(data.last_page) || !safeInt(data.total) || typeof data.as_of !== 'string') return false;
        const ids = new Set();
        return data.data.every(row => {
            if (!row || !safeInt(row.id) || row.id === 0 || ids.has(row.id) ||
                row.availability !== 'unknown' || row.physical_availability_verified !== false || row.remote_control !== false ||
                !Number.isFinite(row.latitude) || Math.abs(row.latitude) > 90 ||
                !Number.isFinite(row.longitude) || Math.abs(row.longitude) > 180) return false;
            ids.add(row.id);
            if (partner) {
                return row.source === 'partner_catalog' && (row.name === null || typeof row.name === 'string') &&
                    (row.country === null || (typeof row.country === 'string' && /^[A-Z]{3}$/.test(row.country))) &&
                    typeof row.provider_label === 'string' &&
                    (row.source_clock_evidence === 'PARTNER_REPORTED_TIME'
                        ? safeInt(row.reported_source_age_minutes)
                        : ['SOURCE_TIMESTAMP_UNKNOWN','PARTNER_CLOCK_AHEAD'].includes(row.source_clock_evidence) &&
                            row.reported_source_age_minutes === null);
            }
            return typeof row.name === 'string' && typeof row.source === 'string' &&
                safeInt(row.charger_count) && safeInt(row.mapped_connector_count) &&
                (row.company === null || (safeInt(row.company?.id) && typeof row.company.name === 'string' && typeof row.company.review_status === 'string'));
        });
    }
    function renderPartner(card, row) {
        card.dataset.partnerRecordId = String(row.id);
        const name = row.name || text('Partner location','موقع الشريك');
        card.append(plain('h4', name + ' · #' + row.id),
            plain('p', `${row.country ?? '—'} · ${row.latitude}, ${row.longitude}`),
            node('p', `Partner catalog · ${row.provider_label}`, `كتالوج الشريك · ${row.provider_label}`));
        const age = row.reported_source_age_minutes;
        if (age !== null) {
            card.append(node('p', `Partner reported update: ${age} minutes ago`, `التحديث حسب تقرير الشريك: قبل ${age} دقيقة`));
        } else if (row.source_clock_evidence === 'PARTNER_CLOCK_AHEAD') {
            card.append(node('p', 'Partner timestamp is in the future', 'وقت تقرير الشريك في المستقبل'));
        } else {
            card.append(node('p', 'Partner report time unavailable', 'وقت تقرير الشريك غير متاح'));
        }
        return name;
    }
    function renderPlatform(card, row) {
        card.dataset.stationId = String(row.id);
        card.append(plain('h4', row.name + ' · #' + row.id), plain('p', `${row.country} · ${row.timezone} · ${row.latitude}, ${row.longitude}`),
            plain('p', row.source), node('p', `Chargers: ${row.charger_count} · mapped connectors: ${row.mapped_connector_count}`,
                `الشواحن: ${row.charger_count} · الموصلات المرتبطة: ${row.mapped_connector_count}`));
        if (row.company) card.append(plain('p', `${row.company.name} · #${row.company.id} · ${row.company.review_status}`));
        else card.append(node('p', 'No matching company ownership', 'لا توجد ملكية شركة مطابقة'));
        return row.name;
    }
    async function load(page = 1, fresh = false) {
        if (!api || document.hidden) return;
        const filters = fresh ? collect() : applied;
        const partner = filters.scope === 'partner';
        erase();
        const ownEpoch = epoch, ownSequence = sequence, controller = new AbortController(); pending = controller;
        say('Loading private operations inventory…', 'جارٍ تحميل مخزون العمليات الخاص…');
        try {
            const data = await api('admin/operations/map?' + new URLSearchParams({...filters, page: String(page)}), 'GET', undefined, controller.signal);
            if (ownEpoch !== epoch || ownSequence !== sequence || controller.signal.aborted || document.hidden) return;
            if (!safe(data, partner)) throw Error('Unexpected map contract');
            applied = filters; currentPage = data.current_page;
            say(`Loaded ${data.data.length} of ${data.total} locations · page ${currentPage}/${data.last_page} · as of ${data.as_of}`,
                `تم تحميل ${data.data.length} من ${data.total} موقع · الصفحة ${currentPage}/${data.last_page} · وقت القراءة ${data.as_of}`);
            for (const row of data.data) {
                const card = document.createElement('article');
                const name = partner ? renderPartner(card, row) : renderPlatform(card, row);
                card.append(node('p', 'Physical availability unknown. No controls are granted by this map.', 'التوفر الفعلي غير معروف. لا تمنح الخريطة صلاحية التحكم.'));
                output.append(card);
                if (map && Math.abs(row.latitude) <= 85.05112878) {
                    const popup = document.createElement('div'); popup.append(plain('strong', name), partner
                        ? node('p', 'Partner report; physical availability unknown', 'تقرير شريك؛ التوفر الفعلي غير معروف')
                        : node('p', 'Inventory location; physical availability unknown', 'موقع مخزون؛ التوفر الفعلي غير معروف'));
                    const marker = window.L.marker([row.latitude, row.longitude]).bindPopup(popup).addTo(markers);
                    marker.on('click', () => card.scrollIntoView({block: 'nearest'}));
                }
            }
            const pages = document.createElement('nav');
            if (currentPage > 1) pages.append(button('Previous page', 'الصفحة السابقة', () => load(currentPage - 1)));
            if (currentPage < data.last_page) pages.append(button('Next page', 'الصفحة التالية', () => load(currentPage + 1)));
            output.append(pages);
        } catch (error) {
            if (ownEpoch === epoch && ownSequence === sequence && !controller.signal.aborted) {
                erase(); say('Operations inventory could not load. Refresh explicitly to retry.', 'تعذر تحميل مخزون العمليات. أعد التحديث صراحة للمحاولة.');
            }
        }
    }
    function setActor(actor, request) {
        clear(); if (actor?.role !== 'admin') return; api = request;
        root.append(node('p', 'Platform inventory and published partner locations, 25 per page. Source reports describe catalog or simulator evidence. Coordinates beyond the map projection remain in the list.',
            'مخزون المنصة ومواقع الشركاء المنشورة، 25 موقعاً في الصفحة. تعرض التقارير أدلة الكتالوج أو المحاكي. تبقى الإحداثيات خارج إسقاط الخريطة في القائمة.'));
        const form = document.createElement('form'), fieldLabels = {}; fields = {};
        for (const [key, en, ar] of [['q','Station name','اسم المحطة'], ['country','Country code','رمز الدولة'], ['company_id','Company ID','معرف الشركة']]) {
            const label = document.createElement('label'), input = document.createElement('input'); input.name = key; input.maxLength = key === 'q' ? 80 : key === 'country' ? 2 : 16;
            fieldLabels[key] = node('span', en, ar); label.append(fieldLabels[key], input); form.append(label); fields[key] = input;
        }
        const statusLabel = document.createElement('label'), select = document.createElement('select'); select.name = 'recent_source_status';
        select.append(Object.assign(node('option', 'Any source report', 'أي تقرير مصدر'), {value: ''}));
        const states = {Available:'متاح', Occupied:'مشغول', Preparing:'قيد التجهيز', Charging:'قيد الشحن', SuspendedEV:'متوقف من المركبة', SuspendedEVSE:'متوقف من الشاحن', Finishing:'قيد الإنهاء', Reserved:'محجوز', Unavailable:'غير متاح', Faulted:'عطل'};
        for (const [value, ar] of Object.entries(states)) select.append(Object.assign(node('option', value, ar), {value}));
        statusLabel.append(node('span', 'Recent simulator report (5 minutes)', 'تقرير المحاكي الحديث (5 دقائق)'), select); fields.recent_source_status = select; form.append(statusLabel);
        const scopeLabel = document.createElement('label'), scope = document.createElement('select'); scope.name = 'scope';
        scope.append(Object.assign(node('option','Platform inventory','مخزون المنصة'), {value: ''}),
            Object.assign(node('option','Published partner locations','مواقع الشركاء المنشورة'), {value: 'partner'}));
        scopeLabel.append(node('span','Map source','مصدر الخريطة'), scope); form.append(scopeLabel); fields.scope = scope;
        scope.onchange = () => {
            if (!api || fields.scope !== scope) return;
            const partner = scope.value === 'partner';
            fields.company_id.disabled = partner;
            select.disabled = partner;
            fields.country.value = '';
            fields.country.maxLength = partner ? 3 : 2;
            for (const [key, en, ar] of partner
                ? [['q','Station or provider name','اسم المحطة أو المزود'], ['country','Country code (3 letters)','رمز الدولة (3 حروف)']]
                : [['q','Station name','اسم المحطة'], ['country','Country code','رمز الدولة']]) {
                const label = fieldLabels[key];
                label.dataset.en = en; label.dataset.ar = ar; label.textContent = text(en, ar);
            }
            applied = {}; currentPage = 1; erase();
            say('Search to load the selected map source.', 'ابحث لتحميل مصدر الخريطة المحدد.');
        };
        viewport = document.createElement('input'); viewport.type = 'checkbox'; viewport.disabled = true;
        const viewportLabel = document.createElement('label'); viewportLabel.append(viewport, node('span', 'Search only the current map area', 'البحث في منطقة الخريطة الحالية فقط')); form.append(viewportLabel);
        const submit = node('button', 'Search / refresh inventory', 'بحث / تحديث المخزون'); submit.type = 'submit'; form.append(submit);
        form.onsubmit = event => { event.preventDefault(); load(1, true); }; root.append(form);
        root.append(node('p', 'Enable tiles only if you consent to sending your IP and viewed area to OpenStreetMap. No location lookup is performed. Only the loaded result page is plotted; changing the map does not automatically search.',
            'فعّل الخلفية فقط إذا وافقت على إرسال عنوان الإنترنت والمنطقة المعروضة إلى OpenStreetMap. لا يُحدد موقعك. تُرسم صفحة النتائج المحملة فقط؛ تحريك الخريطة لا يبحث تلقائياً.'));
        canvas = document.createElement('div'); canvas.style.height = '360px'; canvas.hidden = true; canvas.setAttribute('aria-label', 'Admin inventory map / خريطة مخزون المسؤول');
        enable = button('Enable map tiles', 'تفعيل خلفية الخريطة', () => {
            if (map) return;
            if (!window.L) { say('Interactive map is unavailable. Use the inventory list.', 'الخريطة التفاعلية غير متاحة. استخدم قائمة المخزون.'); return; }
            canvas.hidden = false; map = window.L.map(canvas).setView([25,55], 4);
            window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'© OpenStreetMap contributors', maxZoom:19}).addTo(map);
            markers = window.L.layerGroup().addTo(map); viewport.disabled = false; enable.hidden = true; disable.hidden = false;
            erase(); say('Map enabled. Search explicitly to plot a result page.', 'تم تفعيل الخريطة. ابحث صراحة لرسم صفحة النتائج.');
        });
        disable = button('Disable map tiles', 'إيقاف خلفية الخريطة', () => { erase(); stopMap(); say('Map disabled. Search to read the list.', 'الخريطة متوقفة. ابحث لقراءة القائمة.'); }); disable.hidden = true;
        status = document.createElement('p'); status.setAttribute('role','status'); status.setAttribute('aria-live','polite'); output = document.createElement('div');
        root.append(enable, disable, canvas, status, output); say('Search to load private inventory.', 'ابحث لتحميل المخزون الخاص.');
    }
    document.addEventListener('visibilitychange', () => { if (document.hidden && api) { erase(); stopMap(); say('Private results cleared. Search again when ready.', 'تم مسح النتائج الخاصة. ابحث مجدداً عندما تكون مستعداً.'); } });
    window.addEventListener('pagehide', clear);
    return {setActor};
})();
