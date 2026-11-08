/* Public vendor-reported station facts; never a charger/price/availability authority. */
(function () {
    "use strict";
    const days = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"];
    const dayEn = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
    const dayAr = ["الاثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت", "الأحد"];
    const amenities = {
        restroom: ["Restroom", "دورة مياه"], wifi: ["Wi-Fi", "واي فاي"],
        cafe: ["Café", "مقهى"], parking: ["Parking", "موقف سيارات"],
        lighting: ["Lighting", "إضاءة"], shelter: ["Shelter", "مأوى"],
        shop: ["Shop", "متجر"], security: ["Security", "أمن"],
    };
    const accessible = {
        step_free: ["Step-free access", "دخول بدون درجات"],
        accessible_parking: ["Accessible parking", "مواقف مهيأة"],
        accessible_restroom: ["Accessible restroom", "دورة مياه مهيأة"],
        wide_bays: ["Wide bays", "مواقف واسعة"],
    };
    const isArabic = () => document.documentElement.lang === "ar";
    const label = (en, ar, tag = "span") => {
        const node = document.createElement(tag);
        node.dataset.en = en;
        node.dataset.ar = ar;
        node.textContent = isArabic() ? ar : en;
        return node;
    };
    const appendText = (root, en, ar) => root.append(label(en, ar, "p"));
    const safeText = (value) => typeof value === "string" ? value : "";
    const fallback = (value, alternative) => safeText(value).trim() || safeText(alternative).trim();
    const summaryText = (info, en, ar) => {
        const out = label(en, ar, "details");
        return out;
    };
    function attachCard(card, station) {
        const info = station.information || {};
        const details = document.createElement("details");
        details.className = "station-information";
        details.append(label("Hours, access & facilities", "ساعات العمل والدخول والمرافق", "summary"));
        if (info.source_label !== "VENDOR_REPORTED_UNVERIFIED") {
            appendText(details, "Operator has not published station hours or facilities.", "لم ينشر المشغل ساعات العمل أو تفاصيل المرافق.");
            card.append(details);
            return;
        }
        appendText(details, "Operator-reported details — not independently verified; live availability remains unknown.", "تفاصيل أفاد بها المشغل ولم يتم التحقق منها؛ التوفر الفعلي غير معروف.");
        const zone = typeof station.timezone === "string" ? station.timezone.trim() : "";
        appendText(details,
            zone ? "Opening hours: station local time (" + zone + ")." : "Opening hours: station local time; timezone not provided.",
            zone ? "ساعات العمل بالتوقيت المحلي للمحطة (" + zone + ")." : "ساعات العمل بالتوقيت المحلي للمحطة؛ المنطقة الزمنية غير متوفرة.");
        const description = fallback(info.description_en, info.description_ar);
        if (description) appendText(details, "About: " + fallback(info.description_en, description), "الوصف: " + fallback(info.description_ar, description));
        const access = fallback(info.access_en, info.access_ar);
        if (access) appendText(details, "Access: " + fallback(info.access_en, access), "الدخول: " + fallback(info.access_ar, access));
        if (info.notice_active === true) {
            const notice = fallback(info.notice_en, info.notice_ar);
            if (notice) appendText(details,
                "Operator access notice: " + fallback(info.notice_en, notice),
                "تنبيه دخول من المشغل: " + fallback(info.notice_ar, notice));
            if (notice && typeof info.notice_until === "string")
                appendText(details, "Notice expires (UTC): " + info.notice_until,
                    "ينتهي التنبيه (UTC): " + info.notice_until);
        }
        const hours = info.weekly_hours || {};
        const hourLines = [];
        for (let i = 0; i < days.length; i++) {
            const range = hours[days[i]] || {};
            const open = typeof range.open === "string" ? range.open : null;
            const close = typeof range.close === "string" ? range.close : null;
            const twentyFour = open && close && open === close;
            const overnight = open && close && open > close;
            const en = !open || !close ? "Closed" : twentyFour ? "24 hours"
                : open + "–" + close + (overnight ? " (next day)" : "");
            const ar = !open || !close ? "مغلق" : twentyFour ? "٢٤ ساعة"
                : open + "–" + close + (overnight ? " (لليوم التالي)" : "");
            hourLines.push([dayEn[i] + ": " + en, dayAr[i] + ": " + ar]);
        }
        for (const [en, ar] of hourLines) appendText(details, en, ar);
        if (Array.isArray(info.closed_dates) && info.closed_dates.length)
            appendText(details, "Holiday closures: " + info.closed_dates.join(", "), "إغلاقات العطلات: " + info.closed_dates.join("، "));
        for (const [field, table, en, ar] of [
            ["amenities", amenities, "Facilities: ", "المرافق: "],
            ["accessibility", accessible, "Accessibility: ", "إمكانية الوصول: "],
        ]) {
            const items = Array.isArray(info[field]) ? info[field].filter(k => Object.hasOwn(table, k)) : [];
            if (items.length) appendText(details, en + items.map(k => table[k][0]).join(", "), ar + items.map(k => table[k][1]).join("، "));
        }
        appendText(details, "These hours are informational, not a booking eligibility or charger status guarantee.", "ساعات العمل للعلم فقط ولا تضمن إمكانية الحجز أو حالة الشاحن.");
        card.append(details);
    }
    function createVendorWorkspace({root, api, actor, epoch = () => 0}) {
        let currentActor = null, selected = null, information = null, generation = 0,
            session = 0, listVersion = 0, page = 1, loading = false;
        const state = () => actor() === currentActor &&
            session === epoch() && currentActor?.role === "vendor";
        function clear() {
            generation++; listVersion++;
            currentActor = null; session = 0; selected = information = null;
            page = 1; loading = false;
            root.replaceChildren();
        }
        function status(message, error = false) {
            let line = root.querySelector("[data-station-info-state]");
            if (!line) {
                line = document.createElement("p");
                line.dataset.stationInfoState = "1";
                line.setAttribute("role", "status");
                line.setAttribute("aria-live", "polite");
                root.prepend(line);
            }
            line.textContent = message;
            line.classList.toggle("error", error);
        }
        const field = (form, key, en, ar, value = "", multiline = false) => {
            const wrap = document.createElement("label");
            wrap.append(label(en, ar));
            const input = document.createElement(multiline ? "textarea" : "input");
            input.name = key;
            input.value = safeText(value);
            input.maxLength = multiline ? 2500 : 500;
            if (multiline) input.rows = 3;
            wrap.append(input);
            form.append(wrap);
            return input;
        };
        async function selectStation(station, expectedListVersion = listVersion) {
            // A detached station picker must not load another tenant's data or
            // overwrite a newly selected station after a list refresh.
            if (!state()) { clear(); return; }
            if (expectedListVersion !== listVersion) return;
            const ticket = ++generation, owner = currentActor;
            // Remove the previous station's form before starting a new GET.
            // Otherwise its old submit closure could write stale content into
            // the newly selected station during a slow network response.
            root.querySelector("form")?.remove();
            selected = station || null;
            information = null;
            if (!station) { status(isArabic() ? "المحطة غير متاحة" : "Station no longer available.", true); return; }
            status(isArabic() ? "جارٍ تحميل التفاصيل..." : "Loading station information…");
            try {
                const data = await api("stations/" + station.id + "/information");
                if (ticket !== generation || !state() || actor() !== owner) return;
                information = data;
                renderForm();
                status("");
            } catch (e) {
                if (ticket === generation && state()) status(String(e.message || e), true);
            }
        }
        function renderForm() {
            root.querySelector("form")?.remove();
            if (!selected || !information || !state()) return;
            const info = information;
            const formStationId = selected.id, formTicket = generation;
            const form = document.createElement("form");
            form.className = "station-information-form";
            form.append(label("Edit information for " + selected.name, "تعديل معلومات " + selected.name, "h3"));
            appendText(form, "Vendor-reported only. Does not change live status, fees or charger control.", "معلومات من المشغل فقط ولا تغير حالة الشحن أو الرسوم أو التحكم.");
            field(form, "description_en", "Description (English)", "الوصف (بالإنجليزية)", info.description_en);
            field(form, "description_ar", "Description (Arabic)", "الوصف (بالعربية)", info.description_ar);
            field(form, "access_en", "Access instructions (English)", "تعليمات الدخول (بالإنجليزية)", info.access_en);
            field(form, "access_ar", "Access instructions (Arabic)", "تعليمات الدخول (بالعربية)", info.access_ar);
            field(form, "notice_en", "Temporary access notice (English)", "تنبيه وصول مؤقت (بالإنجليزية)", info.notice_en).maxLength = 300;
            field(form, "notice_ar", "Temporary access notice (Arabic)", "تنبيه وصول مؤقت (بالعربية)", info.notice_ar).maxLength = 300;
            const noticeUntil = field(form, "notice_until", "Notice expiry (UTC calendar date, inclusive)", "انتهاء التنبيه (تاريخ UTC شامل)", info.notice_until);
            noticeUntil.type = "date";
            const week = document.createElement("fieldset");
            const timezone = typeof selected.timezone === "string" && selected.timezone.trim()
                ? selected.timezone.trim() : null;
            week.append(label(
                timezone ? "Opening hours, station local time (" + timezone + ")"
                    : "Opening hours, station local time; timezone not provided",
                timezone ? "ساعات العمل بتوقيت المحطة (" + timezone + ")"
                    : "ساعات العمل بتوقيت المحطة؛ المنطقة الزمنية غير متوفرة",
                "legend"));
            const inputs = {};
            for (let i = 0; i < days.length; i++) {
                const day = days[i], hours = info.weekly_hours?.[day] || {};
                const row = document.createElement("div");
                row.className = "station-hours-row";
                const closed = document.createElement("input");
                closed.type = "checkbox";
                closed.checked = !hours.open || !hours.close;
                const closedLabel = document.createElement("label");
                closedLabel.append(closed, label(dayEn[i] + " closed", dayAr[i] + " مغلق"));
                const open = document.createElement("input"), close = document.createElement("input");
                open.type = close.type = "time";
                open.value = hours.open || ""; close.value = hours.close || "";
                const toggle = () => { open.disabled = close.disabled = closed.checked; };
                closed.onchange = toggle; toggle();
                const openLabel = document.createElement("label");
                openLabel.append(label("Opens", "يفتح"), open);
                const closeLabel = document.createElement("label");
                closeLabel.append(label("Closes", "يغلق"), close);
                row.append(closedLabel, openLabel, closeLabel);
                week.append(row);
                inputs[day] = {closed, open, close};
            }
            form.append(week);
            const dates = field(form, "closed_dates", "Holiday closure dates (YYYY-MM-DD, one per line)", "تواريخ إغلاق العطلات (تاريخ في كل سطر)", (info.closed_dates || []).join("\n"), true);
            const sets = {};
            for (const [fieldName, table, en, ar] of [
                ["amenities", amenities, "Facilities", "المرافق"],
                ["accessibility", accessible, "Accessibility", "إمكانية الوصول"],
            ]) {
                const f = document.createElement("fieldset");
                f.append(label(en, ar, "legend"));
                sets[fieldName] = {};
                for (const [k, captions] of Object.entries(table)) {
                    const choice = document.createElement("label");
                    const check = document.createElement("input");
                    check.type = "checkbox"; check.checked = (info[fieldName] || []).includes(k);
                    choice.append(check, label(captions[0], captions[1]));
                    f.append(choice); sets[fieldName][k] = check;
                }
                form.append(f);
            }
            const submit = document.createElement("button");
            submit.type = "submit";
            submit.append(label("Save station information", "حفظ معلومات المحطة"));
            form.append(submit);
            form.onsubmit = async e => {
                e.preventDefault();
                if (!state() || loading || generation !== formTicket ||
                    selected?.id !== formStationId || information !== info) return;
                const weekly = {};
                for (const day of days) {
                    const w = inputs[day];
                    if (!w.closed.checked && (!w.open.value || !w.close.value)) {
                        status(isArabic() ? "تحقق من أوقات الفتح والإغلاق" : "Opening and closing times are both required for open days.", true);
                        return;
                    }
                    weekly[day] = w.closed.checked ? {open: null, close: null} : {open: w.open.value, close: w.close.value};
                }
                const uniqueDates = [...new Set(dates.value.split(/[\n,]+/).map(s => s.trim()).filter(Boolean))];
                if (uniqueDates.length > 50) { status(isArabic() ? "الحد الأقصى 50 تاريخاً" : "At most 50 holiday dates.", true); return; }
                const noticeText = form.elements.notice_en.value.trim() || form.elements.notice_ar.value.trim();
                if (!!noticeText !== !!noticeUntil.value) {
                    status(isArabic() ? "يجب إدخال تاريخ انتهاء مع التنبيه أو حذف الاثنين" : "Provide an expiry date with a notice, or clear both.", true);
                    return;
                }
                const body = {
                    revision: info.revision,
                    notice_en: form.elements.notice_en.value.trim() || null,
                    notice_ar: form.elements.notice_ar.value.trim() || null,
                    notice_until: noticeUntil.value || null,
                    description_en: form.elements.description_en.value.trim() || null,
                    description_ar: form.elements.description_ar.value.trim() || null,
                    access_en: form.elements.access_en.value.trim() || null,
                    access_ar: form.elements.access_ar.value.trim() || null,
                    weekly_hours: weekly,
                    closed_dates: uniqueDates,
                    amenities: Object.keys(sets.amenities).filter(k => sets.amenities[k].checked),
                    accessibility: Object.keys(sets.accessibility).filter(k => sets.accessibility[k].checked),
                };
                const ticket = generation, owner = currentActor, stationId = formStationId;
                loading = true; submit.disabled = true;
                status(isArabic() ? "جارٍ الحفظ..." : "Saving…");
                try {
                    const saved = await api("stations/" + stationId + "/information", "PUT", body);
                    if (ticket !== generation || actor() !== owner) return;
                    information = saved;
                    renderForm();
                    status(isArabic() ? "تم الحفظ" : "Saved");
                } catch (e) {
                    if (ticket === generation && state()) {
                        status(e.status === 409
                            ? (isArabic() ? "حدث تغيير في المعلومات. حدّث قبل الحفظ مجدداً." : "Information changed elsewhere. Refresh before saving.")
                            : String(e.message || e), true);
                    }
                } finally {
                    // A previous session's in-flight save cannot release the
                    // loading lock for a newly selected station.
                    if (ticket === generation && state()) {
                        loading = false;
                        submit.disabled = false;
                    }
                }
            };
            root.append(form);
        }
        async function reload(target = 1) {
            if (!state()) { clear(); return; }
            const ticket = ++generation, listTicket = ++listVersion, owner = currentActor;
            page = target;
            selected = information = null;
            root.replaceChildren();
            status(isArabic() ? "جارٍ تحميل محطاتك..." : "Loading your stations…");
            try {
                const result = await api("vendor/stations?page=" + page);
                if (ticket !== generation || !state() || actor() !== owner) return;
                root.replaceChildren();
                root.append(label("Station information", "معلومات المحطات", "h3"));
                const refresh = document.createElement("button");
                refresh.type = "button"; refresh.append(label("Refresh", "تحديث"));
                refresh.onclick = () => {
                    if (state() && listTicket === listVersion) return reload(page);
                }; root.append(refresh);
                if (!result.data?.length) {
                    appendText(root, "No stations registered for this vendor.", "لا توجد محطات مسجلة لهذا المشغل.");
                    return;
                }
                const picker = document.createElement("select");
                picker.setAttribute("aria-label", "Your station / محطتك");
                for (const s of result.data) {
                    const opt = new Option(s.name + " (#" + s.id + ")", String(s.id));
                    picker.add(opt);
                }
                picker.onchange = () => {
                    if (state() && listTicket === listVersion)
                        return selectStation(result.data.find(s => String(s.id) === picker.value), listTicket);
                };
                root.append(picker);
                for (const [en, ar, target, enabled] of [
                    ["Previous", "السابق", page - 1, page > 1],
                    ["Next", "التالي", page + 1, page < result.last_page],
                ]) {
                    const btn = document.createElement("button");
                    btn.type = "button"; btn.disabled = !enabled;
                    btn.append(label(en, ar));
                    btn.onclick = () => {
                        if (state() && listTicket === listVersion) return reload(target);
                    }; root.append(btn);
                }
                await selectStation(result.data[0], listTicket);
            } catch (e) {
                if (ticket === generation && state()) status(String(e.message || e), true);
            }
        }
        async function setActor(value) {
            if (value?.role !== "vendor") { clear(); return; }
            if (currentActor === value && session === epoch()) return;
            clear();
            currentActor = value;
            session = epoch();
            await reload();
        }
        return {clear, setActor, reload};
    }
    window.StationInformation = {attachCard, createVendorWorkspace};
})();
