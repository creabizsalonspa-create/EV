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
    const operatorRow = document.createElement('label');
    const operatorQuery = document.createElement('input');
    operatorQuery.name = 'ocm_operator_contains';
    operatorQuery.type = 'text';
    operatorQuery.maxLength = 120;
    operatorRow.append(text('span', 'Reported operator contains (optional, unverified)',
        'اسم المشغل حسب التقرير يحتوي على (اختياري، غير مؤكد)'), operatorQuery);
    const addressRow = document.createElement('label');
    const addressQuery = document.createElement('input');
    addressQuery.name = 'ocm_address_contains';
    addressQuery.type = 'text';
    addressQuery.maxLength = 150;
    addressRow.append(text('span', 'Reported address or city contains (optional)',
        'العنوان أو المدينة حسب التقرير (اختياري)'), addressQuery);
    const accessRow = document.createElement('label');
    const accessQuery = document.createElement('input');
    accessQuery.name = 'ocm_access_contains';
    accessQuery.type = 'text';
    accessQuery.maxLength = 120;
    accessRow.append(text('span', 'Reported access contains (optional, unverified)',
        'الوصول حسب التقرير يحتوي على (اختياري، غير مؤكد)'), accessQuery);
    const reportedStatusRow = document.createElement('label');
    const reportedStatusFilter = document.createElement('select');
    reportedStatusFilter.name = 'ocm_reported_operational';
    for (const [value, english, arabic] of [
        ['', 'Any crowd-reported operating state', 'أي حالة تشغيل حسب التقرير'],
        ['true', 'Reported operational at last update (not live)', 'أبلغ عن تشغيل عند آخر تحديث (ليس مباشراً)'],
        ['false', 'Reported operational issue at last update (not live)', 'أبلغ عن مشكلة تشغيل (ليس مباشراً)'],
    ]) {
        const choice = text('option', english, arabic);
        choice.value = value;
        reportedStatusFilter.append(choice);
    }
    reportedStatusRow.append(text('span', 'Crowd-reported operating state (unverified)',
        'حالة التشغيل المبلغ عنها (غير مؤكدة)'), reportedStatusFilter);
    const membershipRow = document.createElement('label');
    const membershipFilter = document.createElement('select');
    membershipFilter.name = 'ocm_reported_membership_required';
    for (const [value, english, arabic] of [
        ['', 'Any reported membership requirement', 'أي اشتراط عضوية حسب التقرير'],
        ['true', 'Membership reportedly required (unverified)', 'عضوية مطلوبة حسب التقرير (غير مؤكد)'],
        ['false', 'Membership reportedly not required (unverified)', 'عضوية غير مطلوبة حسب التقرير (غير مؤكد)'],
    ]) {
        const choice = text('option', english, arabic);
        choice.value = value;
        membershipFilter.append(choice);
    }
    membershipRow.append(text('span', 'Crowd-reported membership requirement',
        'اشتراط العضوية المبلغ عنه'), membershipFilter);
    form.append(operatorRow, addressRow, accessRow, reportedStatusRow, membershipRow);
    const ageRow = document.createElement('label');
    const maxSourceAge = document.createElement('input');
    maxSourceAge.name = 'ocm_max_source_age_hours';
    maxSourceAge.type = 'number';
    maxSourceAge.min = '1';
    maxSourceAge.max = '168';
    maxSourceAge.step = '1';
    ageRow.append(text('span', 'Maximum crowd-report age in hours (optional; not live)',
        'الحد الأقصى لعمر التقرير المجتمعي بالساعات (اختياري؛ ليس مباشراً)'), maxSourceAge);
    form.append(ageRow);
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
    const federatedSearch = text('button', 'Search nearby across published and licensed sources',
        'البحث عن المواقع القريبة في المصادر المنشورة والمرخصة');
    federatedSearch.type = 'submit';
    form.append(consentRow, copy, search, federatedSearch,
        text('p', 'Searching across sources requires sign-in and uses only coordinates, radius and optional country (AE, US or CA).',
            'يتطلب البحث في جميع المصادر تسجيل الدخول، ويستخدم الإحداثيات والنطاق والدولة الاختيارية فقط (AE أو US أو CA).'));
    const state = document.createElement('p');
    state.setAttribute('role', 'status');
    const publishedResults = document.createElement('div');
    publishedResults.id = 'federated-published-results';
    const results = document.createElement('div');
    results.className = 'station-grid';
    root.append(
        text('h2', 'Open Charge Map — discovery only', 'خريطة الشحن المفتوحة — للاكتشاف فقط'),
        text('p', 'Open-licensed crowd reports. No live availability, reservations or charging controls. Each source retains its own license.',
            'تقارير مجتمعية ذات بيانات مفتوحة. لا توفر مباشر أو حجوزات أو تحكم بالشحن. يظهر الترخيص الخاص بكل مصدر.'),
        form, state, publishedResults, results,
    );
    anchor.after(root);
    let enabled = false, generation = 0, current, lastResult = null, lastFederated = null;
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
        // A programmatic coordinate change must invalidate prior source
        // records too; it never authorizes another external provider call.
        lat.dispatchEvent(new Event('input', { bubbles: true }));
        // Copy does not authorize or trigger an external provider call.
        announce('Coordinates copied locally; confirm consent to search.', 'نُسخت الإحداثيات محلياً؛ أكد الموافقة للبحث.');
    };
    const secure = (r) => r && /^ocm:[1-9]\d*$/.test(r.id || '') &&
        r.source_status_evidence === 'OPEN_CHARGE_MAP_DISCOVERY_ONLY' &&
        r.reported_operational_evidence === 'OPEN_CHARGE_MAP_CROWD_STATUS_UNVERIFIED' &&
        (r.reported_operational === null || typeof r.reported_operational === 'boolean') &&
        r.reported_access_evidence === 'OPEN_CHARGE_MAP_CROWD_ACCESS_UNVERIFIED' &&
        r.reported_cost_evidence === 'OPEN_CHARGE_MAP_CROWD_PRICE_UNVERIFIED' &&
        typeof r.reported_cost_note === 'string' && Array.from(r.reported_cost_note).length <= 240 &&
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
        const quality = data.quality_sample;
        if (quality !== undefined) {
            const count = data.results.length;
            const keys = ['returned_count', 'source_time_unknown_or_future_count',
                'source_older_than_24h_count', 'operational_report_unknown_count'];
            if (!quality || quality.evidence !== 'FILTERED_LICENSED_CROWD_SAMPLE_ONLY' ||
                quality.complete_geographic_coverage !== false || quality.live_status_verified !== false ||
                quality.max_source_sample !== 25 ||
                !keys.every(key => Number.isSafeInteger(quality[key]) &&
                    quality[key] >= 0 && quality[key] <= count) ||
                quality.returned_count !== count) throw Error('Unsafe source quality summary');
        }
        lastResult = data;
        results.replaceChildren();
        if (quality !== undefined) {
            const summary = text('p',
                'Quality of ' + quality.returned_count + ' returned filtered crowd reports (maximum 25 source records): ' +
                quality.source_time_unknown_or_future_count + ' unknown/future timestamps; ' +
                quality.source_older_than_24h_count + ' older than 24 hours; ' +
                quality.operational_report_unknown_count + ' missing operational reports. This sample is not full geographic coverage or live charger availability.',
                'جودة ' + quality.returned_count + ' من تقارير المجتمع المعروضة بعد التصفية (٢٥ سجلاً مصدرياً كحد أقصى): ' +
                quality.source_time_unknown_or_future_count + ' أوقات مفقودة أو مستقبلية؛ ' +
                quality.source_older_than_24h_count + ' أقدم من ٢٤ ساعة؛ ' +
                quality.operational_report_unknown_count + ' دون تقرير تشغيل. هذه عينة وليست تغطية جغرافية شاملة أو توافراً مباشراً للشواحن.');
            summary.className = 'ocm-quality-sample';
            results.append(summary);
        }
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
            const operatorInfo = document.createElement('p');
            operatorInfo.textContent = (ar() ? 'المشغل حسب التقرير (غير مؤكد): ' : 'Reported operator (unverified): ') +
                (r.operator_label || (ar() ? 'غير معروف' : 'unknown'));
            const access = document.createElement('p');
            access.textContent = (ar() ? 'الوصول حسب تقارير المجتمع (غير مؤكد): ' : 'Crowd-reported access (unverified): ') +
                (r.reported_access || (ar() ? 'غير معروف' : 'unknown')) +
                (ar() ? ' · تحقق مع المشغل، ليست حالة مباشرة.' : ' · confirm with operator, not live.');
            const membership = document.createElement('p');
            membership.textContent = r.reported_membership_required === true
                ? (ar() ? 'العضوية مطلوبة حسب تقرير المجتمع؛ غير مؤكدة.' : 'Crowd report: membership required; unverified.')
                : r.reported_membership_required === false
                    ? (ar() ? 'العضوية غير مطلوبة حسب تقرير المجتمع؛ غير مؤكدة.' : 'Crowd report: membership not required; unverified.')
                    : (ar() ? 'اشتراط العضوية غير معروف.' : 'Crowd-reported membership requirement unknown.');
            const cost = document.createElement('p');
            cost.textContent = (ar() ? 'ملاحظة التكلفة حسب تقرير المجتمع (غير مؤكدة وليست عرض سعر): ' : 'Crowd-reported cost note (unverified, not a quote): ') +
                (r.reported_cost_note || (ar() ? 'غير متاحة' : 'unavailable')) +
                (ar() ? ' · تحقق من الأسعار مع المشغل.' : ' · Confirm prices with the operator.');
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
            const directions = text('a', 'Open source location in Google Maps', 'فتح موقع المصدر في خرائط Google');
            directions.href = 'https://www.google.com/maps/dir/?api=1&destination=' +
                encodeURIComponent(String(Number(r.latitude)) + ',' + String(Number(r.longitude)));
            directions.target = '_blank';
            directions.rel = 'noopener noreferrer';
            directions.onclick = event => {
                if (!window.confirm(ar()
                    ? 'هل تريد إرسال موقع المصدر إلى خرائط Google؟ تطبق سياسة الخصوصية للمزود، ولم يتم التحقق من الطريق أو زمنه أو توفر الشاحن.'
                    : 'Send this crowd-reported location to Google Maps? Its privacy policy applies. No route, ETA or live charger availability is verified.'))
                    event.preventDefault();
            };
            card.append(heading, place, distance, age, reportedStatus, operatorInfo, access, membership, cost, license, connectors,
                text('p', 'Availability unknown. No remote charging, booking or payment.',
                    'التوفر غير معروف. لا شحن أو حجز أو دفع عن بُعد.'), link, directions);
            results.append(card);
        }
        announce('Filtered from at most 25 licensed crowd reports; more matching sites may exist. No live status verified.',
            'نتائج مرشحة من 25 تقريراً مجتمعياً مرخصاً كحد أقصى؛ قد توجد مواقع مطابقة أخرى. لم يتم التحقق من التوفر المباشر.');
        // Publish markers only after the entire vetted list renders.
        window.dispatchEvent(new CustomEvent("open-charge-map-data", { detail: data.results }));
    }
    function renderFederated(data) {
        const page = data?.published;
        const source = data?.licensed;
        if (data?.evidence !== 'FEDERATED_GEOGRAPHIC_DISCOVERY_ONLY' ||
            data.distance_basis !== 'great_circle' || data.live_status_verified !== false ||
            data.complete_geographic_coverage !== false || data.combined_total !== null ||
            !Array.isArray(page?.data) || page.data.length > 25 || page.per_page !== 25 ||
            page.current_page !== 1 || !Number.isSafeInteger(page.last_page) || page.last_page < 1 ||
            !Number.isSafeInteger(page.total) || page.total < page.data.length ||
            !['observed', 'unavailable'].includes(source?.state) ||
            (source.state === 'unavailable' && source.sample !== null)) throw Error('Invalid federated results');

        const heading = text('h3', 'Published nearby locations', 'المواقع القريبة المنشورة');
        const list = document.createElement('ul');
        const seen = new Set();
        for (const row of page.data) {
            const station = row?.station;
            const partner = row?.location;
            const id = row?.kind === 'catalog' ? station?.id : row?.record_id;
            if (!Number.isSafeInteger(id) || id < 1 || row.key !== row.kind + ':' + id ||
                seen.has(row.key)) throw Error('Invalid published location');
            seen.add(row.key);
            if (row.kind === 'catalog' && typeof station?.name === 'string') {
                list.append(text('li', 'Platform catalog · ' + station.name, 'كتالوج المنصة · ' + station.name));
            } else if (row.kind === 'partner' && row.evidence === 'PARTNER_REPORTED_UNVERIFIED' &&
                row.availability === 'unknown' && row.reservable === false && row.remote_control === false &&
                partner && (typeof partner.name === 'string' || typeof partner.id === 'string')) {
                const name = partner.name || partner.id;
                list.append(text('li', 'Partner report · ' + name, 'تقرير الشريك · ' + name));
            } else throw Error('Invalid published source');
        }

        if (source.state === 'observed') {
            render(source.sample);
            if (source.sample.results.length === 0) {
                results.append(text('p', 'No licensed crowd reports matched this search.',
                    'لم تطابق أي تقارير مجتمعية مرخصة هذا البحث.'));
            }
        } else {
            announce('Licensed crowd reports are unavailable. Published nearby results are shown below.',
                'تقارير المجتمع المرخصة غير متاحة. تظهر النتائج القريبة المنشورة أدناه.');
        }
        publishedResults.replaceChildren(heading, text('p',
            `Showing ${page.data.length} of ${page.total} published results. Physical availability is unverified.`,
            `عرض ${page.data.length} من ${page.total} نتيجة منشورة. التوفر الفعلي غير مؤكد.`), list);
        lastFederated = data;
    }
    const clear = () => {
        generation++;
        current?.abort();
        current = null;
        search.disabled = federatedSearch.disabled = false;
        lastResult = lastFederated = null;
        publishedResults.replaceChildren();
        results.replaceChildren();
        window.dispatchEvent(new CustomEvent("open-charge-map-data", { detail: [] }));
        consent.checked = false;
        lat.value = lng.value = '';
        country.value = '';
        minimumPower.value = '';
        connector.value = '';
        operatorQuery.value = '';
        addressQuery.value = '';
        accessQuery.value = '';
        reportedStatusFilter.value = '';
        membershipFilter.value = '';
        maxSourceAge.value = '';
        radius.value = '25';
        announce('', '');
    };
    // Results are tied to the *submitted* query, not to whatever filters
    // happen to be visible afterward. Editing any search field invalidates
    // both the list and map immediately; no additional coordinates are sent.
    const invalidateOnEdit = event => {
        if (event.target === consent || (!current && !lastResult && !lastFederated)) return;
        generation++;
        current?.abort();
        current = null;
        lastResult = lastFederated = null;
        search.disabled = federatedSearch.disabled = false;
        publishedResults.replaceChildren();
        results.replaceChildren();
        window.dispatchEvent(new CustomEvent('open-charge-map-data', { detail: [] }));
        consent.checked = false;
        announce('Filters changed. Results cleared; review and consent to search again.',
            'تغيرت عوامل التصفية. حُذفت النتائج؛ راجع البحث ووافق من جديد.');
    };
    form.addEventListener('input', invalidateOnEdit);
    form.addEventListener('change', invalidateOnEdit);
    form.onsubmit = async e => {
        e.preventDefault();
        if (!enabled || !consent.checked || !form.checkValidity()) return;
        const latitude = Number(lat.value), longitude = Number(lng.value), km = Number(radius.value);
        if (![latitude, longitude, km].every(Number.isFinite) ||
            Math.abs(latitude) > 90 || Math.abs(longitude) > 180 || km < 1 || km > 200) return;
        const minPower = minimumPower.value.trim() ? Number(minimumPower.value) : null;
        if (minPower !== null && (!Number.isFinite(minPower) || minPower < 1 || minPower > 1000)) return;
        const maxAge = maxSourceAge.value.trim() ? Number(maxSourceAge.value) : null;
        if (maxAge !== null && (!Number.isSafeInteger(maxAge) || maxAge < 1 || maxAge > 168)) return;
        const federated = e.submitter === federatedSearch;
        const geographicQuery = {consent:true,latitude,longitude,radius_km:km,
            ...(country.value.trim() ? {country:country.value.trim()} : {})};
        const sourceFilters = {
            ...(minPower !== null ? {min_reported_power_kw:minPower} : {}),
            ...(connector.value.trim() ? {reported_connector:connector.value.trim()} : {}),
            ...(operatorQuery.value.trim() ? {operator_contains:operatorQuery.value.trim()} : {}),
            ...(addressQuery.value.trim() ? {address_contains:addressQuery.value.trim()} : {}),
            ...(accessQuery.value.trim() ? {access_contains:accessQuery.value.trim()} : {}),
            ...(membershipFilter.value ? {reported_membership_required:membershipFilter.value === 'true'} : {}),
            ...(reportedStatusFilter.value ? {reported_operational:reportedStatusFilter.value === 'true'} : {}),
            ...(maxAge !== null ? {max_source_age_hours:maxAge} : {}),
        };
        if (federated && (Object.keys(sourceFilters).length ||
            (geographicQuery.country && !['AE', 'US', 'CA'].includes(geographicQuery.country)))) {
            consent.checked = false;
            announce('Clear crowd-report filters and use AE, US or CA (or leave country blank). Then confirm consent.',
                'امسح مرشحات تقارير المجتمع واستخدم AE أو US أو CA (أو اترك الدولة فارغة). ثم أكد الموافقة.');
            return;
        }
        const epoch = ++generation;
        current?.abort();
        current = new AbortController();
        lastResult = lastFederated = null;
        publishedResults.replaceChildren();
        results.replaceChildren();
        window.dispatchEvent(new CustomEvent("open-charge-map-data", { detail: [] }));
        search.disabled = federatedSearch.disabled = true;
        announce('Searching nearby sources…', 'يجري البحث في المصادر القريبة…');
        try {
            const endpoint = federated ? '/api/discovery/federated-nearby' : '/api/discovery/open-charge-map/search';
            const response = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin', redirect: 'error', cache: 'no-store',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify(federated ? geographicQuery : {...geographicQuery, ...sourceFilters}),
                signal: AbortSignal.any([current.signal, AbortSignal.timeout(15000)]),
            });
            if (epoch !== generation) return;
            if (!response.ok) throw Error('Source unavailable');
            const responseBody = await response.json();
            // A logout/identity change can race with streamed JSON parsing:
            // never resurrect a revoked source after source generation changes.
            if (epoch !== generation) return;
            if (federated) renderFederated(responseBody);
            else render(responseBody);
        } catch (_) {
            if (epoch !== generation) return;
            results.replaceChildren();
            lastResult = null;
            if (federated) {
                announce('Nearby search is unavailable. Sign in to search across sources, or use the published catalog.',
                    'البحث القريب غير متاح. سجّل الدخول للبحث في جميع المصادر أو استخدم الكتالوج المنشور.');
            } else {
                announce('Licensed source unavailable or invalid; manual station search is still available.',
                    'المصدر المرخص غير متاح أو غير صالح؛ يبقى البحث اليدوي متاحاً.');
            }
        } finally {
            if (epoch === generation) {
                search.disabled = federatedSearch.disabled = false;
                consent.checked = false;
                current = null;
            }
        }
    };
    window.addEventListener('catalog-map-clear', clear);
    window.addEventListener('ev:locale', () => {
        // Refresh static distance/source annotations without issuing another
        // query or losing the original validation/attribution evidence.
        if (lastFederated) renderFederated(lastFederated);
        else if (lastResult) render(lastResult);
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
