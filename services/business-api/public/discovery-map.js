/* Self-hosted Leaflet. Tiles load only after explicit consent; no API credentials. */
(() => {
    const root = document.getElementById("discovery-map");
    if (!root || !window.L) return;
    const text = (en, ar) => (document.documentElement.lang === "ar" ? ar : en);
    const make = (tag, en, ar) => {
        const node = document.createElement(tag);
        node.dataset.en = en;
        node.dataset.ar = ar;
        node.textContent = text(en, ar);
        return node;
    };
    const intro = make(
        "p",
        "Map tiles are provided by OpenStreetMap. Enabling the map sends your IP and viewed area to its tile service. Only the loaded station and partner pages are shown, not every site in view. Partner locations are unverified and cannot be booked or controlled.",
        "توفر OpenStreetMap خرائط الخلفية. عند تفعيل الخريطة يُرسل عنوان الإنترنت والمنطقة المعروضة إلى مزود الخرائط. تظهر صفحات المحطات والشركاء المحملة فقط، وليس كل المواقع. مواقع الشركاء غير متحقق منها ولا يمكن حجزها أو التحكم بها.",
    );
    const enable = make(
        "button",
        "Enable interactive map",
        "تفعيل الخريطة التفاعلية",
    );
    const locate = make(
        "button",
        "Use my location once",
        "استخدام موقعي مرة واحدة",
    );
    const disable = make("button", "Disable interactive map", "إيقاف الخريطة التفاعلية");
    const focus = make("button", "Show loaded locations on map", "إظهار المواقع المحملة على الخريطة");
    const searchPartners = make("button", "Find partners near selected point", "البحث عن شركاء قرب النقطة المحددة");
    searchPartners.type = "button";
    searchPartners.hidden = true;
    const searchViewport = make(
        "button",
        "Search partner reports around map centre",
        "البحث في تقارير الشركاء حول مركز الخريطة",
    );
    searchViewport.type = "button";
    searchViewport.hidden = true;
    const platformLayerLabel = document.createElement("label");
    const showPlatformLayer = document.createElement("input");
    showPlatformLayer.id = "show-platform-map-pins";
    showPlatformLayer.type = "checkbox";
    showPlatformLayer.checked = true;
    platformLayerLabel.append(
        showPlatformLayer,
        make("span", "Show platform station pins", "عرض محطات المنصة على الخريطة"),
    );
    platformLayerLabel.hidden = true;
    const partnerLayerLabel = document.createElement("label");
    const showPartnerLayer = document.createElement("input");
    showPartnerLayer.id = "show-partner-map-pins";
    showPartnerLayer.type = "checkbox";
    showPartnerLayer.checked = true;
    partnerLayerLabel.append(
        showPartnerLayer,
        make("span", "Show unverified partner pins", "عرض مواقع الشركاء غير المؤكدة"),
    );
    partnerLayerLabel.hidden = true;
    const ocmLayerLabel = document.createElement("label");
    const showOcmLayer = document.createElement("input");
    showOcmLayer.id = "show-ocm-map-pins";
    showOcmLayer.type = "checkbox";
    showOcmLayer.checked = true;
    ocmLayerLabel.append(
        showOcmLayer,
        make("span", "Show open-licensed discovery pins", "عرض مواقع الاكتشاف ذات البيانات المفتوحة"),
    );
    ocmLayerLabel.hidden = true;
    enable.type = locate.type = disable.type = focus.type = "button";
    disable.hidden = focus.hidden = true;
    const consent = document.createElement("label");
    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.id = "location-consent";
    consent.append(
        checkbox,
        make(
            "span",
            "Allow a one-time location lookup and send coordinates to search. Coordinates are not saved as a preference.",
            "السماح بتحديد الموقع مرة واحدة وإرسال الإحداثيات للبحث. لا تُحفظ الإحداثيات كتفضيل.",
        ),
    );
    const status = document.createElement("p");
    status.setAttribute("role", "status");
    const setStatus = (en, ar) => {
        status.dataset.en = en;
        status.dataset.ar = ar;
        status.textContent = text(en, ar);
    };
    const canvas = document.createElement("div");
    canvas.id = "station-map";
    canvas.hidden = true;
    canvas.setAttribute("aria-label", "Station map / خريطة المحطات");
    root.append(intro, enable, disable, focus, searchPartners, searchViewport, platformLayerLabel, partnerLayerLabel, ocmLayerLabel, consent, locate, status, canvas);
    let locationGeneration = 0;
    let planner,
        selectedCentre = null,
        routeSegments = [],
        routeLines;
    let popupOpen = false;
    let records = [],
        partnerRows = [],
        ocmRows = [],
        map,
        markers,
        partnerMarkers,
        ocmMarkers,
        picked;
    const valid = (s) =>
        s.latitude !== null &&
        s.longitude !== null &&
        s.latitude !== undefined &&
        s.longitude !== undefined &&
        String(s.latitude).trim() !== "" &&
        String(s.longitude).trim() !== "" &&
        Number.isFinite(+s.latitude) &&
        Number.isFinite(+s.longitude) &&
        Math.abs(+s.latitude) <= 85.05112878 &&
        Math.abs(+s.longitude) <= 180;
    function select(lat, lng) {
        if (!Number.isFinite(lat) || !Number.isFinite(lng) ||
            Math.abs(lat) > 90 || Math.abs(lng) > 180) {
            setStatus(
                "Coordinates are invalid. Enter a bounded location manually.",
                "الإحداثيات غير صالحة. أدخل موقعاً صحيحاً يدوياً.",
            );
            return;
        }
        selectedCentre = { latitude: lat, longitude: lng };
        searchPartners.hidden = false;
        const form = document.getElementById("filters");
        form.elements.latitude.value = lat.toFixed(6);
        form.elements.longitude.value = lng.toFixed(6);
        if (!form.elements.radius_km.value)
            form.elements.radius_km.value = "25";
        form.querySelector("details").open = true;
        setStatus(
            "Search centre selected. Press Search to load nearby results. Distance is straight-line.",
            "تم تحديد مركز البحث. اضغط بحث لتحميل النتائج القريبة. المسافة بخط مستقيم.",
        );
        if (map) {
            if (picked) picked.remove();
            picked = null;
            if (Math.abs(lat) <= 85.05112878) {
                picked = L.circleMarker([lat, lng], { radius: 9 }).addTo(map);
            } else {
                // Leaflet Web Mercator cannot render polar coordinates. Keep
                // the valid polar search centre in the form and list API,
                // but never project it as a misleading map marker at zero.
                setStatus(
                    "Selected latitude is outside this map's display range. Nearby list search remains available.",
                    "خط العرض المحدد خارج نطاق عرض الخريطة. يبقى البحث القريب في القائمة متاحاً.",
                );
            }
        }
    }

    // One selected point explicitly updates partner discovery; map/GPS never
    // silently sends coordinates to another catalog without this click.
    searchPartners.onclick = () => {
        if (!selectedCentre) return;
        const { latitude, longitude } = selectedCentre;
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude) ||
            Math.abs(latitude) > 90 || Math.abs(longitude) > 180) return;
        const field = document.getElementById("filters").elements.radius_km;
        const value = Number(field.value);
        const radius = field.value && Number.isFinite(value) && value >= 1 && value <= 200
            ? value : 25;
        window.dispatchEvent(new CustomEvent("partner-nearby-search", {
            detail: {
                latitude: latitude.toFixed(6),
                longitude: longitude.toFixed(6),
                radius_km: String(radius),
            },
        }));
        setStatus(
            "Searching partner-reported locations around selected coordinates. Availability is not live.",
            "يجري البحث في مواقع الشركاء المبلغ عنها قرب الإحداثيات المحددة. التوفر ليس مباشراً.",
        );
    };

    // The map is a visual selection surface, not a background GPS search.
    // Panning/zooming alone never submits coordinates or refetches partners.
    searchViewport.onclick = () => {
        if (!map) return;
        const centre = map.getCenter().wrap();
        const bounds = map.getBounds();
        const corners = [bounds.getNorthEast(), bounds.getSouthEast(), bounds.getNorthWest(), bounds.getSouthWest()];
        if (!Number.isFinite(centre.lat) || !Number.isFinite(centre.lng) ||
            Math.abs(centre.lat) > 90 || Math.abs(centre.lng) > 180) return;
        // The public endpoint has an intentionally bounded radius (<=200km).
        // A wider visible viewport is not claimed to have been searched fully.
        const visibleRadiusKm = Math.ceil(Math.max(
            1, ...corners.map(point => map.distance(centre, point) / 1000),
        ));
        const radius = Math.max(1, Math.min(200, visibleRadiusKm));
        window.dispatchEvent(new CustomEvent("partner-nearby-search", {
            detail: {
                latitude: centre.lat.toFixed(6),
                longitude: centre.lng.toFixed(6),
                radius_km: String(radius),
            },
        }));
        setStatus(
            visibleRadiusKm > 200
                ? "Map view exceeds 200 km; searching partner reports within 200 km of map centre only. Not live availability."
                : "Searching partner-reported locations around this map centre. Not live availability.",
            visibleRadiusKm > 200
                ? "مساحة الخريطة أوسع من 200 كم؛ البحث في تقارير الشركاء ضمن 200 كم فقط من المركز. ليس توافراً مباشراً."
                : "يجري البحث في مواقع الشركاء المبلغ عنها حول مركز الخريطة. ليس توافراً مباشراً.",
        );
    };

    // Partner markers reuse this exact map and its explicit tile-consent gate.
    // Never infer booking, charging, live availability or route authority from OCPI.
    const partnerSafe = (row) =>
        row && Number.isSafeInteger(row.id) && row.id > 0 &&
        row.evidence === "PARTNER_REPORTED_UNVERIFIED" &&
        row.availability === "unknown" &&
        row.remote_control === false &&
        row.reservable === false &&
        row.location_available !== false &&
        row.location &&
        row.location.availability === "unknown" &&
        row.location.remote_control === false &&
        row.location.reservable === false &&
        valid(row.location);
    function renderPartners() {
        if (!map || !partnerMarkers) return;
        partnerMarkers.clearLayers();
        if (!showPartnerLayer.checked) return;
        const buckets = new Map();
        for (const row of partnerRows.filter(partnerSafe)) {
            const place = row.location;
            const point = map.latLngToContainerPoint([+place.latitude, +place.longitude]);
            const key = `${Math.floor(point.x / 48)}:${Math.floor(point.y / 48)}`;
            if (!buckets.has(key)) buckets.set(key, []);
            buckets.get(key).push(row);
        }
        for (const group of buckets.values()) {
            const first = group[0].location;
            // This class describes source evidence, never actual occupancy.
            // Mixed clusters are marked uncertain whenever a source clock is
            // missing or ahead, and stale whenever any report exceeds 24h.
            const freshnessClass = group.some(row =>
                row.source_clock_evidence === "PARTNER_CLOCK_AHEAD" ||
                !Number.isSafeInteger(row.reported_source_age_minutes))
                ? "partner-pin-uncertain"
                : group.some(row => row.reported_source_age_minutes >= 1440)
                    ? "partner-pin-stale" : "partner-pin-reported";
            const popup = document.createElement("div");
            for (const row of group) {
                const place = row.location;
                const name = document.createElement("strong");
                name.textContent = String(place.name || place.id || "Partner location").slice(0, 255);
                popup.append(name);
                popup.append(make(
                    "p",
                    "Partner reported (unverified). Live availability unknown. No booking or remote control.",
                    "بيانات الشريك غير متحقق منها. التوفر المباشر غير معروف. لا حجز أو تحكم عن بُعد.",
                ));
                if (row.provider_label_evidence === "PROVIDER_CONFIGURED_UNVERIFIED" &&
                    typeof row.provider_label === "string" && row.provider_label.trim()) {
                    const label = row.provider_label.trim().slice(0, 120);
                    popup.append(make(
                        "p",
                        `Configured provider (unverified): ${label}`,
                        `اسم مقدم الخدمة المُسجل (غير مؤكد): ${label}`,
                    ));
                }
                if (row.source_clock_evidence === "PARTNER_CLOCK_AHEAD") {
                    popup.append(make(
                        "p",
                        "Partner's reported timestamp is in the future; freshness unknown.",
                        "طابع وقت الشريك في المستقبل؛ حداثة البيانات غير معروفة.",
                    ));
                }
                const age = Number.isSafeInteger(row.reported_source_age_minutes) &&
                    row.reported_source_age_minutes >= 0
                    ? row.reported_source_age_minutes : null;
                const stale = age !== null && age >= 1440;
                popup.append(make(
                    "p",
                    age === null
                        ? "Partner source age unknown; live status unverified."
                        : `Partner source age: ${age} min${stale ? " (older than 24 hours)" : ""}; live status unverified.`,
                    age === null
                        ? "عمر سجل الشريك غير معروف؛ الحالة المباشرة غير مؤكدة."
                        : `عمر سجل الشريك: ${age} دقيقة${stale ? " (أكثر من 24 ساعة)" : ""}؛ الحالة المباشرة غير مؤكدة.`,
                ));
                const jump = make("button", "View partner location in list", "عرض موقع الشريك في القائمة");
                jump.type = "button";
                jump.onclick = () => {
                    const card = document.querySelector(
                        `[data-partner-location-id="${row.id}"]`,
                    );
                    if (card) {
                        card.tabIndex = -1;
                        card.focus();
                        card.scrollIntoView({ block: "center" });
                    }
                };
                const navigation = make(
                    "a",
                    "Open partner directions in Google Maps",
                    "فتح اتجاهات موقع الشريك في خرائط Google",
                );
                navigation.href = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(`${+place.latitude},${+place.longitude}`)}`;
                navigation.target = "_blank";
                navigation.rel = "noopener noreferrer";
                navigation.onclick = (event) => {
                    if (!window.confirm(text(
                        "Send the partner destination to Google Maps? Its privacy policy applies; no route or travel time is verified.",
                        "إرسال موقع الشريك إلى خرائط Google؟ تطبق سياسة الخصوصية للمزود؛ لا توجد مسافة قيادة أو مدة طريق متحقق منها.",
                    ))) event.preventDefault();
                };
                popup.append(jump, navigation);
            }
            L.marker([+first.latitude, +first.longitude], {
                title: freshnessClass === "partner-pin-uncertain"
                    ? text("Unverified partner — source time uncertain", "الشريك غير مؤكد — وقت المصدر غير معروف")
                    : freshnessClass === "partner-pin-stale"
                        ? text("Unverified partner — old reported data", "الشريك غير مؤكد — بيانات قديمة")
                        : text("Unverified partner locations", "مواقع الشركاء غير المؤكدة"),
                // Canonical station pins must remain reachable at shared coordinates.
                zIndexOffset: -500,
                icon: L.divIcon({
                    className: "station-map-pin partner-map-pin " + freshnessClass,
                    html: `P${group.length}`,
                    iconSize: [36, 36],
                }),
            }).bindPopup(popup).addTo(partnerMarkers);
        }
    }
    // Open-licensed crowd data is a third read-only source, never a canonical
    // charging station or an authorized OCPI provider/EVSE ledger.
    const ocmSafe = (r) =>
        r && typeof r.id === "string" && /^ocm:[1-9]\d*$/.test(r.id) &&
        r.source_status_evidence === "OPEN_CHARGE_MAP_DISCOVERY_ONLY" &&
        r.reported_operational_evidence === "OPEN_CHARGE_MAP_CROWD_STATUS_UNVERIFIED" &&
        (r.reported_operational === null || typeof r.reported_operational === "boolean") &&
        r.reported_access_evidence === "OPEN_CHARGE_MAP_CROWD_ACCESS_UNVERIFIED" &&
        r.reported_cost_evidence === "OPEN_CHARGE_MAP_CROWD_PRICE_UNVERIFIED" &&
        typeof r.reported_cost_note === "string" && Array.from(r.reported_cost_note).length <= 240 &&
        typeof r.reported_access === "string" &&
        r.availability === "unknown" && r.remote_control === false &&
        r.reservable === false && r.billing_eligible === false &&
        typeof r.data_license === "string" && r.data_license.trim() &&
        typeof r.attribution === "string" && r.attribution.trim() &&
        r.source_url === "https://openchargemap.org/site/poi/details/" + r.id.slice(4) &&
        valid(r);
    // Mercator cannot project polar coordinates accurately; preserve such
    // records in the list without inventing a mapped location.
    const ocmPlottable = r => ocmSafe(r) && Math.abs(+r.latitude) <= 85.05112878;
    const ocmFreshness = group => group.some(row =>
        row.source_clock_evidence !== "CROWD_REPORTED_TIME" ||
        !Number.isSafeInteger(row.source_age_minutes) || row.source_age_minutes < 0)
        ? "ocm-pin-uncertain"
        : group.some(row => row.source_age_minutes >= 1440)
            ? "ocm-pin-stale" : "ocm-pin-reported";
    function renderOcm() {
        if (!map || !ocmMarkers) return;
        ocmMarkers.clearLayers();
        ocmLayerLabel.hidden = ocmRows.length === 0;
        if (!showOcmLayer.checked) return;
        const buckets = new Map();
        for (const row of ocmRows.filter(ocmPlottable)) {
            const p = map.latLngToContainerPoint([+row.latitude, +row.longitude]);
            const key = `${Math.floor(p.x / 48)}:${Math.floor(p.y / 48)}`;
            if (!buckets.has(key)) buckets.set(key, []);
            buckets.get(key).push(row);
        }
        for (const group of buckets.values()) {
            const first = group[0];
            const freshness = ocmFreshness(group);
            const popup = document.createElement("div");
            for (const row of group) {
                const name = document.createElement("strong");
                name.textContent = String(row.name || row.id).slice(0, 180);
                const evidence = make(
                    "p",
                    "Open Charge Map crowd report (licensed source). Live availability unverified; no booking, charging or payment.",
                    "تقرير مجتمعي من خريطة الشحن المفتوحة (مصدر مرخص). التوفر المباشر غير مؤكد؛ لا حجز أو شحن أو دفع.",
                );
                const sourceAge = make("p",
                    row.source_clock_evidence === "SOURCE_CLOCK_AHEAD"
                        ? "Crowd source clock is in the future; freshness unknown."
                        : Number.isSafeInteger(row.source_age_minutes) && row.source_age_minutes >= 0
                            ? "Crowd source age: " + row.source_age_minutes + " min; not live."
                            : "Crowd source update time unknown; not live.",
                    row.source_clock_evidence === "SOURCE_CLOCK_AHEAD"
                        ? "طابع وقت المصدر المجتمعي في المستقبل؛ حداثة البيانات غير معروفة."
                        : Number.isSafeInteger(row.source_age_minutes) && row.source_age_minutes >= 0
                            ? "عمر التقرير المجتمعي: " + row.source_age_minutes + " دقيقة؛ ليست مباشرة."
                            : "وقت تحديث المصدر المجتمعي غير معروف؛ ليست حالة مباشرة.",
                );
                const reported = make(
                    "p",
                    row.reported_operational === true
                        ? "Crowd report: this location was operational at last update; not live."
                        : row.reported_operational === false
                            ? "Crowd report: this location had an operational issue; not live."
                            : "Crowd operational report unknown; not live.",
                    row.reported_operational === true
                        ? "التقرير المجتمعي: كان الموقع يعمل عند آخر تحديث؛ ليست حالة مباشرة."
                        : row.reported_operational === false
                            ? "التقرير المجتمعي: أبلغ عن مشكلة تشغيل؛ ليست حالة مباشرة."
                            : "الحالة التشغيلية المجتمعية غير معروفة؛ ليست مباشرة.",
                );
                const operatorInfo = make(
                    "p",
                    "Crowd-reported operator (unverified): " + (row.operator_label || "unknown"),
                    "المشغل حسب التقرير (غير مؤكد): " + (row.operator_label || "غير معروف"),
                );
                const access = make(
                    "p",
                    "Crowd-reported access (unverified): " + (row.reported_access || "unknown") + ". Check with operator.",
                    "الوصول حسب تقارير المجتمع (غير مؤكد): " + (row.reported_access || "غير معروف") + ". تحقق مع المشغل.",
                );
                const membership = make(
                    "p",
                    row.reported_membership_required === true
                        ? "Crowd report: membership required; unverified."
                        : row.reported_membership_required === false
                            ? "Crowd report: membership not required; unverified."
                            : "Crowd membership requirement unknown.",
                    row.reported_membership_required === true
                        ? "العضوية مطلوبة حسب تقرير المجتمع؛ غير مؤكدة."
                        : row.reported_membership_required === false
                            ? "العضوية غير مطلوبة حسب تقرير المجتمع؛ غير مؤكدة."
                            : "اشتراط العضوية في التقرير غير معروف.",
                );
                const cost = make(
                    "p",
                    "Crowd-reported cost note (unverified, not a quote): " + (row.reported_cost_note || "unavailable") + ". Check current operator tariff.",
                    "ملاحظة التكلفة حسب تقرير المجتمع (غير مؤكدة وليست عرض سعر): " + (row.reported_cost_note || "غير متاحة") + ". تحقق من الأسعار الحالية مع المشغل.",
                );
                const attribution = document.createElement("p");
                attribution.textContent = row.attribution + " · " + row.data_license;
                const jump = make(
                    "button",
                    "View open-licensed location in list",
                    "عرض الموقع ذي البيانات المفتوحة في القائمة",
                );
                jump.type = "button";
                jump.onclick = () => {
                    const card = document.querySelector(
                        `[data-ocm-location-id="${row.id}"]`,
                    );
                    if (card) {
                        card.tabIndex = -1;
                        card.focus();
                        card.scrollIntoView({ block: "center" });
                    }
                };
                const source = make("a", "View Open Charge Map source", "عرض المصدر في خريطة الشحن المفتوحة");
                source.href = row.source_url;
                source.target = "_blank";
                source.rel = "noopener noreferrer";
                const directions = make(
                    "a",
                    "Open crowd location in Google Maps",
                    "فتح موقع المصدر المجتمعي في خرائط Google",
                );
                directions.href = "https://www.google.com/maps/dir/?api=1&destination=" +
                    encodeURIComponent(String(Number(row.latitude)) + "," + String(Number(row.longitude)));
                directions.target = "_blank";
                directions.rel = "noopener noreferrer";
                directions.onclick = event => {
                    if (!window.confirm(text(
                        "Send this crowd-reported location to Google Maps? Its privacy policy applies. No route, ETA or live charger status verified.",
                        "إرسال موقع المصدر المجتمعي إلى خرائط Google؟ تطبق سياسة خصوصية المزود، ولم يتم التحقق من المسار أو الوقت أو توفر الشاحن.",
                    ))) event.preventDefault();
                };
                popup.append(name, evidence, sourceAge, reported, operatorInfo, access, membership, cost, attribution, jump, source, directions);
            }
            L.marker([+first.latitude, +first.longitude], {
                title: freshness === "ocm-pin-uncertain"
                    ? text("Open Charge Map — source time unknown", "خريطة الشحن المفتوحة — وقت المصدر غير معروف")
                    : freshness === "ocm-pin-stale"
                        ? text("Open Charge Map — old crowd report", "خريطة الشحن المفتوحة — تقرير مجتمعي قديم")
                        : text("Open Charge Map — unverified discovery", "خريطة الشحن المفتوحة — اكتشاف غير مؤكد"),
                zIndexOffset: -900,
                icon: L.divIcon({
                    className: "station-map-pin ocm-map-pin " + freshness,
                    html: `O${group.length}`,
                    iconSize: [36, 36],
                }),
            }).bindPopup(popup).addTo(ocmMarkers);
        }
    }

    function render() {
        if (!map) return;
        markers.clearLayers();
        if (!showPlatformLayer.checked) {
            renderPartners();
            renderOcm();
            return;
        }
        // Screen-space groups keep dense pages usable; clusters never imply shared chargers.
        const buckets = new Map();
        for (const station of records.filter(valid)) {
            const point = map.latLngToContainerPoint([
                +station.latitude,
                +station.longitude,
            ]);
            const key = `${Math.floor(point.x / 48)}:${Math.floor(point.y / 48)}`;
            if (!buckets.has(key)) buckets.set(key, []);
            buckets.get(key).push(station);
        }
        for (const group of buckets.values()) {
            const station = group[0],
                popup = document.createElement("div");
            for (const item of group) {
                const title = document.createElement("strong");
                title.textContent = item.name;
                const evidence = document.createElement("p");
                evidence.textContent = `${item.evidence} · ${text("Availability unverified", "التوفر غير مؤكد")}`;
                const jump = make(
                    "button",
                    "View station in list",
                    "عرض المحطة في القائمة",
                );
                jump.type = "button";
                jump.onclick = () => {
                    const card = document.querySelector(
                        `[data-station-id="${Number(item.id)}"]`,
                    );
                    if (card) {
                        card.tabIndex = -1;
                        card.focus();
                        card.scrollIntoView({ block: "center" });
                    }
                };
                const navigation = make(
                    "a",
                    "Open directions in Google Maps",
                    "فتح الاتجاهات في خرائط Google",
                );
                navigation.href = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(`${+item.latitude},${+item.longitude}`)}`;
                navigation.target = "_blank";
                navigation.rel = "noopener noreferrer";
                navigation.onclick = (event) => {
                    if (
                        !window.confirm(
                            text(
                                "Send the destination to Google Maps? Its privacy policy applies. No route or travel time has been verified.",
                                "إرسال الوجهة إلى خرائط Google؟ تطبق سياسة خصوصية المزود. لم يتم التحقق من المسار أو زمن الرحلة.",
                            ),
                        )
                    )
                        event.preventDefault();
                };
                const stop = make(
                    "button",
                    "Add charging stop",
                    "إضافة توقف شحن",
                );
                stop.type = "button";
                stop.onclick = () => planner?.addStop(item);
                popup.append(title, evidence, jump, navigation, stop);
            }
            L.marker([+station.latitude, +station.longitude], {
                icon: L.divIcon({
                    className: "station-map-pin",
                    html: String(group.length),
                    iconSize: [36, 36],
                }),
            })
                .bindPopup(popup)
                .addTo(markers);
        }
        renderPartners();
        renderOcm();
    }
    const loadedPoints = () => [
        ...(showPlatformLayer.checked
            ? records.filter(valid).map((s) => [+s.latitude, +s.longitude])
            : []),
        ...(showPartnerLayer.checked
            ? partnerRows.filter(partnerSafe).map((r) => [+r.location.latitude, +r.location.longitude])
            : []),
        ...(showOcmLayer.checked
            ? ocmRows.filter(ocmPlottable).map((r) => [+r.latitude, +r.longitude])
            : []),
    ];
    showPartnerLayer.onchange = () => {
        // Purely local presentation toggle: no API, location or tile request.
        renderPartners();
    };
    showPlatformLayer.onchange = () => render();
    showOcmLayer.onchange = () => renderOcm();
    function fitLoaded(announce = false) {
        if (!map) return;
        const points = loadedPoints();
        if (points.length) {
            map.fitBounds(points, { maxZoom: 13, padding: [24, 24], animate: false });
            if (announce) setStatus(
                "Map fitted to loaded results only. Other nearby sites may not be listed.",
                "تم ضبط الخريطة على النتائج المحملة فقط. قد توجد مواقع أخرى قريبة غير معروضة.",
            );
        } else if (announce) {
            setStatus(
                "No loaded locations have usable map coordinates.",
                "لا توجد إحداثيات صالحة للمواقع المحملة على الخريطة.",
            );
        }
    }
    focus.onclick = () => fitLoaded(true);
    disable.onclick = () => {
        // Removing Leaflet releases its event listeners and stops subsequent tile
        // activity until a fresh explicit Enable click; already requested tiles
        // may complete at the network layer.
        locationGeneration++;
        locate.disabled = false;
        checkbox.checked = false;
        selectedCentre = null;
        searchPartners.hidden = true;
        if (map) map.remove();
        popupOpen = false;
        map = markers = partnerMarkers = ocmMarkers = routeLines = picked = null;
        canvas.hidden = true;
        enable.hidden = false;
        disable.hidden = focus.hidden = searchViewport.hidden = partnerLayerLabel.hidden = platformLayerLabel.hidden = ocmLayerLabel.hidden = true;
        setStatus(
            "Interactive map disabled. Results remain available in the lists.",
            "تم إيقاف الخريطة التفاعلية. لا تزال النتائج متاحة في القوائم.",
        );
    };
    enable.onclick = () => {
        if (map) return;
        canvas.hidden = false;
        enable.hidden = true;
        disable.hidden = focus.hidden = searchViewport.hidden = partnerLayerLabel.hidden = platformLayerLabel.hidden = false;
        map = L.map(canvas, { worldCopyJump: true }).setView([24.45, 54.38], 6);
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution:
                '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>',
        })
            .addTo(map)
            .on("tileerror", () => {
                // Tile failures can arrive after a polar GPS selection.
                // Do not replace its more useful projection warning with a
                // generic background-tile error: list search still works.
                if (selectedCentre && Math.abs(selectedCentre.latitude) > 85.05112878) return;
                setStatus(
                    "Map tiles unavailable. The station list and manual search remain available.",
                    "خلفية الخريطة غير متاحة. تبقى قائمة المحطات والبحث اليدوي متاحين.",
                );
            });
        markers = L.layerGroup().addTo(map);
        partnerMarkers = L.layerGroup().addTo(map);
        ocmMarkers = L.layerGroup().addTo(map);
        routeLines = L.layerGroup().addTo(map);
        renderRoute();
        fitLoaded();
        map.on("popupopen", () => { popupOpen = true; });
        map.on("popupclose", () => { popupOpen = false; });
        // Screen-space bucketing may be recalculated on viewport changes, but
        // never destroy an open popup while its controls are being used.
        map.on("moveend zoomend", () => {
            if (!popupOpen) render();
        });
        map.on("click", (event) =>
            select(event.latlng.lat, event.latlng.wrap().lng),
        );
        render();
    };
    locate.onclick = () => {
        if (!checkbox.checked) {
            setStatus(
                "First allow the one-time location lookup, or enter coordinates manually.",
                "اسمح أولاً بتحديد الموقع مرة واحدة أو أدخل الإحداثيات يدوياً.",
            );
            return;
        }
        if (!navigator.geolocation) {
            setStatus(
                "Location is unavailable. Enter coordinates manually.",
                "الموقع غير متاح. أدخل الإحداثيات يدوياً.",
            );
            return;
        }
        locate.disabled = true;
        const lookupGeneration = ++locationGeneration;
        navigator.geolocation.getCurrentPosition(
            (position) => {
                if (lookupGeneration !== locationGeneration) return;
                locate.disabled = false;
                select(position.coords.latitude, position.coords.longitude);
                if (map && Math.abs(position.coords.latitude) <= 85.05112878)
                    map.setView(
                        [position.coords.latitude, position.coords.longitude],
                        13,
                    );
            },
            () => {
                if (lookupGeneration !== locationGeneration) return;
                locate.disabled = false;
                setStatus(
                    "Location could not be obtained. Enter coordinates manually.",
                    "تعذر تحديد الموقع. أدخل الإحداثيات يدوياً.",
                );
            },
            { enableHighAccuracy: false, timeout: 10000, maximumAge: 0 },
        );
    };
    function renderRoute() {
        if (!routeLines) return;
        routeLines.clearLayers();
        for (const line of routeSegments) {
            if (line.every((p) => Math.abs(p.latitude) <= 85.05112878))
                L.polyline(
                    line.map((p) => [p.latitude, p.longitude]),
                    { color: "#087f75", weight: 4 },
                ).addTo(routeLines);
        }
    }
    window.addEventListener("discovery-api-ready", (event) => {
        if (!window.EvGeoTools) return;
        planner = EvGeoTools.install({
            root,
            request: event.detail,
            onPoint: (p) => select(p.latitude, p.longitude),
            getCentre: () => selectedCentre,
            onRoute: (segments) => {
                routeSegments = segments;
                renderRoute();
            },
        });
        planner.update(records);
    });
    window.addEventListener("ev:locale", render);
    window.addEventListener("roaming-map-data", (event) => {
        partnerRows = Array.isArray(event.detail) && event.detail.length <= 25
            ? event.detail.filter(partnerSafe)
            : [];
        render();
    });
    window.addEventListener("open-charge-map-data", (event) => {
        ocmRows = Array.isArray(event.detail) && event.detail.length <= 25
            ? event.detail.filter(ocmSafe)
            : [];
        render();
    });
    window.addEventListener("catalog-map-data", (event) => {
        records = event.detail;
        planner?.update(records);
        render();
    });
    window.addEventListener("catalog-map-clear", () => {
        records = [];
        partnerRows = [];
        ocmRows = [];
        ocmLayerLabel.hidden = true;
        if (partnerMarkers) partnerMarkers.clearLayers();
        if (ocmMarkers) ocmMarkers.clearLayers();
        locationGeneration++;
        locate.disabled = false;
        checkbox.checked = false;
        for (const name of ["latitude", "longitude", "radius_km"])
            document.getElementById("filters").elements[name].value = "";
        status.replaceChildren();
        delete status.dataset.en;
        delete status.dataset.ar;
        selectedCentre = null;
        searchPartners.hidden = true;
        routeSegments = [];
        planner?.clear();
        if (picked) {
            picked.remove();
            picked = null;
        }
        renderRoute();
        if (markers) markers.clearLayers();
    });
})();
