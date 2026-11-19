"use strict";
window.EvCompanySessionObservations = (() => {
    let dispose = null;
    const states = {
        pending_start: ["Recorded start pending", "بدء مسجل قيد الانتظار"],
        charging: ["Recorded charging", "شحن مسجل"],
        stopping: ["Recorded stop pending", "إيقاف مسجل قيد الانتظار"],
        stopped: ["Recorded stopped", "متوقف مسجل"],
        cancelled: ["Cancelled before start", "ملغى قبل البدء"],
        expired_unstarted: ["Expired without starting", "انتهى دون بدء"],
        unknown: ["Unknown recorded status", "حالة مسجلة غير معروفة"],
    };
    const evidenceText = {
        SIMULATED: ["Simulated record", "سجل تجريبي"],
        SIMULATED_CSMS: ["Simulated CSMS record", "سجل نظام شحن تجريبي"],
        RECORDED_SOURCE_UNVERIFIED: [
            "Recorded source; physical charging unverified",
            "مصدر مسجل؛ الشحن الفعلي غير موثق",
        ],
    };
    const integer = (value, positive = false) =>
        Number.isSafeInteger(value) && value >= (positive ? 1 : 0);
    function instant(value) {
        if (
            typeof value !== "string" ||
            !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(
                value,
            ) ||
            !Number.isFinite(Date.parse(value)) ||
            new Date(value).toISOString().slice(0, 19) !== value.slice(0, 19)
        )
            throw Error("Invalid recorded time");
        return value;
    }
    function project(value, company, page, filter) {
        if (
            value?.scope !== "company_recorded_charging_sessions" ||
            value.company_id !== company ||
            value.status_filter !== filter ||
            value.inventory_station_scope !==
                "current_owned_unique_site_links" ||
            [
                "customer_identity_fields_included",
                "financial_fields_included",
                "control_actions_exposed",
                "physical_charging_verified",
                "live_availability_verified",
            ].some((key) => value[key] !== false) ||
            !Array.isArray(value.data) ||
            value.data.length > 25 ||
            value.per_page !== 25 ||
            value.current_page !== page ||
            !integer(page, true) ||
            page > 100000 ||
            !integer(value.last_page, true) ||
            !integer(value.total) ||
            value.data.length > value.total ||
            value.last_page !==
                (value.total === 0
                    ? 1
                    : Math.floor((value.total - 1) / 25) + 1) ||
            (page > value.last_page && value.data.length)
        )
            throw Error("Invalid company observation page");
        const records = value.data.map((row) => {
            if (
                !row ||
                !integer(row.id, true) ||
                !integer(row.station_id, true) ||
                typeof row.station_name !== "string" ||
                !row.station_name ||
                [...row.station_name].length > 255 ||
                !Object.hasOwn(states, row.recorded_status) ||
                !["recorded", "unavailable"].includes(row.energy_state) ||
                (row.energy_state === "recorded"
                    ? !integer(row.energy_wh)
                    : row.energy_wh !== null) ||
                ![null, "1.6", "2.0.1"].includes(row.protocol) ||
                ![
                    "SIMULATED",
                    "SIMULATED_CSMS",
                    "RECORDED_SOURCE_UNVERIFIED",
                ].includes(row.evidence) ||
                (row.evidence === "SIMULATED_CSMS" && row.protocol === null) ||
                (row.evidence === "SIMULATED" && row.protocol !== null) ||
                row.physical_charging_verified !== false ||
                row.live_availability_verified !== false
            )
                throw Error("Invalid session observation");
            return Object.freeze({
                id: row.id,
                station: row.station_name,
                state: row.recorded_status,
                energy: row.energy_wh,
                evidence: row.evidence,
                updated: instant(row.record_updated_at),
                created: instant(row.record_created_at),
                stopped:
                    row.stopped_at === null ? null : instant(row.stopped_at),
            });
        });
        if (
            new Set(records.map((row) => row.id)).size !== records.length ||
            records.some(
                (row, index) => index > 0 && records[index - 1].id <= row.id,
            )
        )
            throw Error("Invalid session ordering");
        return Object.freeze({
            records: Object.freeze(records),
            current: page,
            last: value.last_page,
            total: value.total,
            asOf: instant(value.as_of),
        });
    }
    const n = (tag, en, ar = en) => {
        const node = document.createElement(tag);
        node.dataset.en = en;
        node.dataset.ar = ar;
        node.textContent = document.documentElement.lang === "ar" ? ar : en;
        return node;
    };
    const plain = (tag, value) => {
        const node = document.createElement(tag);
        node.textContent = String(value);
        return node;
    };
    const clear = () => {
        dispose?.();
        dispose = null;
    };
    function open({ host, api, company, current, onExpired }) {
        clear();
        if (!current() || document.hidden || !integer(company?.id, true))
            return;
        host.replaceChildren();
        const root = document.createElement("section");
        root.dataset.companySessionObservations = "";
        host.append(root);
        let closed = false,
            busy = false,
            run = 0,
            actions = 0,
            controller = null,
            page = 1,
            last = 1,
            filter = null,
            hasData = false;
        const live = () =>
            !closed && current() && root.isConnected && !document.hidden;
        const active = (value) => live() && value === run;
        root.append(
            n("h3", "Recorded charging sessions", "جلسات الشحن المسجلة"),
            plain("h4", company.legal_name || ""),
            n(
                "p",
                "Read-only company records from currently owned, linked stations. Customer identities, financial fields and charger actions are excluded. Recorded state is not live availability or verified physical charging.",
                "سجلات للقراءة فقط من المحطات المملوكة والمرتبطة بالشركة حالياً. تُستبعد هوية العملاء والحقول المالية وإجراءات الشواحن. الحالة المسجلة ليست إتاحة مباشرة أو شحناً فعلياً موثقاً.",
            ),
        );
        const label = document.createElement("label"),
            select = document.createElement("select");
        label.append(
            n("span", "Recorded status filter", "مرشح الحالة المسجلة"),
        );
        select.append(
            n("option", "All recorded states", "جميع الحالات المسجلة"),
        );
        select.firstChild.value = "";
        for (const [value, text] of Object.entries(states)) {
            const option = n("option", ...text);
            option.value = value;
            select.append(option);
        }
        label.append(select);
        root.append(label);
        const controls = document.createElement("div");
        controls.className = "session-observation-actions";
        const first = n("button", "Read first page", "قراءة الصفحة الأولى"),
            previous = n("button", "Previous", "السابق"),
            next = n("button", "Next", "التالي"),
            retry = n("button", "Retry this page", "إعادة قراءة هذه الصفحة");
        for (const button of [first, previous, next, retry]) {
            button.type = "button";
            controls.append(button);
        }
        retry.hidden = true;
        const status = document.createElement("p"),
            rows = document.createElement("div");
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");
        root.append(controls, status, rows);
        function bind() {
            const version = ++actions,
                selected = page;
            const allowed = () => version === actions && live() && !busy;
            first.disabled =
                previous.disabled =
                next.disabled =
                retry.disabled =
                select.disabled =
                    busy || !live();
            previous.disabled ||= !hasData || page <= 1;
            next.disabled ||= !hasData || page >= last;
            first.onclick = () => {
                if (allowed()) read(1);
            };
            previous.onclick = () => {
                if (allowed() && hasData && selected === page && page > 1)
                    read(page - 1);
            };
            next.onclick = () => {
                if (allowed() && hasData && selected === page && page < last)
                    read(page + 1);
            };
            retry.onclick = () => {
                if (allowed() && selected === page) read(page);
            };
            select.onchange = () => {
                if (
                    allowed() &&
                    (select.value === "" || Object.hasOwn(states, select.value))
                ) {
                    filter = select.value || null;
                    read(1);
                }
            };
        }
        async function read(target) {
            if (!live() || busy) return;
            const own = ++run;
            page = target;
            busy = true;
            hasData = false;
            retry.hidden = true;
            rows.replaceChildren();
            controller?.abort();
            controller = new AbortController();
            bind();
            status.replaceChildren(
                n(
                    "span",
                    "Reading recorded sessions…",
                    "جارٍ قراءة الجلسات المسجلة…",
                ),
            );
            try {
                const query = new URLSearchParams({
                    page: String(target),
                    ...(filter === null ? {} : { status: filter }),
                });
                const raw = await api(
                    `vendor-ops/companies/${company.id}/charging-sessions?${query}`,
                    "GET",
                    undefined,
                    controller.signal,
                );
                if (!active(own)) return;
                const data = project(raw, company.id, target, filter);
                last = data.last;
                hasData = true;
                for (const record of data.records) {
                    const card = document.createElement("article");
                    card.className = "session-observation-card";
                    card.append(
                        plain("h4", record.station),
                        n("p", `Session #${record.id}`, `الجلسة #${record.id}`),
                        n("p", ...states[record.state]),
                        n(
                            "p",
                            record.energy === null
                                ? "Recorded energy unavailable"
                                : `Recorded energy: ${record.energy} Wh`,
                            record.energy === null
                                ? "الطاقة المسجلة غير متاحة"
                                : `الطاقة المسجلة: ${record.energy} Wh`,
                        ),
                        n("p", ...evidenceText[record.evidence]),
                        n(
                            "p",
                            "Record updated at UTC",
                            "تحديث السجل بالتوقيت العالمي",
                        ),
                    );
                    const time = plain("time", record.updated);
                    time.dateTime = record.updated;
                    time.dir = "ltr";
                    time.className = "session-observation-time";
                    card.append(time);
                    rows.append(card);
                }
                if (!data.records.length)
                    rows.append(
                        n(
                            "p",
                            "No records in this filtered scope.",
                            "لا توجد سجلات ضمن النطاق المرشح.",
                        ),
                    );
                status.replaceChildren(
                    n(
                        "span",
                        `Page ${data.current} / ${data.last} · ${data.total} recorded sessions`,
                        `الصفحة ${data.current} / ${data.last} · ${data.total} جلسات مسجلة`,
                    ),
                );
                const observed = plain("time", data.asOf);
                observed.dir = "ltr";
                observed.dateTime = data.asOf;
                observed.className = "session-observation-time";
                status.append(
                    n(
                        "span",
                        "Observed at UTC",
                        "تمت الملاحظة بالتوقيت العالمي",
                    ),
                    observed,
                );
            } catch (error) {
                if (active(own) && [401, 409, 419].includes(error?.status)) {
                    cleanup();
                    onExpired?.();
                    return;
                }
                if (active(own)) {
                    rows.replaceChildren();
                    retry.hidden = false;
                    status.replaceChildren(
                        n(
                            "span",
                            "Session observations unavailable. Retry this read explicitly; no command was sent.",
                            "ملاحظات الجلسات غير متاحة. أعد هذه القراءة صراحة؛ لم يُرسل أمر.",
                        ),
                    );
                }
            } finally {
                if (active(own)) {
                    busy = false;
                    controller = null;
                    bind();
                }
            }
        }
        const cleanup = () => {
            if (closed) return;
            closed = true;
            run++;
            actions++;
            controller?.abort();
            root.remove();
            document.removeEventListener("visibilitychange", onVisibility);
            window.removeEventListener("pagehide", cleanup);
        };
        const onVisibility = () => {
            if (document.hidden) cleanup();
        };
        document.addEventListener("visibilitychange", onVisibility);
        window.addEventListener("pagehide", cleanup);
        dispose = cleanup;
        bind();
        read(1);
    }
    return { open, clear };
})();
