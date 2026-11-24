/* Read-only provider lookups and in-memory charging itineraries; no finance logic. */
(() => {
    const arabic = () => document.documentElement.lang === "ar";
    const label = (tag, en, ar) => {
        const n = document.createElement(tag);
        n.dataset.en = en;
        n.dataset.ar = ar;
        n.textContent = arabic() ? ar : en;
        return n;
    };
    const button = (en, ar, click) => {
        const n = label("button", en, ar);
        n.type = "button";
        n.onclick = click;
        return n;
    };
    const digits = (value) =>
        value
            .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 1632))
            .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 1776))
            .replaceAll("٫", ".")
            .replaceAll("−", "-");
    const point = (lat, lng) => {
        if (String(lat).trim() === "" || String(lng).trim() === "") return null;
        const latitude = Number(digits(String(lat).trim())),
            longitude = Number(digits(String(lng).trim()));
        return Number.isFinite(latitude) &&
            Number.isFinite(longitude) &&
            Math.abs(latitude) <= 90 &&
            Math.abs(longitude) <= 180
            ? { latitude, longitude }
            : null;
    };
    const coordinate = (p) =>
        `${p.latitude.toFixed(6)},${p.longitude.toFixed(6)}`;
    // All energy values are explicit driver assumptions, never vehicle telemetry.
    function energyInputs(raw) {
        const limits = {capacity:[1,250], soc:[0,100], reserve:[0,100], consumption:[1,100]};
        const values = {};
        for (const [key,[min,max]] of Object.entries(limits)) {
            if (typeof raw?.[key] !== "string") return null;
            const text = digits(raw[key].trim());
            if (!/^[0-9]{1,3}(?:\.[0-9]{1,2})?$/.test(text)) return null;
            const n = Number(text);
            if (n < min || n > max) return null;
            values[key] = n;
        }
        if (values.reserve >= values.soc) return null;
        return values;
    }
    function straightKm(a,b) {
        if (!a || !b) return null;
        const rad = Math.PI / 180, dlat = (b.latitude-a.latitude)*rad,
            dlng = (b.longitude-a.longitude)*rad;
        const h = Math.sin(dlat/2)**2 + Math.cos(a.latitude*rad)*Math.cos(b.latitude*rad)*Math.sin(dlng/2)**2;
        return 6371*2*Math.asin(Math.sqrt(Math.min(1,Math.max(0,h))));
    }
    // A shortlist of current-page published locations, not a road itinerary.
    // Nearest straight-line distance first. Never infer available charging.
    function energyCandidates(raw, origin, stations, connector = "") {
        const v = energyReview(raw, null, null, null);
        if (!v || !origin || !Array.isArray(stations) || stations.length > 25 ||
            !["","CCS2","Type2","CHAdeMO"].includes(connector)) return [];
        const start = point(origin.latitude,origin.longitude);
        if (!start) return [];
        const counts = new Map();
        for(const row of stations) if(Number.isSafeInteger(row?.id)) counts.set(row.id,(counts.get(row.id)||0)+1);
        const rows = [];
        for (const s of stations) {
            if (!s || !Number.isSafeInteger(s.id) || s.id < 1 || counts.get(s.id)!==1 ||
                typeof s.name !== "string" || !s.name.trim() ||
                !["SIMULATED","DISCOVERY_ONLY"].includes(s.evidence) ||
                !["CCS2","Type2","CHAdeMO"].includes(s.connector_type) ||
                (connector && s.connector_type!==connector) ||
                !Number.isInteger(s.power_kw) || s.power_kw < 1 || s.power_kw > 1000 ||
                s.latitude == null || s.longitude == null ||
                !["string","number"].includes(typeof s.latitude) ||
                !["string","number"].includes(typeof s.longitude)) continue;
            const p = point(s.latitude,s.longitude);
            if(!p) continue;
            const km = straightKm(start,p);
            if(km > v.km+1e-9) continue;
            rows.push({id:s.id,name:s.name,connector:s.connector_type,evidence:s.evidence,power:s.power_kw,km});
        }
        return rows.sort((a,b)=>a.km-b.km || a.id-b.id).slice(0,5);
    }
    function energyReview(raw, routeMeters, origin, firstStop) {
        const v = energyInputs(raw);
        if (!v) return null;
        const budget = v.capacity * (v.soc-v.reserve)/100;
        const km = budget / v.consumption * 100;
        let firstKm = null;
        if (origin && firstStop) {
            const a = point(origin.latitude,origin.longitude), b = point(firstStop.latitude,firstStop.longitude);
            if (a && b) {
                firstKm = straightKm(a,b);
            }
        }
        const needed = Number.isFinite(routeMeters) && routeMeters >= 0 && routeMeters <= 40000000
            ? routeMeters/1000*v.consumption/100 : null;
        return {budget,km,needed,firstKm,
            totalExceeds: needed === null ? null : needed > budget+1e-9,
            firstExceeds: firstKm === null ? null : firstKm > km+1e-9};
    }
    function addressSearch(request, onSelected) {
        const root = document.createElement("fieldset"),
            legend = label("legend", "Find an address", "البحث عن عنوان");
        const consent = document.createElement("input");
        consent.type = "checkbox";
        const privacy = document.createElement("label");
        privacy.append(
            consent,
            label(
                "span",
                "Allow typed addresses to be sent to Geoapify; queries are not saved as preferences.",
                "السماح بإرسال العناوين المكتوبة إلى Geoapify؛ لا تُحفظ عمليات البحث كتفضيلات.",
            ),
        );
        const input = document.createElement("input");
        input.type = "search";
        input.maxLength = 180;
        input.disabled = true;
        input.setAttribute("aria-label", "Address search / البحث عن عنوان");
        input.setAttribute("autocomplete", "off");
        const results = document.createElement("div");
        results.setAttribute("role", "listbox");
        results.setAttribute(
            "aria-label",
            "Address suggestions / اقتراحات العنوان",
        );
        const state = document.createElement("p");
        state.setAttribute("role", "status");
        const policy = label(
            "a",
            "Geoapify privacy policy",
            "سياسة خصوصية Geoapify",
        );
        policy.href = "https://www.geoapify.com/privacy-policy/";
        policy.target = "_blank";
        policy.rel = "noopener noreferrer";
        root.append(legend, policy, privacy, input, state, results);
        let timer,
            abort,
            generation = 0;
        function clear() {
            generation++;
            clearTimeout(timer);
            abort?.abort();
            results.replaceChildren();
            state.replaceChildren();
        }
        async function lookup() {
            const query = input.value.trim();
            if (!consent.checked || query.length < 3) return;
            clear();
            const current = ++generation;
            abort = new AbortController();
            state.append(
                label(
                    "span",
                    "Searching addresses…",
                    "جارٍ البحث عن العناوين…",
                ),
            );
            try {
                const response = await request(
                    "addresses",
                    "POST",
                    { consent: true, query, language: arabic() ? "ar" : "en" },
                    abort.signal,
                );
                if (current !== generation) return;
                if (
                    response.provider !== "geoapify" ||
                    response.evidence !== "GEOCODING_RESULT" ||
                    !Array.isArray(response.places) ||
                    response.places.length > 5
                )
                    throw Error("Invalid address evidence");
                state.replaceChildren();
                results.replaceChildren();
                for (const place of response.places) {
                    const p = point(place.latitude, place.longitude);
                    if (
                        !p ||
                        typeof place.label !== "string" ||
                        typeof place.id !== "string" ||
                        !place.id.startsWith("geoapify:")
                    )
                        throw Error("Invalid address point");
                    const row = document.createElement("button");
                    row.type = "button";
                    row.setAttribute("role", "option");
                    row.textContent = place.label;
                    row.onclick = () => {
                        clear();
                        input.value = place.label;
                        onSelected({ ...p, label: place.label });
                    };
                    results.append(row);
                }
                if (!response.places.length)
                    state.append(
                        label(
                            "span",
                            "No address matches. Try manual coordinates.",
                            "لا توجد عناوين مطابقة. جرب الإحداثيات اليدوية.",
                        ),
                    );
            } catch (error) {
                if (
                    current !== generation ||
                    error.name === "AbortError" ||
                    error.name === "SessionChangedError"
                )
                    return;
                state.replaceChildren(
                    label(
                        "span",
                        error.status === 429
                            ? "Search limit reached. Wait before another lookup."
                            : "Address lookup unavailable. Use GPS or manual coordinates.",
                        error.status === 429
                            ? "تم بلوغ حد البحث. انتظر قبل محاولة أخرى."
                            : "البحث عن العنوان غير متاح. استخدم الموقع أو الإحداثيات اليدوية.",
                    ),
                );
            }
        }
        consent.onchange = () => {
            clear();
            input.disabled = !consent.checked;
            if (consent.checked) input.focus();
        };
        input.oninput = () => {
            clear();
            if (consent.checked && input.value.trim().length >= 3)
                timer = setTimeout(lookup, 600);
        };
        input.onkeydown = (event) => {
            if (event.key === "ArrowDown") {
                event.preventDefault();
                results.querySelector("button")?.focus();
            }
            if (event.key === "Escape") clear();
        };
        results.onkeydown = (event) => {
            const options = [...results.querySelectorAll("button")],
                index = options.indexOf(document.activeElement);
            if (event.key === "ArrowDown" || event.key === "ArrowUp") {
                event.preventDefault();
                options[
                    (index +
                        (event.key === "ArrowDown" ? 1 : -1) +
                        options.length) %
                        options.length
                ]?.focus();
            }
            if (event.key === "Escape") {
                clear();
                input.focus();
            }
        };
        window.addEventListener("ev:locale", clear);
        return {
            root,
            clear: () => {
                clear();
                input.value = "";
                consent.checked = false;
                input.disabled = true;
            },
        };
    }
    function install({ root, request, onPoint, getCentre, onRoute }) {
        let stations = [],
            stops = [],
            requiredConnector = "",
            estimate = null,
            epoch = 0,
            capabilityEpoch = 0,
            routeAbort;
        const section = document.createElement("div");
        section.className = "discovery-tools";
        const address = document.createElement("details"),
            addressTitle = label("summary", "Address search", "البحث عن عنوان"),
            addressState = document.createElement("p");
        address.append(addressTitle, addressState);
        const search = addressSearch(request, onPoint);
        search.root.hidden = true;
        address.append(search.root);
        const journey = document.createElement("details");
        journey.id = "charging-route-planner";
        journey.append(
            label(
                "summary",
                "Plan route and charging stops",
                "تخطيط المسار وتوقفات الشحن",
            ),
        );
        const description = label(
            "p",
            "Up to three stops from this catalog page. Stops are not reserved; availability, compatibility, energy and prices remain unverified. Itinerary stays in memory.",
            "حتى ثلاث توقفات من صفحة النتائج الحالية. التوقفات غير محجوزة والتوفر والتوافق والطاقة والأسعار غير مؤكدة. يبقى المسار في الذاكرة.",
        );
        const form = document.createElement("form");
        form.noValidate = true;
        const routeState = document.createElement("p");
        routeState.setAttribute("role", "status");
        function endpoint(en, ar, prefix) {
            const fieldset = document.createElement("fieldset");
            fieldset.append(label("legend", en, ar));
            const values = {};
            for (const [name, enLabel, arLabel] of [
                ["latitude", "Latitude (−90 to 90)", "خط العرض (−90 إلى 90)"],
                [
                    "longitude",
                    "Longitude (−180 to 180)",
                    "خط الطول (−180 إلى 180)",
                ],
            ]) {
                const wrapper = document.createElement("label"),
                    input = document.createElement("input");
                wrapper.append(label("span", enLabel, arLabel));
                input.type = "text";
                input.inputMode = "decimal";
                input.maxLength = 30;
                input.name = `journey_${prefix}_${name}`;
                input.dir = "ltr";
                wrapper.append(input);
                fieldset.append(wrapper);
                values[name] = input;
                input.oninput = invalidate;
            }
            const selected = button(
                "Use selected search centre",
                "استخدام مركز البحث المحدد",
                () => {
                    const p = getCentre();
                    if (!p) {
                        routeState.replaceChildren(
                            label(
                                "span",
                                "Select a map point, GPS location or address first.",
                                "حدد نقطة على الخريطة أو الموقع أو العنوان أولاً.",
                            ),
                        );
                        return;
                    }
                    values.latitude.value = p.latitude.toFixed(6);
                    values.longitude.value = p.longitude.toFixed(6);
                    invalidate();
                },
            );
            fieldset.append(selected);
            const addresses = addressSearch(request, (p) => {
                values.latitude.value = p.latitude;
                values.longitude.value = p.longitude;
                invalidate();
            });
            const addressToggle = document.createElement("details");
            addressToggle.append(
                label("summary", "Find an address", "البحث عن عنوان"),
                addresses.root,
            );
            addresses.root = addressToggle;
            addresses.root.hidden = true;
            fieldset.append(addresses.root);
            return { fieldset, values, addresses };
        }
        function invalidate(revokeConsent = true) {
            epoch++;
            routeAbort?.abort();
            estimate = null;
            renderEnergy();
            // Consent was for the exact reviewed itinerary. Editing stops,
            // coordinates or connector filters requires a new explicit opt-in.
            if (revokeConsent) routeConsent.checked = false;
            routeState.replaceChildren();
            preview.disabled = !routeConsent.checked || !routeAvailable || !compatibleStops();
            navigation.disabled = !compatibleStops();
            onRoute([]);
        }
        const origin = endpoint("Origin", "نقطة البداية", "origin"),
            destination = endpoint("Destination", "الوجهة", "destination");
        const ordered = document.createElement("ol"),
            choices = document.createElement("div");
        const connectorFilter = document.createElement("select");
        connectorFilter.name = "route_connector";
        connectorFilter.id = "route-connector-filter";
        const connectorField = document.createElement("label");
        connectorField.append(
            label("span", "Filter by advertised connector", "تصفية حسب الموصل المعلن"),
            connectorFilter,
        );
        for (const [value, en, ar] of [
            ["", "All advertised connectors", "كل الموصلات المعلنة"],
            ["CCS2", "CCS2", "CCS2"],
            ["Type2", "Type2", "Type2"],
            ["CHAdeMO", "CHAdeMO", "CHAdeMO"],
        ]) {
            const option = label("option", en, ar);
            option.value = value;
            connectorFilter.append(option);
        }
        const mismatchWarning = label(
            "p",
            "Some selected stops use a different advertised connector. Remove them or change the filter before preview or navigation.",
            "بعض التوقفات المحددة تستخدم موصلاً معلناً مختلفاً. أزلها أو غيّر التصفية قبل المعاينة أو الملاحة.",
        );
        mismatchWarning.setAttribute("role", "status");
        mismatchWarning.hidden = true;
        const compatibleStops = () => !requiredConnector ||
            stops.every((stop) => stop.connector === requiredConnector);
        choices.className = "journey-stations";
        const routeConsent = document.createElement("input");
        routeConsent.type = "checkbox";
        const privacy = document.createElement("label");
        privacy.append(
            routeConsent,
            label(
                "span",
                "Send these points and stops to Geoapify for a driving estimate.",
                "إرسال هذه النقاط والتوقفات إلى Geoapify لتقدير القيادة.",
            ),
        );
        let routeAvailable = false;
        const preview = button(
            "Preview provider route",
            "معاينة مسار المزود",
            async () => {
                const points = readPoints();
                if (!points || !compatibleStops() || !routeConsent.checked || !routeAvailable) return;
                invalidate();
                const current = ++epoch;
                routeAbort = new AbortController();
                preview.disabled = true;
                routeState.append(
                    label(
                        "span",
                        "Loading route estimate…",
                        "جارٍ تحميل تقدير المسار…",
                    ),
                );
                try {
                    const result = await request(
                        "route",
                        "POST",
                        {
                            consent: true,
                            language: arabic() ? "ar" : "en",
                            points,
                        },
                        routeAbort.signal,
                    );
                    if (current !== epoch) return;
                    if (
                        result.provider !== "geoapify" ||
                        result.evidence !== "PROVIDER_ROUTE_ESTIMATE" ||
                        result.traffic_verified !== false ||
                        result.charging_time_included !== false ||
                        !Number.isFinite(result.distance_meters) ||
                        result.distance_meters < 0 || result.distance_meters > 40000000 ||
                        !Number.isFinite(result.duration_seconds) ||
                        result.duration_seconds < 0 || result.duration_seconds > 31536000 ||
                        !Array.isArray(result.segments) ||
                        !result.segments.length ||
                        result.segments.some(
                            (line) =>
                                !Array.isArray(line) ||
                                line.length < 2 ||
                                line.some(
                                    (p) => !point(p.latitude, p.longitude),
                                ),
                        )
                    )
                        throw Error("Invalid route evidence");
                    estimate = result;
                    renderEnergy();
                    routeState.replaceChildren();
                    const total = label(
                        "strong",
                        `${new Intl.NumberFormat("en", { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(result.distance_meters / 1000)} km · ${new Intl.NumberFormat("en").format(Math.ceil(result.duration_seconds / 60))} min`,
                        `${new Intl.NumberFormat("ar", { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(result.distance_meters / 1000)} كم · ${new Intl.NumberFormat("ar").format(Math.ceil(result.duration_seconds / 60))} دقيقة`,
                    );
                    routeState.append(
                        total,
                        label(
                            "p",
                            "Provider estimate only; traffic is unverified, charging/queues/stop time excluded. Navigation may choose a different route.",
                            "تقدير من المزود فقط؛ المرور غير مؤكد وأوقات الشحن والانتظار والتوقف غير مشمولة. قد تختار الملاحة مساراً مختلفاً.",
                        ),
                        document.createTextNode(
                            "Routing © Geoapify / OpenStreetMap contributors",
                        ),
                    );
                    onRoute(result.segments);
                } catch (error) {
                    if (
                        current !== epoch ||
                        error.name === "AbortError" ||
                        error.name === "SessionChangedError"
                    )
                        return;
                    routeState.replaceChildren(
                        label(
                            "span",
                            error.status === 429
                                ? "Route lookup limit reached. Wait before trying again."
                                : "Route estimate unavailable. Use manual navigation or change your points.",
                            error.status === 429
                                ? "تم بلوغ حد البحث عن المسار. انتظر قبل المحاولة مجدداً."
                                : "تقدير المسار غير متاح. استخدم الملاحة اليدوية أو غيّر النقاط.",
                        ),
                    );
                } finally {
                    if (current === epoch)
                        preview.disabled =
                            !routeConsent.checked || !routeAvailable || !compatibleStops();
                }
            },
        );
        preview.disabled = true;
        routeConsent.onchange = () => invalidate(false);
        const navigation = button(
            "Open navigation with selected stops",
            "فتح الملاحة بالتوقفات المحددة",
            () => {
                const points = readPoints();
                if (!points || !compatibleStops()) return;
                if (
                    !confirm(
                        arabic()
                            ? "إرسال نقطة البداية والوجهة والتوقفات إلى خرائط Google؟ تطبق سياسة خصوصية المزود. التوقفات غير محجوزة وقد يختلف المسار."
                            : "Send origin, destination and stops to Google Maps? Its privacy policy applies. Stops are not reserved and its route can differ.",
                    )
                )
                    return;
                const url = new URL("https://www.google.com/maps/dir/");
                url.searchParams.set("api", "1");
                url.searchParams.set("origin", coordinate(points[0]));
                url.searchParams.set("destination", coordinate(points.at(-1)));
                url.searchParams.set("travelmode", "driving");
                if (stops.length)
                    url.searchParams.set(
                        "waypoints",
                        stops.map((s) => coordinate(s.point)).join("|"),
                    );
                window.open(url.href, "_blank", "noopener,noreferrer");
            },
        );
        function readPoints() {
            if (!compatibleStops()) {
                routeState.replaceChildren(label(
                    "span",
                    "Selected stops have an incompatible advertised connector. Remove them before navigation.",
                    "التوقفات المحددة لها موصل معلن غير مطابق. أزلها قبل الملاحة.",
                ));
                return null;
            }
            const a = point(
                    origin.values.latitude.value,
                    origin.values.longitude.value,
                ),
                b = point(
                    destination.values.latitude.value,
                    destination.values.longitude.value,
                );
            if (!a || !b) {
                routeState.replaceChildren(
                    label(
                        "span",
                        "Enter valid origin/destination coordinates within the displayed ranges.",
                        "أدخل إحداثيات بداية ووجهة صالحة ضمن النطاق المعروض.",
                    ),
                );
                return null;
            }
            return [a, ...stops.map((s) => s.point), b];
        }
        function renderStops() {
            ordered.replaceChildren();
            for (const [i, stop] of stops.entries()) {
                const li = document.createElement("li"),
                    name = document.createElement("strong");
                name.textContent = stop.name;
                const evidence = document.createElement("p");
                if (requiredConnector && stop.connector !== requiredConnector) {
                    evidence.append(label(
                        "span",
                        "Different advertised connector · ",
                        "موصل معلن مختلف · ",
                    ));
                }
                evidence.append(
                    document.createTextNode(`${stop.evidence} · `),
                    label("span", "Not reserved", "غير محجوز"),
                );
                const up = button("Move earlier", "نقل للأعلى", () => {
                    [stops[i - 1], stops[i]] = [stops[i], stops[i - 1]];
                    invalidate();
                    renderStops();
                });
                up.disabled = i === 0;
                li.append(
                    name,
                    evidence,
                    up,
                    button("Remove stop", "إزالة التوقف", () => {
                        stops.splice(i, 1);
                        invalidate();
                        renderStops();
                    }),
                );
                ordered.append(li);
            }
            choices.replaceChildren();
            mismatchWarning.hidden = compatibleStops();
            navigation.disabled = !compatibleStops();
            preview.disabled = !routeConsent.checked || !routeAvailable || !compatibleStops();
            for (const station of stations) {
                const p = point(station.latitude, station.longitude);
                if (!p) continue;
                const add = button("Add charging stop", "إضافة توقف شحن", () =>
                        addStop(station),
                    ),
                    name = document.createElement("span");
                name.textContent = station.name;
                add.disabled =
                    stops.length >= 3 || stops.some((s) => s.id === station.id) ||
                    (requiredConnector !== "" && station.connector_type !== requiredConnector);
                const row = document.createElement("div");
                row.append(name, add);
                choices.append(row);
            }
        }
        function addStop(station) {
            const p = point(station.latitude, station.longitude);
            if (
                !p ||
                stops.length >= 3 ||
                stops.some((s) => s.id === station.id) ||
                (requiredConnector !== "" && station.connector_type !== requiredConnector)
            )
                return;
            stops.push({
                id: station.id,
                name: station.name,
                evidence: station.evidence,
                connector: station.connector_type,
                point: p,
            });
            journey.open = true;
            invalidate();
            renderStops();
        }
        const service = label(
            "p",
            "Provider lookups are not configured. GPS, manual coordinates and external navigation remain available.",
            "عمليات بحث المزود غير مهيأة. يبقى الموقع والإحداثيات اليدوية والملاحة الخارجية متاحين.",
        );
        const stopTitle = label(
                "h3",
                "Ordered charging stops (maximum 3)",
                "توقفات الشحن بالترتيب (٣ كحد أقصى)",
            ),
            catalog = document.createElement("details");
        catalog.append(
            label(
                "summary",
                "Choose stops from current results",
                "اختيار توقفات من النتائج الحالية",
            ),
            choices,
        );
        const energy = document.createElement("details");
        energy.id = "journey-energy-review";
        energy.append(label("summary","Review estimated battery energy","مراجعة طاقة البطارية التقديرية"));
        const energyFields = {};
        const energyForm = document.createElement("fieldset");
        energyForm.append(label("legend","Driver-entered assumptions only","افتراضات يُدخلها السائق فقط"));
        for (const [key,en,ar] of [
            ["capacity","Usable battery capacity (kWh)","سعة البطارية القابلة للاستخدام (كيلوواط ساعة)"],
            ["soc","Starting charge (%)","الشحن عند البداية (%)"],
            ["reserve","Minimum reserve (%)","الاحتياطي الأدنى (%)"],
            ["consumption","Assumed consumption (kWh/100 km)","الاستهلاك المفترض (كيلوواط ساعة/١٠٠ كم)"],
        ]) {
            const wrap = document.createElement("label"), input = document.createElement("input");
            wrap.append(label("span",en,ar),input);
            input.name = "energy_"+key;
            input.type = "text";
            input.inputMode = "decimal";
            input.maxLength = 6;
            input.dir = "ltr";
            input.autocomplete = "off";
            input.oninput = () => renderEnergy();
            energyFields[key] = input;
            energyForm.append(wrap);
        }
        const energyState = document.createElement("div");
        energyState.setAttribute("role","status");
        const showEnergy = (en,ar) => energyState.append(label("p",en,ar));
        function renderEnergy() {
            energyState.replaceChildren();
            const raw = Object.fromEntries(Object.entries(energyFields).map(([k,v])=>[k,v.value]));
            if (Object.values(raw).every(v=>!v.trim())) return;
            const start = point(origin.values.latitude.value,origin.values.longitude.value);
            const first = stops.length ? stops[0].point : null;
            const review = energyReview(raw,estimate?.distance_meters,start,first);
            if (!review) {
                showEnergy("Enter capacity 1–250, charge and reserve 0–100 (reserve below charge), and consumption 1–100; up to two decimals.",
                    "أدخل السعة ١–٢٥٠ والشحن والاحتياطي ٠–١٠٠ (الاحتياطي أقل من الشحن) والاستهلاك ١–١٠٠؛ حتى منزلتين عشريتين.");
                return;
            }
            const fmt=(n,lang)=>new Intl.NumberFormat(lang,{maximumFractionDigits:1,minimumFractionDigits:1}).format(n);
            const pair=(n)=>[fmt(n,"en"),fmt(n,"ar")];
            const [budget,budgetAr]=pair(review.budget), [km,kmAr]=pair(review.km);
            showEnergy(`Estimated energy above reserve: ${budget} kWh · assumed range: ${km} km`,
                `الطاقة التقديرية فوق الاحتياطي: ${budgetAr} كيلوواط ساعة · المدى المفترض: ${kmAr} كم`);
            if(review.firstKm !== null) {
                const [d,dAr]=pair(review.firstKm);
                showEnergy(`First stop straight-line distance: ${d} km`,`المسافة الجوية إلى أول توقف: ${dAr} كم`);
                showEnergy(review.firstExceeds
                    ? "First stop exceeds the assumed range even in a straight line."
                    : "First stop not ruled out by straight-line distance; road reachability is unverified.",
                    review.firstExceeds ? "أول توقف يتجاوز المدى المفترض حتى بخط مستقيم." : "لا تستبعد المسافة الجوية أول توقف؛ الوصول عبر الطريق غير مؤكد.");
            }
            if(review.needed !== null) {
                const [n,nAr]=pair(review.needed);
                showEnergy(`Whole-route assumed energy: ${n} kWh`,`الطاقة المفترضة للمسار الكامل: ${nAr} كيلوواط ساعة`);
                showEnergy(review.totalExceeds ? "Whole-route estimate exceeds starting energy above reserve. Charging may be needed." : "Whole-route estimate is within the assumed starting energy. This is not a reachability guarantee.",
                    review.totalExceeds ? "يتجاوز تقدير المسار الكامل الطاقة عند البداية فوق الاحتياطي. قد يلزم الشحن." : "تقدير المسار الكامل ضمن طاقة البداية المفترضة. لا يضمن ذلك الوصول.");
            } else showEnergy("Preview a provider route for a whole-route energy estimate.","عاين مسار المزود لتقدير طاقة المسار الكامل.");
            if (start) {
                const candidates = energyCandidates(raw,start,stations,requiredConnector);
                showEnergy("First-stop candidates from this result page (nearest straight-line distance first). Review a candidate in the existing charging-stop selector; nothing is selected automatically.",
                    "مرشحات التوقف الأول من صفحة النتائج الحالية (الأقرب بالمسافة الجوية أولاً). راجع المرشح في محدد توقفات الشحن؛ لا يُختار شيء تلقائياً.");
                if (!candidates.length) showEnergy("No supported candidate on this page within the assumed straight-line range. Search another area or revise your assumptions.",
                    "لا يوجد مرشح مدعوم في هذه الصفحة ضمن المدى الجوي المفترض. ابحث في منطقة أخرى أو راجع افتراضاتك.");
                else {
                    const list = document.createElement("ul");
                    list.id = "energy-first-stop-candidates";
                    for (const candidate of candidates) {
                        const li = document.createElement("li");
                        const name = document.createElement("strong");
                        name.textContent = candidate.name;
                        const [distance,distanceAr] = pair(candidate.km);
                        li.append(name,label("span",
                            ` · ${distance} km straight line · ${candidate.connector} · ${candidate.power} kW advertised · ${candidate.evidence}`,
                            ` · ${distanceAr} كم جواً · ${candidate.connector} · ${candidate.power} كيلوواط معلنة · ${candidate.evidence}`));
                        list.append(li);
                    }
                    energyState.append(list);
                    showEnergy("Candidates are not verified reachable by road, compatible, available or reservable.",
                        "لم يتم التحقق من إمكانية الوصول إلى المرشحات عبر الطريق أو توافقها أو توفرها أو قابليتها للحجز.");
                }
            }
        }
        energy.append(energyForm,label("p",
            "Local arithmetic only. Not live vehicle SOC, verified range, station availability, road reachability or charging advice. Weather, terrain and charging losses are excluded. No recharge is assumed. These inputs are never sent to the provider.",
            "حساب محلي فقط. لا يمثل شحن المركبة الفعلي أو مدى مؤكداً أو توفر المحطات أو إمكانية الوصول أو نصيحة شحن. لا يشمل الطقس أو التضاريس أو خسائر الشحن. لا يفترض إعادة الشحن. لا تُرسل المدخلات إلى المزود."),energyState);
        form.append(
            origin.fieldset,
            destination.fieldset,
            stopTitle,
            ordered,
            connectorField,
            label("p", "Published connector labels are not verified vehicle fit or live charger status.", "تسميات الموصلات المنشورة ليست تحققاً من توافق المركبة أو حالة الشاحن المباشرة."),
            mismatchWarning,
            catalog,
            service,
            privacy,
            preview,
            navigation,
            routeState,
            energy,
        );
        connectorFilter.onchange = () => {
            requiredConnector = connectorFilter.value;
            invalidate();
            renderStops();
        };
        form.onsubmit = (event) => event.preventDefault();
        journey.append(description, form);
        section.append(address, journey);
        root.append(section);
        async function capabilities() {
            // Session clear and subsequent requests invalidate delayed
            // provider capabilities from the previous authenticated context.
            const requestGeneration = ++capabilityEpoch;
            try {
                const c = await request("capabilities", "GET");
                if (requestGeneration !== capabilityEpoch) return;
                if (
                    c.provider !== "geoapify" ||
                    typeof c.geocoding !== "boolean" ||
                    typeof c.routing !== "boolean"
                )
                    throw Error("Invalid capabilities");
                search.root.hidden =
                    origin.addresses.root.hidden =
                    destination.addresses.root.hidden =
                        !c.geocoding;
                addressState.replaceChildren();
                if (!c.geocoding)
                    addressState.append(
                        label(
                            "span",
                            "Address provider is not configured. Use GPS or manual coordinates.",
                            "مزود العناوين غير مهيأ. استخدم الموقع أو الإحداثيات اليدوية.",
                        ),
                    );
                routeAvailable = c.routing;
                service.hidden = c.routing;
                preview.disabled = !routeConsent.checked || !routeAvailable || !compatibleStops();
                privacy.hidden = !routeAvailable;
            } catch (_) {
                if (requestGeneration !== capabilityEpoch) return;
                search.root.hidden =
                    origin.addresses.root.hidden =
                    destination.addresses.root.hidden =
                        true;
                routeAvailable = false;
                preview.disabled = true;
            }
        }
        address.ontoggle = () => {
            if (address.open) capabilities();
        };
        journey.ontoggle = () => {
            if (journey.open) capabilities();
        };
        const controls = {
            update(data) {
                stations = data;
                renderStops();
                renderEnergy();
            },
            addStop,
            clear() {
                epoch++;
                capabilityEpoch++;
                routeAbort?.abort();
                // These catalog entries may be tenant-private. A session
                // reset must clear both selected stops and old search results.
                stations = [];
                stops = [];
                requiredConnector = "";
                connectorFilter.value = "";
                estimate = null;
                // A prior account's provider permission is never retained.
                routeAvailable = false;
                service.hidden = false;
                privacy.hidden = true;
                search.root.hidden =
                    origin.addresses.root.hidden =
                    destination.addresses.root.hidden = true;
                preview.disabled = true;
                navigation.disabled = false;
                for (const box of [origin, destination]) {
                    box.values.latitude.value = box.values.longitude.value = "";
                    box.addresses.clear();
                }
                search.clear();
                for(const input of Object.values(energyFields)) input.value = "";
                energyState.replaceChildren();
                energy.open = false;
                routeConsent.checked = false;
                routeState.replaceChildren();
                onRoute([]);
                renderStops();
            },
            refresh: capabilities,
        };
        // Browser visibility is not account logout, but private route inputs
        // must not remain visible or dispatch after backgrounding. Clear only
        // in-memory tool state, without GPS, provider calls or auto-refresh.
        const onHidden = () => {
            if (!section.isConnected) {
                document.removeEventListener("visibilitychange", onHidden);
                window.removeEventListener("pagehide", onPageHide);
                return;
            }
            if (document.hidden) controls.clear();
        };
        const onPageHide = () => {
            controls.clear();
            document.removeEventListener("visibilitychange", onHidden);
            window.removeEventListener("pagehide", onPageHide);
        };
        document.addEventListener("visibilitychange", onHidden);
        window.addEventListener("pagehide", onPageHide);
        return controls;
    }
    window.EvGeoTools = { install, point, navigationCoordinate: coordinate, energyReview, energyCandidates };
})();
