"use strict";

window.EvPrivacyRequests = (() => {
    const mineRoot = document.getElementById("account-privacy");
    const adminRoot = document.getElementById("admin-privacy");
    const states = {
        mine: {
            root: mineRoot,
            generation: 0,
            controller: null,
            request: null,
        },
        admin: {
            root: adminRoot,
            generation: 0,
            controller: null,
            request: null,
        },
    };

    function el(tag, text) {
        const e = document.createElement(tag);
        if (text !== undefined) e.textContent = String(text);
        return e;
    }
    function button(text, callback) {
        const b = el("button", text);
        b.type = "button";
        b.onclick = callback;
        return b;
    }
    function field(text, control) {
        const label = el("label");
        label.append(el("span", text), control);
        return label;
    }
    function select(options) {
        const s = el("select");
        for (const [value, label] of options) {
            const opt = el("option", label);
            opt.value = value;
            s.append(opt);
        }
        s.value = options[0][0];
        return s;
    }
    function clear(state) {
        state.generation++;
        state.controller?.abort();
        state.controller = null;
        state.request = null;
        state.pending = null;
        state.root.replaceChildren();
    }
    function active(state, generation, controller) {
        return (
            state.request !== null &&
            state.generation === generation &&
            !controller.signal.aborted
        );
    }

    function setSelfActor(user, api) {
        const state = states.mine;
        clear(state);
        if (!user?.id) return;
        state.request = api;
        const viewGeneration = state.generation;
        const form = el("form");
        form.setAttribute("aria-label", "Personal privacy rights request");
        const kind = select([
            ["access", "Data access / الوصول إلى البيانات"],
            ["correction", "Correction / تصحيح البيانات"],
            ["erasure", "Deletion review / طلب مراجعة الحذف"],
        ]);
        kind.setAttribute("aria-label", "Request kind");
        const details = el("textarea");
        details.required = true;
        details.minLength = 10;
        details.maxLength = 2000;
        details.setAttribute("aria-label", "Private request details");
        const submit = button("Submit privacy request / إرسال الطلب", () => {});
        submit.type = "submit";
        form.append(
            field("Request type", kind),
            field("Describe your request", details),
            submit,
        );
        const message = el(
            "p",
            "Submitting a request does not automatically erase account or charging records.",
        );
        message.setAttribute("role", "status");
        const results = el("div");
        const reset = button("Start different request / طلب مختلف", () => {
            if (viewGeneration !== state.generation || !state.request) return;
            state.pending = null;
            details.value = "";
            message.textContent =
                "A different request requires a new explicit submission.";
        });
        const reload = button("Refresh requests / تحديث الطلبات", () =>
            refresh(),
        );
        state.root.append(form, reset, reload, message, results);
        async function refresh(target = 1) {
            if (!state.request || viewGeneration !== state.generation) return;
            state.controller?.abort();
            const controller = new AbortController();
            state.controller = controller;
            const generation = state.generation;
            try {
                const page = await state.request(
                    "account/privacy-requests" +
                        (target > 1 ? "?page=" + target : ""),
                    "GET",
                    undefined,
                    controller.signal,
                );
                if (!active(state, generation, controller)) return;
                results.replaceChildren();
                for (const row of page.data || []) {
                    const card = el("article");
                    card.append(
                        el(
                            "p",
                            "Request #" +
                                row.id +
                                " · " +
                                row.kind +
                                " · " +
                                row.status +
                                " · " +
                                row.created_at,
                        ),
                    );
                    if (typeof row.details === "string")
                        card.append(el("p", row.details));
                    if (
                        ["pending", "reviewing", "blocked_external"].includes(
                            row.status,
                        ) &&
                        Number.isSafeInteger(row.id) &&
                        row.id > 0
                    ) {
                        const withdraw = button(
                            "Withdraw request / سحب الطلب",
                            async () => {
                                if (!active(state, generation, controller))
                                    return;
                                withdraw.disabled = true;
                                try {
                                    await state.request(
                                        "account/privacy-requests/" +
                                            row.id +
                                            "/withdraw",
                                        "POST",
                                        {},
                                    );
                                    if (!active(state, generation, controller))
                                        return;
                                    await refresh();
                                } catch {
                                    if (active(state, generation, controller))
                                        message.textContent =
                                            "Withdrawal could not be confirmed. Refresh or retry safely.";
                                } finally {
                                    withdraw.disabled = false;
                                }
                            },
                        );
                        card.append(withdraw);
                    }
                    results.append(card);
                }
                if (!page.data?.length)
                    results.append(
                        el("p", "No privacy requests submitted yet."),
                    );
                const current = Number(page.current_page || 1),
                    last = Number(page.last_page || 1);
                if (
                    Number.isSafeInteger(current) &&
                    Number.isSafeInteger(last) &&
                    last > 1
                ) {
                    const previous = button(
                        "Previous requests / الطلبات السابقة",
                        () => refresh(current - 1),
                    );
                    const next = button("Next requests / الطلبات التالية", () =>
                        refresh(current + 1),
                    );
                    previous.disabled = current <= 1;
                    next.disabled = current >= last;
                    results.append(previous, next);
                }
                return page.data || [];
            } catch {
                if (active(state, generation, controller)) {
                    message.textContent =
                        "Could not load your request history.";
                }
            }
        }
        form.onsubmit = async (event) => {
            event.preventDefault();
            if (!state.request || viewGeneration !== state.generation) return;
            if (details.value.trim().length < 10) {
                message.textContent =
                    "Please explain your request in at least 10 characters.";
                return;
            }
            const generation = state.generation;
            const body = { kind: kind.value, details: details.value.trim() };
            if (
                state.pending &&
                (state.pending.kind !== body.kind ||
                    state.pending.details !== body.details)
            ) {
                message.textContent =
                    "Retry the same request or explicitly start a different request.";
                return;
            }
            state.pending ||= Object.freeze({
                ...body,
                idempotency_key: crypto.randomUUID(),
            });
            submit.disabled = true;
            try {
                const receipt = await state.request(
                    "account/privacy-requests",
                    "POST",
                    state.pending,
                );
                if (generation !== state.generation) return;
                const rows = await refresh();
                if (generation !== state.generation) return;
                if (!rows?.some((row) => row.id === receipt.id))
                    throw Error("Request readback unavailable");
                state.pending = null;
                details.value = "";
                message.textContent =
                    "Request recorded for manual review. No data has been automatically deleted.";
            } catch {
                if (generation === state.generation) {
                    message.textContent =
                        "Request could not be confirmed. Check history or retry the unchanged request safely.";
                }
            } finally {
                submit.disabled = false;
            }
        };
        refresh();
    }

    function setAdminActor(user, api) {
        const state = states.admin;
        clear(state);
        if (user?.role !== "admin") return;
        state.request = api;
        const filters = el("form");
        filters.setAttribute("aria-label", "Admin privacy request filters");
        const status = select([
            ["pending", "Pending"],
            ["reviewing", "Reviewing"],
            ["blocked_external", "External review required"],
            ["closed_review", "Review closed"],
            ["withdrawn", "Withdrawn"],
        ]);
        const kind = select([
            ["", "All types"],
            ["access", "Data access"],
            ["correction", "Correction"],
            ["erasure", "Deletion review"],
        ]);
        const search = button("Apply filters / تطبيق", () => {});
        search.type = "submit";
        filters.append(field("Status", status), field("Kind", kind), search);
        const message = el("p");
        message.setAttribute("role", "status");
        const results = el("div");
        state.root.append(filters, message, results);

        const allowed = {
            pending: ["reviewing", "blocked_external"],
            reviewing: ["blocked_external", "closed_review"],
            blocked_external: ["reviewing", "closed_review"],
            closed_review: [],
            withdrawn: [],
        };
        async function load(page = 1) {
            if (!state.request) return;
            state.controller?.abort();
            const controller = new AbortController();
            state.controller = controller;
            const generation = state.generation;
            const params = new URLSearchParams({
                status: status.value,
                page: String(page),
            });
            if (kind.value) params.set("kind", kind.value);
            message.textContent = "Loading private review queue…";
            results.replaceChildren();
            try {
                const response = await state.request(
                    "admin/privacy-requests?" + params.toString(),
                    "GET",
                    undefined,
                    controller.signal,
                );
                if (!active(state, generation, controller)) return;
                const items = Array.isArray(response.data) ? response.data : [];
                for (const record of items) {
                    const caseId = Number(record.id);
                    if (!Number.isSafeInteger(caseId) || caseId <= 0) continue;
                    const card = el("article");
                    card.append(
                        el(
                            "h4",
                            "Privacy request #" + caseId + " · " + record.kind,
                        ),
                        el(
                            "p",
                            "Owner: " +
                                (record.owner?.name || "Unknown") +
                                " · " +
                                (record.owner?.email || "") +
                                " · Current state: " +
                                record.status,
                        ),
                        el("p", record.details),
                        el(
                            "p",
                            "Manual review required; no automatic deletion or export occurs.",
                        ),
                    );
                    const historyHost = el("div");
                    const history = button(
                        "Private review history / سجل المراجعة",
                        async () => {
                            if (!active(state, generation, controller)) return;
                            history.disabled = true;
                            try {
                                const response = await state.request(
                                    "admin/privacy-requests/" +
                                        caseId +
                                        "/reviews",
                                    "GET",
                                    undefined,
                                    controller.signal,
                                );
                                if (!active(state, generation, controller))
                                    return;
                                const entries = Array.isArray(response.data)
                                    ? response.data
                                    : [];
                                const list = el("ul");
                                for (const item of entries) {
                                    list.append(
                                        el(
                                            "li",
                                            item.created_at +
                                                " · " +
                                                item.from_status +
                                                " → " +
                                                item.to_status +
                                                " · " +
                                                item.note,
                                        ),
                                    );
                                }
                                if (!entries.length) {
                                    list.append(
                                        el(
                                            "li",
                                            "No recorded review decisions.",
                                        ),
                                    );
                                }
                                historyHost.replaceChildren(
                                    el(
                                        "p",
                                        "Private review reasons are visible to Admin only.",
                                    ),
                                    list,
                                );
                            } catch {
                                if (active(state, generation, controller)) {
                                    historyHost.replaceChildren(
                                        el("p", "Review history unavailable."),
                                    );
                                }
                            } finally {
                                history.disabled = false;
                            }
                        },
                    );
                    card.append(history, historyHost);
                    const choices =
                        record.owner?.id === user.id
                            ? []
                            : allowed[record.status] || [];
                    if (choices.length) {
                        const form = el("form");
                        form.setAttribute(
                            "aria-label",
                            "Privacy review decision " + caseId,
                        );
                        const decision = select(
                            choices.map((v) => [v, v.replaceAll("_", " ")]),
                        );
                        const note = el("textarea");
                        note.minLength = 10;
                        note.maxLength = 2000;
                        note.required = true;
                        note.setAttribute("aria-label", "Private review note");
                        const submit = button(
                            "Record review decision / تسجيل القرار",
                            () => {},
                        );
                        submit.type = "submit";
                        form.append(
                            field("Next review stage", decision),
                            field("Private decision note", note),
                            submit,
                        );
                        form.onsubmit = async (event) => {
                            event.preventDefault();
                            if (!active(state, generation, controller)) return;
                            if (note.value.trim().length < 10) {
                                message.textContent =
                                    "A review note of at least 10 characters is required.";
                                return;
                            }
                            submit.disabled = true;
                            try {
                                await state.request(
                                    "admin/privacy-requests/" +
                                        caseId +
                                        "/review",
                                    "PATCH",
                                    {
                                        expected_status: record.status,
                                        status: decision.value,
                                        review_note: note.value.trim(),
                                    },
                                    controller.signal,
                                );
                                if (active(state, generation, controller)) {
                                    message.textContent =
                                        "Decision recorded; refreshing review queue.";
                                    await load(page);
                                }
                            } catch {
                                if (active(state, generation, controller)) {
                                    message.textContent =
                                        "Decision not saved; refresh the current request state.";
                                }
                            } finally {
                                submit.disabled = false;
                            }
                        };
                        card.append(form);
                    }
                    results.append(card);
                }
                if (!items.length)
                    results.append(el("p", "No requests match these filters."));
                const controls = el("div");
                controls.className = "pagination";
                const prev = button("Previous / السابق", () => load(page - 1));
                const next = button("Next / التالي", () => load(page + 1));
                prev.disabled = page <= 1;
                next.disabled = page >= response.last_page;
                controls.append(
                    prev,
                    el("span", "Page " + page + " / " + response.last_page),
                    next,
                );
                results.append(controls);
                message.textContent =
                    String(response.total) + " requests in this review status.";
            } catch {
                if (active(state, generation, controller)) {
                    message.textContent =
                        "Privacy queue unavailable; no request state was changed.";
                }
            }
        }
        filters.onsubmit = (event) => {
            event.preventDefault();
            load(1);
        };
        load(1);
    }

    return { setSelfActor, setAdminActor };
})();
