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
            estimate = null,
            epoch = 0,
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
        function invalidate() {
            epoch++;
            routeAbort?.abort();
            estimate = null;
            routeState.replaceChildren();
            preview.disabled = !routeConsent.checked || !routeAvailable;
            onRoute([]);
        }
        const origin = endpoint("Origin", "نقطة البداية", "origin"),
            destination = endpoint("Destination", "الوجهة", "destination");
        const ordered = document.createElement("ol"),
            choices = document.createElement("div");
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
                if (!points || !routeConsent.checked || !routeAvailable) return;
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
                        result.distance_meters < 0 ||
                        !Number.isFinite(result.duration_seconds) ||
                        result.duration_seconds < 0 ||
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
                            !routeConsent.checked || !routeAvailable;
                }
            },
        );
        preview.disabled = true;
        routeConsent.onchange = invalidate;
        const navigation = button(
            "Open navigation with selected stops",
            "فتح الملاحة بالتوقفات المحددة",
            () => {
                const points = readPoints();
                if (!points) return;
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
            for (const station of stations) {
                const p = point(station.latitude, station.longitude);
                if (!p) continue;
                const add = button("Add charging stop", "إضافة توقف شحن", () =>
                        addStop(station),
                    ),
                    name = document.createElement("span");
                name.textContent = station.name;
                add.disabled =
                    stops.length >= 3 || stops.some((s) => s.id === station.id);
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
                stops.some((s) => s.id === station.id)
            )
                return;
            stops.push({
                id: station.id,
                name: station.name,
                evidence: station.evidence,
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
        form.append(
            origin.fieldset,
            destination.fieldset,
            stopTitle,
            ordered,
            catalog,
            service,
            privacy,
            preview,
            navigation,
            routeState,
        );
        form.onsubmit = (event) => event.preventDefault();
        journey.append(description, form);
        section.append(address, journey);
        root.append(section);
        async function capabilities() {
            try {
                const c = await request("capabilities", "GET");
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
                preview.disabled = !routeConsent.checked || !routeAvailable;
                privacy.hidden = !routeAvailable;
            } catch (_) {
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
        return {
            update(data) {
                stations = data;
                renderStops();
            },
            addStop,
            clear() {
                epoch++;
                routeAbort?.abort();
                stops = [];
                estimate = null;
                for (const box of [origin, destination]) {
                    box.values.latitude.value = box.values.longitude.value = "";
                    box.addresses.clear();
                }
                search.clear();
                routeConsent.checked = false;
                routeState.replaceChildren();
                onRoute([]);
                renderStops();
            },
            refresh: capabilities,
        };
    }
    window.EvGeoTools = { install, point, navigationCoordinate: coordinate };
})();
