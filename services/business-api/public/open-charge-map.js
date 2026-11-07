/* Source-only OCM lookup. No automatic location/availability/control claims. */
(() => {
    const anchor = document.getElementById('roaming-discovery');
    if (!anchor) return;
    const ar = () => document.documentElement.lang === 'ar';
    const text = (tag, en, arabic) => {
        const node = document.createElement(tag);
        node.dataset.en = en;
        node.dataset.ar = arabic;
        node.textContent = ar() ? arabic : en;
        return node;
    };
    const root = document.createElement('section');
    root.id = 'open-charge-map-discovery';
    root.hidden = true;
    const form = document.createElement('form');
    function field(name, min, max, en, arabic) {
        const container = document.createElement('label');
        const value = document.createElement('input');
        value.name = name;
        value.type = 'number';
        value.min = String(min);
        value.max = String(max);
        value.step = 'any';
        value.required = true;
        container.append(text('span', en, arabic), value);
        form.append(container);
        return value;
    }
    const lat = field('ocm_latitude', -90, 90, 'Latitude', 'خط العرض');
    const lng = field('ocm_longitude', -180, 180, 'Longitude', 'خط الطول');
    const radius = field('ocm_radius_km', 1, 200, 'Radius in km', 'النطاق بالكيلومتر');
    radius.value = '25';
    const countryRow = document.createElement('label');
    const country = document.createElement('input');
    country.name = 'ocm_country';
    country.type = 'text';
    country.maxLength = 2;
    country.pattern = '[A-Z]{2}';
    country.autocomplete = 'off';
    country.oninput = () => { country.value = country.value.toUpperCase().replace(/[^A-Z]/g, ''); };
    countryRow.append(text('span', 'Country ISO2 (optional)', 'رمز الدولة من حرفين (اختياري)'), country);
    form.append(countryRow);
    const powerRow = document.createElement('label');
    const minimumPower = document.createElement('input');
    minimumPower.name = 'ocm_min_reported_power_kw';
    minimumPower.type = 'number';
    minimumPower.min = '1';
    minimumPower.max = '1000';
    minimumPower.step = 'any';
    powerRow.append(text('span', 'Minimum reported connector power, kW (optional)', 'القدرة الدنيا المبلغ عنها للموصل، كيلوواط (اختياري)'), minimumPower);
    const connectorRow = document.createElement('label');
    const connector = document.createElement('input');
    connector.name = 'ocm_reported_connector';
    connector.type = 'text';
    connector.maxLength = 80;
    connectorRow.append(text('span', 'Reported connector type (optional, exact)', 'نوع الموصل المبلغ عنه (اختياري، تطابق تام)'), connector);
    form.append(powerRow, connectorRow);
    const consent = document.createElement('input');
    consent.type = 'checkbox';
    consent.required = true;
    const consentRow = document.createElement('label');
    consentRow.append(consent, text('span',
        'I allow these coordinates to be sent to Open Charge Map via this server for one search.',
        'أسمح بإرسال هذه الإحداثيات إلى خريطة الشحن المفتوحة عبر هذا الخادم لبحث واحد.'));
    const copy = text('button', 'Copy chosen map point', 'نسخ نقطة الخريطة المحددة');
    copy.type = 'button';
    const search = text('button', 'Search open-licensed locations', 'البحث في المواقع ذات البيانات المفتوحة');
    search.type = 'submit';
    form.append(consentRow, copy, search);
    const state = document.createElement('p');
    state.setAttribute('role', 'status');
    const results = document.createElement('div');
    results.className = 'station-grid';
    root.append(
        text('h2', 'Open Charge Map — discovery only', 'خريطة الشحن المفتوحة — للاكتشاف فقط'),
        text('p', 'Open-licensed crowd reports. No live availability, reservations or charging controls. Each source retains its own license.',
            'تقارير مجتمعية ذات بيانات مفتوحة. لا توفر مباشر أو حجوزات أو تحكم بالشحن. يظهر الترخيص الخاص بكل مصدر.'),
        form, state, results,
    );
    anchor.after(root);
    let enabled = false, generation = 0, current, lastResult = null;
    function announce(en, arabic) {
        state.dataset.en = en;
        state.dataset.ar = arabic;
        state.textContent = ar() ? arabic : en;
    }
    copy.onclick = () => {
        const source = document.getElementById('filters');
        const a = String(source?.elements.latitude?.value || '');
        const b = String(source?.elements.longitude?.value || '');
        if (!a.trim() || !b.trim() || !Number.isFinite(Number(a)) || !Number.isFinite(Number(b))) return;
        lat.value = a;
        lng.value = b;
        // Copy does not authorize or trigger an external provider call.
        announce('Coordinates copied locally; confirm consent to search.', 'نُسخت الإحداثيات محلياً؛ أكد الموافقة للبحث.');
    };
    const secure = (r) => r && /^ocm:[1-9]\d*$/.test(r.id || '') &&
        r.source_status_evidence === 'OPEN_CHARGE_MAP_DISCOVERY_ONLY' &&
        r.reported_operational_evidence === 'OPEN_CHARGE_MAP_CROWD_STATUS_UNVERIFIED' &&
        (r.reported_operational === null || typeof r.reported_operational === 'boolean') &&
        r.reported_access_evidence === 'OPEN_CHARGE_MAP_CROWD_ACCESS_UNVERIFIED' &&
        typeof r.reported_access === 'string' &&
        (r.reported_membership_required === null || typeof r.reported_membership_required === 'boolean') &&
        (r.reported_access_key_required === null || typeof r.reported_access_key_required === 'boolean') &&
        r.availability === 'unknown' && r.remote_control === false &&
        r.reservable === false && r.billing_eligible === false &&
        typeof r.data_license === 'string' && !!r.data_license.trim() &&
        typeof r.attribution === 'string' && !!r.attribution.trim() &&
        r.source_url === 'https://openchargemap.org/site/poi/details/' + r.id.slice(4) &&
        Number.isFinite(Number(r.latitude)) && Number.isFinite(Number(r.longitude)) &&
        Math.abs(Number(r.latitude)) <= 90 && Math.abs(Number(r.longitude)) <= 180 &&
        Number.isFinite(Number(r.distance_km)) && Number(r.distance_km) >= 0 &&
        Number(r.distance_km) <= 200.001 && Array.isArray(r.connections) &&
        r.connections.length <= 20 && r.connections.every(c => c &&
            c.availability === 'unknown' && typeof c.reported_connector_type === 'string' &&
            !Object.hasOwn(c, 'remote_control') && !Object.hasOwn(c, 'reservable'));
    function render(data) {
        if (!data || data.provider !== 'open_charge_map' ||
            data.evidence !== 'OPEN_LICENSED_DISCOVERY_ONLY' ||
            data.availability !== 'unknown' || data.remote_control !== false ||
            data.reservable !== false || data.billing_eligible !== false ||
            data.license_policy !== 'PER_RECORD_ATTRIBUTION_REQUIRED' ||
            !Array.isArray(data.results) || data.results.length > 25 ||
            !data.results.every(secure)) throw Error('Unsafe source data');
        lastResult = data;
        results.replaceChildren();
        for (const r of data.results) {
            const card = document.createElement('article');
            card.dataset.ocmLocationId = r.id;
            const heading = document.createElement('h3');
            heading.textContent = String(r.name || r.id);
            const place = document.createElement('p');
            place.textContent = [r.address, r.city, r.country].filter(Boolean).join(' · ');
            const distance = document.createElement('p');
            distance.textContent = (ar() ? 'المسافة المباشرة: ' : 'Straight-line distance: ') + Number(r.distance_km).toFixed(2) + ' km';
            const age = document.createElement('p');
            age.textContent = r.source_clock_evidence === 'SOURCE_CLOCK_AHEAD'
                ? (ar() ? 'توقيت المصدر في المستقبل؛ الحداثة غير مؤكدة' : 'Future source timestamp; freshness unknown')
                : Number.isSafeInteger(r.source_age_minutes) && r.source_age_minutes >= 0
                    ? (ar() ? 'عمر التقرير: ' : 'Reported source age: ') + r.source_age_minutes + ' min (not live)'
                    : (ar() ? 'وقت تحديث المصدر غير معروف' : 'Source update time unknown');
            const reportedStatus = document.createElement('p');
            reportedStatus.textContent = r.reported_operational === true
                ? (ar() ? 'وفق تقرير المجتمع كان الموقع يعمل؛ غير مؤكد وليس مباشراً' : 'Crowd-reported operational at last update; unverified and not live.')
                : r.reported_operational === false
                    ? (ar() ? 'أبلغ المجتمع أن الموقع لا يعمل؛ غير مؤكد وليس مباشراً' : 'Crowd-reported non-operational at last update; unverified and not live.')
                    : (ar() ? 'الحالة التشغيلية المبلغ عنها غير معروفة؛ ليست مباشرة' : 'Crowd operational report unavailable; not live.');
            const access = document.createElement('p');
            access.textContent = (ar() ? 'الوصول حسب تقارير المجتمع (غير مؤكد): ' : 'Crowd-reported access (unverified): ') +
                (r.reported_access || (ar() ? 'غير معروف' : 'unknown')) +
                (ar() ? ' · تحقق مع المشغل، ليست حالة مباشرة.' : ' · confirm with operator, not live.');
            const license = document.createElement('p');
            license.textContent = (ar() ? 'المصدر والترخيص: ' : 'Source/license: ') + r.attribution + ' · ' + r.data_license;
            const connectors = document.createElement('p');
            connectors.textContent = (ar() ? 'الموصلات المبلغ عنها (غير مؤكدة): ' : 'Reported connectors (unverified): ') +
                (r.connections.length ? r.connections.map(c => c.reported_connector_type +
                    (Number.isFinite(c.reported_power_kw) ? ' · ' + c.reported_power_kw + ' kW' : '')).join(' / ') : 'unknown');
            const link = text('a', 'View source and license', 'عرض المصدر والترخيص');
            link.href = r.source_url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            card.append(heading, place, distance, age, reportedStatus, access, license, connectors,
                text('p', 'Availability unknown. No remote charging, booking or payment.',
                    'التوفر غير معروف. لا شحن أو حجز أو دفع عن بُعد.'), link);
            results.append(card);
        }
        announce('Filtered from at most 25 licensed crowd reports; more matching sites may exist. No live status verified.',
            'نتائج مرشحة من 25 تقريراً مجتمعياً مرخصاً كحد أقصى؛ قد توجد مواقع مطابقة أخرى. لم يتم التحقق من التوفر المباشر.');
        // Publish markers only after the entire vetted list renders.
        window.dispatchEvent(new CustomEvent("open-charge-map-data", { detail: data.results }));
    }
    const clear = () => {
        generation++;
        current?.abort();
        current = null;
        search.disabled = false;
        lastResult = null;
        results.replaceChildren();
        window.dispatchEvent(new CustomEvent("open-charge-map-data", { detail: [] }));
        consent.checked = false;
        lat.value = lng.value = '';
        country.value = '';
        minimumPower.value = '';
        connector.value = '';
        radius.value = '25';
        announce('', '');
    };
    form.onsubmit = async e => {
        e.preventDefault();
        if (!enabled || !consent.checked || !form.checkValidity()) return;
        const latitude = Number(lat.value), longitude = Number(lng.value), km = Number(radius.value);
        if (![latitude, longitude, km].every(Number.isFinite) ||
            Math.abs(latitude) > 90 || Math.abs(longitude) > 180 || km < 1 || km > 200) return;
        const minPower = minimumPower.value.trim() ? Number(minimumPower.value) : null;
        if (minPower !== null && (!Number.isFinite(minPower) || minPower < 1 || minPower > 1000)) return;
        const epoch = ++generation;
        current?.abort();
        current = new AbortController();
        lastResult = null;
        results.replaceChildren();
        window.dispatchEvent(new CustomEvent("open-charge-map-data", { detail: [] }));
        search.disabled = true;
        announce('Searching licensed sources…', 'يجري البحث في المصادر المرخصة…');
        try {
            const response = await fetch('/api/discovery/open-charge-map/search', {
                method: 'POST', credentials: 'same-origin', redirect: 'error', cache: 'no-store',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({consent:true,latitude,longitude,radius_km:km,
                    ...(country.value.trim() ? {country:country.value.trim()} : {}),
                    ...(minPower !== null ? {min_reported_power_kw:minPower} : {}),
                    ...(connector.value.trim() ? {reported_connector:connector.value.trim()} : {})}),
                signal: AbortSignal.any([current.signal, AbortSignal.timeout(15000)]),
            });
            if (epoch !== generation) return;
            if (!response.ok) throw Error('Source unavailable');
            const responseBody = await response.json();
            // A logout/identity change can race with streamed JSON parsing:
            // never resurrect a revoked source after source generation changes.
            if (epoch !== generation) return;
            render(responseBody);
        } catch (_) {
            if (epoch !== generation) return;
            results.replaceChildren();
            lastResult = null;
            announce('Licensed source unavailable or invalid; manual station search is still available.',
                'المصدر المرخص غير متاح أو غير صالح؛ يبقى البحث اليدوي متاحاً.');
        } finally {
            if (epoch === generation) {
                search.disabled = false;
                consent.checked = false;
                current = null;
            }
        }
    };
    window.addEventListener('catalog-map-clear', clear);
    window.addEventListener('ev:locale', () => {
        // Refresh static distance/source annotations without issuing another
        // query or losing the original validation/attribution evidence.
        if (lastResult) render(lastResult);
        else if (state.dataset.en !== undefined)
            state.textContent = ar() ? state.dataset.ar : state.dataset.en;
    });
    void (async () => {
        try {
            const res = await fetch('/api/discovery/open-charge-map/capabilities', {
                method: 'GET', credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(5000),
            });
            if (!res.ok) return;
            const caps = await res.json();
            if (caps?.provider === 'open_charge_map' && caps.enabled === true &&
                caps.requires_explicit_consent === true && caps.availability === 'unknown' &&
                caps.remote_control === false && caps.reservable === false) {
                enabled = true;
                root.hidden = false;
            }
        } catch (_) { /* Disabled or unreachable sources remain hidden. */ }
    })();
})();
