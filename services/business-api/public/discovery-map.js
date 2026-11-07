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
        "Map tiles are provided by OpenStreetMap. Enabling the map sends your IP and viewed area to its tile service. Only this page of results is shown; availability is unverified.",
        "توفر OpenStreetMap خرائط الخلفية. عند تفعيل الخريطة يُرسل عنوان الإنترنت والمنطقة المعروضة إلى مزود الخرائط. تظهر نتائج هذه الصفحة فقط؛ التوفر غير مؤكد.",
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
    enable.type = locate.type = "button";
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
    root.append(intro, enable, consent, locate, status, canvas);
    let locationGeneration = 0;
    let planner,
        selectedCentre = null,
        routeSegments = [],
        routeLines;
    let records = [],
        partnerRows = [],
        map,
        markers,
        partnerMarkers,
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
        selectedCentre = { latitude: lat, longitude: lng };
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
            picked = L.circleMarker([lat, lng], { radius: 9 }).addTo(map);
        }
    }

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
                icon: L.divIcon({
                    className: "station-map-pin partner-map-pin",
                    html: `P${group.length}`,
                    iconSize: [36, 36],
                }),
            }).bindPopup(popup).addTo(partnerMarkers);
        }
    }
    function render() {
        if (!map) return;
        markers.clearLayers();
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
    }
    enable.onclick = () => {
        canvas.hidden = false;
        enable.hidden = true;
        map = L.map(canvas, { worldCopyJump: true }).setView([24.45, 54.38], 6);
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution:
                '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>',
        })
            .addTo(map)
            .on("tileerror", () => {
                setStatus(
                    "Map tiles unavailable. The station list and manual search remain available.",
                    "خلفية الخريطة غير متاحة. تبقى قائمة المحطات والبحث اليدوي متاحين.",
                );
            });
        markers = L.layerGroup().addTo(map);
        partnerMarkers = L.layerGroup().addTo(map);
        routeLines = L.layerGroup().addTo(map);
        renderRoute();
        const points = [
            ...records.filter(valid).map((s) => [+s.latitude, +s.longitude]),
            ...partnerRows.filter(partnerSafe).map((r) => [+r.location.latitude, +r.location.longitude]),
        ];
        if (points.length)
            map.fitBounds(points, { maxZoom: 13, padding: [24, 24] });
        map.on("moveend zoomend", render);
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
    window.addEventListener("catalog-map-data", (event) => {
        records = event.detail;
        planner?.update(records);
        render();
    });
    window.addEventListener("catalog-map-clear", () => {
        records = [];
        locationGeneration++;
        locate.disabled = false;
        checkbox.checked = false;
        for (const name of ["latitude", "longitude", "radius_km"])
            document.getElementById("filters").elements[name].value = "";
        status.replaceChildren();
        delete status.dataset.en;
        delete status.dataset.ar;
        selectedCentre = null;
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
