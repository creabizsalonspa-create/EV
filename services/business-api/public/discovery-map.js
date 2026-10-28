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
    let records = [],
        map,
        markers,
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
                popup.append(title, evidence, jump, navigation);
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
        const points = records
            .filter(valid)
            .map((s) => [+s.latitude, +s.longitude]);
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
        navigator.geolocation.getCurrentPosition(
            (position) => {
                locate.disabled = false;
                select(position.coords.latitude, position.coords.longitude);
                if (map && Math.abs(position.coords.latitude) <= 85.05112878)
                    map.setView(
                        [position.coords.latitude, position.coords.longitude],
                        13,
                    );
            },
            () => {
                locate.disabled = false;
                setStatus(
                    "Location could not be obtained. Enter coordinates manually.",
                    "تعذر تحديد الموقع. أدخل الإحداثيات يدوياً.",
                );
            },
            { enableHighAccuracy: false, timeout: 10000, maximumAge: 0 },
        );
    };
    window.addEventListener("ev:locale", render);
    window.addEventListener("catalog-map-data", (event) => {
        records = event.detail;
        render();
    });
    window.addEventListener("catalog-map-clear", () => {
        records = [];
        if (markers) markers.clearLayers();
    });
})();
