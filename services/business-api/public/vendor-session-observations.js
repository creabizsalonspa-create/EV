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
    function eventTime(value) {
        const time = window.EvStationTime.bindServerEventTime(
            document.createElement("time"),
            value,
        );
        time.dateTime = value;
        time.className = "session-observation-time";
        return time;
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
        const grant = value.staff_stop_grant;
        if (grant !== undefined && (!grant || !integer(grant.id, true) || !integer(grant.version, true)))
            throw Error("Invalid stop grant");
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
                (row.can_request_stop !== undefined && typeof row.can_request_stop !== "boolean") ||
                (row.can_request_stop === true && (!grant || row.evidence !== "SIMULATED_CSMS" ||
                    !["charging", "stopping"].includes(row.recorded_status))) ||
                row.physical_charging_verified !== false ||
                row.live_availability_verified !== false
            )
                throw Error("Invalid session observation");
            return Object.freeze({
                id: row.id,
                canStop: row.can_request_stop === true,
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
            grant: grant === undefined ? null : Object.freeze({ id: grant.id, version: grant.version }),
            current: page,
            last: value.last_page,
            total: value.total,
            asOf: instant(value.as_of),
        });
    }
    function projectMeters(v, company, session, page, through) {
        if (
            v?.scope !== "company_csms_meter_history" ||
            v.company_id !== company ||
            !integer(v.company_id, true) ||
            v.session_id !== session ||
            !integer(v.session_id, true) ||
            !integer(v.through_id) ||
            (through !== null && v.through_id !== through) ||
            typeof v.source_linked !== "boolean" ||
            v.coverage !== "RECORDED_APPLIED_CSMS_EVENTS" ||
            [
                "history_complete",
                "billing_authority",
                "physical_energy_verified",
                "commands_sent",
            ].some((k) => v[k] !== false) ||
            v.network_requests !== 0
        )
            throw Error("Invalid recorded meter scope");
        const p = v.observations;
        if (
            !p ||
            !Array.isArray(p.data) ||
            p.data.length > 25 ||
            p.per_page !== 25 ||
            p.current_page !== page ||
            !integer(page, true) ||
            page > 100000 ||
            !integer(p.last_page, true) ||
            !integer(p.total) ||
            p.data.length > p.total ||
            p.last_page !==
                (p.total === 0 ? 1 : Math.floor((p.total - 1) / 25) + 1) ||
            (page > p.last_page && p.data.length)
        )
            throw Error("Invalid recorded meter page");
        const records = p.data.map((r) => {
            if (
                !r ||
                !integer(r.id, true) ||
                r.id > v.through_id ||
                !integer(r.sequence) ||
                !integer(r.cumulative_meter_wh) ||
                !["started", "meter", "ended"].includes(r.kind)
            )
                throw Error("Invalid recorded counter");
            return Object.freeze({
                id: r.id,
                sequence: r.sequence,
                counter: r.cumulative_meter_wh,
                kind: r.kind,
                occurred: instant(r.source_occurred_at),
                recorded: instant(r.recorded_at),
            });
        });
        if (
            new Set(records.map((r) => r.id)).size !== records.length ||
            records.some((r, i) => i > 0 && records[i - 1].id <= r.id) ||
            (v.through_id === 0 && p.total !== 0) ||
            (v.source_linked
                ? !["1.6", "2.0.1"].includes(v.protocol) ||
                  !["SIMULATED_CSMS", "RECORDED_SOURCE_UNVERIFIED"].includes(
                      v.evidence,
                  )
                : v.protocol !== null ||
                  v.evidence !== "NO_RECORDED_CSMS_LINK" ||
                  p.total !== 0 ||
                  v.through_id !== 0)
        )
            throw Error("Incoherent recorded history");
        return Object.freeze({
            records: Object.freeze(records),
            current: page,
            last: p.last_page,
            total: p.total,
            asOf: instant(v.as_of),
            through: v.through_id,
            linked: v.source_linked,
            protocol: v.protocol,
            evidence: v.evidence,
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
    function open({ host, api, company, current, onExpired, session = null }) {
        clear();
        if (
            !current() ||
            document.hidden ||
            !integer(company?.id, true) ||
            (session !== null && !integer(session, true))
        )
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
            hasData = false,
            through = null;
        const live = () =>
            !closed && current() && root.isConnected && !document.hidden;
        const active = (value) => live() && value === run;
        root.append(
            n(
                "h3",
                session === null
                    ? "Recorded charging sessions"
                    : "Recorded meter history",
                session === null
                    ? "جلسات الشحن المسجلة"
                    : "سجل العدادات المسجل",
            ),
            plain("h4", company.legal_name || ""),
            n(
                "p",
                session === null
                    ? "Read-only company records from currently owned, linked stations. Customer identities and financial fields are excluded. Staff stop requests require a current simulator grant. Recorded state is not live availability or verified physical charging."
                    : "Source counter readings are not consumed or billed energy. This snapshot covers retained applied events; it does not verify physical charging or a complete history.",
                session === null
                    ? "سجلات للقراءة فقط من المحطات المملوكة والمرتبطة بالشركة حالياً. تُستبعد هوية العملاء والحقول المالية. يتطلب طلب إيقاف المحاكاة صلاحية موظف سارية. الحالة المسجلة ليست إتاحة مباشرة أو شحناً فعلياً موثقاً."
                    : "قراءات عداد المصدر ليست طاقة مستهلكة أو مفوترة. تعرض اللقطة الأحداث المطبقة المحتفظ بها؛ لا توثق الشحن الفعلي أو اكتمال السجل.",
            ),
        );
        if (session !== null) {
            root.append(n("p", `Session #${session}`, `الجلسة #${session}`));
            const back = n(
                "button",
                "Back to recorded sessions",
                "العودة إلى الجلسات المسجلة",
            );
            back.type = "button";
            back.onclick = () => {
                if (live() && !busy)
                    open({ host, api, company, current, onExpired });
            };
            root.append(back);
        }
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
        if (session === null) root.append(label);
        const controls = document.createElement("div");
        controls.className = "session-observation-actions";
        const first = n(
                "button",
                session === null ? "Read first page" : "Refresh snapshot",
                session === null ? "قراءة الصفحة الأولى" : "تحديث اللقطة",
            ),
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
                if (allowed()) read(1, true);
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
        async function requestStop(record, grant, reviewed) {
            if (!active(reviewed) || busy) return;
            const ar = document.documentElement.lang === "ar";
            if (!window.confirm(ar
                ? `إرسال طلب إيقاف المحاكاة للجلسة رقم ${record.id}؟ يتطلب الإيقاف تأكيداً مسجلاً من المصدر.`
                : `Request a simulator stop for session #${record.id}? The stop requires a recorded source confirmation.`
            ) || !active(reviewed) || busy) return;
            const own = ++run;
            busy = true;
            hasData = false;
            rows.replaceChildren();
            bind();
            status.replaceChildren(n("span", "Requesting simulator stop…", "جارٍ طلب إيقاف المحاكاة…"));
            try {
                const reply = await api(
                    `vendor-ops/companies/${company.id}/charging-sessions/${record.id}/stop`,
                    "POST",
                    { grant_id: grant.id, grant_version: grant.version, consent: true },
                );
                if (!active(own)) return;
                if (reply?.scope !== "company_simulator_session_stop" ||
                    reply.company_id !== company.id || reply.session_id !== record.id ||
                    !integer(reply.command_id, true) || reply.evidence !== "SIMULATED_CSMS" ||
                    reply.physical_charging_verified !== false ||
                    !["pending", "dispatching", "submitted", "source_observed", "rejected", "outcome_unknown"].includes(reply.command_status) ||
                    !["charging", "stopping", "stopped"].includes(reply.session_status))
                    throw Error("Invalid stop receipt");
                status.replaceChildren(n(
                    "span",
                    "Simulator stop request recorded. Read the current session state before continuing.",
                    "تم تسجيل طلب إيقاف المحاكاة. اقرأ حالة الجلسة الحالية قبل المتابعة.",
                ));
            } catch (error) {
                if (!active(own)) return;
                if ([401, 419].includes(error?.status)) {
                    cleanup();
                    onExpired?.();
                    return;
                }
                status.replaceChildren(n(
                    "span",
                    "Simulator stop outcome was not confirmed. Read the current session state before another request.",
                    "تعذر تأكيد نتيجة إيقاف المحاكاة. اقرأ حالة الجلسة الحالية قبل إرسال طلب آخر.",
                ));
            } finally {
                if (active(own)) {
                    busy = false;
                    bind();
                }
            }
        }
        async function read(target, refresh = false) {
            if (!live() || busy) return;
            const own = ++run;
            page = target;
            if (refresh) through = null;
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
                    ...(session === null
                        ? filter === null
                            ? {}
                            : { status: filter }
                        : through === null
                          ? {}
                          : { through: String(through) }),
                });
                const raw = await api(
                    `vendor-ops/companies/${company.id}/charging-sessions${session === null ? "" : "/" + session + "/meter-history"}?${query}`,
                    "GET",
                    undefined,
                    controller.signal,
                );
                if (!active(own)) return;
                const data =
                    session === null
                        ? project(raw, company.id, target, filter)
                        : projectMeters(
                              raw,
                              company.id,
                              session,
                              target,
                              through,
                          );
                if (session !== null) through = data.through;
                last = data.last;
                hasData = true;
                if (session !== null) {
                    rows.append(
                        n(
                            "p",
                            ...(data.linked
                                ? evidenceText[data.evidence]
                                : [
                                      "No recorded CSMS link. No meter history is invented.",
                                      "لا يوجد ربط مسجل بنظام الشحن. لا يتم إنشاء سجل عداد افتراضي.",
                                  ]),
                        ),
                    );
                    if (data.protocol !== null)
                        rows.append(plain("p", "OCPP " + data.protocol));
                    for (const record of data.records) {
                        const card = document.createElement("article");
                        card.className = "session-observation-card";
                        card.append(
                            n(
                                "h4",
                                ...{
                                    started: ["Recorded start", "بداية مسجلة"],
                                    meter: ["Recorded meter", "عداد مسجل"],
                                    ended: ["Recorded end", "نهاية مسجلة"],
                                }[record.kind],
                            ),

                            n(
                                "p",
                                `Sequence: ${record.sequence}`,
                                `التسلسل: ${record.sequence}`,
                            ),
                        );
                        const counter = document.createElement("p"),
                            amount = plain("bdi", `${record.counter} Wh`);
                        amount.dir = "ltr";
                        counter.append(
                            n(
                                "span",
                                "Cumulative counter: ",
                                "العداد التراكمي: ",
                            ),
                            amount,
                        );
                        card.append(counter);
                        for (const [text, value] of [
                            [
                                [
                                    "Source timestamp UTC",
                                    "توقيت المصدر بالتوقيت العالمي",
                                ],
                                record.occurred,
                            ],
                            [
                                [
                                    "Recorded at UTC",
                                    "وقت التسجيل بالتوقيت العالمي",
                                ],
                                record.recorded,
                            ],
                        ]) {
                            const time = plain("time", value);
                            time.dateTime = value;
                            time.dir = "ltr";
                            time.className = "session-observation-time";
                            card.append(n("p", ...text), time);
                        }
                        rows.append(card);
                    }
                } else {
                    for (const record of data.records) {
                        const card = document.createElement("article");
                        card.className = "session-observation-card";
                        card.append(
                            plain("h4", record.station),
                            n(
                                "p",
                                `Session #${record.id}`,
                                `الجلسة #${record.id}`,
                            ),
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
                        card.append(eventTime(record.updated));
                        if (record.stopped !== null) {
                            card.append(
                                n(
                                    "p",
                                    "Recorded stop at UTC",
                                    "التوقف المسجل بالتوقيت العالمي",
                                ),
                                eventTime(record.stopped),
                            );
                        }
                        const meters = n(
                            "button",
                            "Recorded meter history",
                            "سجل العدادات المسجل",
                        );
                        meters.type = "button";
                        meters.onclick = () => {
                            if (active(own) && !busy)
                                open({
                                    host,
                                    api,
                                    company,
                                    current,
                                    onExpired,
                                    session: record.id,
                                });
                        };
                        card.append(meters);
                        if (record.canStop) {
                            const stop = n("button", "Request a simulator stop", "طلب إيقاف جلسة محاكاة");
                            stop.type = "button";
                            stop.onclick = () => {
                                if (stop.isConnected) requestStop(record, data.grant, own);
                            };
                            card.append(stop);
                        }
                        rows.append(card);
                    }
                }
                if (!data.records.length)
                    rows.append(
                        n(
                            "p",
                            session === null
                                ? "No records in this filtered scope."
                                : "No applied source events in this recorded snapshot.",
                            session === null
                                ? "لا توجد سجلات ضمن النطاق المرشح."
                                : "لا توجد أحداث مصدر مطبقة في هذه اللقطة المسجلة.",
                        ),
                    );
                const pageInfo = document.createElement("span"),
                    fraction = plain("bdi", `${data.current} / ${data.last}`);
                fraction.dir = "ltr";
                pageInfo.append(
                    n("span", "Page ", "الصفحة "),
                    fraction,
                    n(
                        "span",
                        ` · ${data.total} ${session === null ? "recorded sessions" : "recorded source events"}`,
                        ` · ${data.total} ${session === null ? "جلسات مسجلة" : "أحداث مصدر مسجلة"}`,
                    ),
                );
                status.replaceChildren(pageInfo);
                status.append(
                    n(
                        "span",
                        "Observed at UTC",
                        "تمت الملاحظة بالتوقيت العالمي",
                    ),
                    eventTime(data.asOf),
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
