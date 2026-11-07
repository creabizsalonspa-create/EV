"use strict";

window.EvPrivacyRequests = (() => {
    const mineRoot = document.getElementById("account-privacy");
    const adminRoot = document.getElementById("admin-privacy");
    const states = {
        mine: {root:mineRoot, generation:0, controller:null, request:null},
        admin: {root:adminRoot, generation:0, controller:null, request:null},
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
        state.root.replaceChildren();
    }
    function active(state, generation, controller) {
        return state.request !== null &&
            state.generation === generation && !controller.signal.aborted;
    }

    function setSelfActor(user, api) {
        const state = states.mine;
        clear(state);
        if (!user?.id) return;
        state.request = api;
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
        form.append(field("Request type", kind), field("Describe your request", details), submit);
        const message = el("p", "Submitting a request does not automatically erase account or charging records.");
        message.setAttribute("role", "status");
        const results = el("div");
        state.root.append(form, message, results);
        async function refresh() {
            if (!state.request) return;
            state.controller?.abort();
            const controller = new AbortController();
            state.controller = controller;
            const generation = state.generation;
            try {
                const page = await state.request("account/privacy-requests", "GET", undefined, controller.signal);
                if (!active(state, generation, controller)) return;
                results.replaceChildren();
                for (const row of page.data || []) {
                    results.append(el("p",
                        "Request #" + row.id + " · " + row.kind +
                        " · " + row.status + " · " + row.created_at));
                }
                if (!page.data?.length) results.append(el("p", "No privacy requests submitted yet."));
            } catch {
                if (active(state, generation, controller)) {
                    message.textContent = "Could not load your request history.";
                }
            }
        }
        form.onsubmit = async event => {
            event.preventDefault();
            if (!state.request) return;
            if (details.value.trim().length < 10) {
                message.textContent = "Please explain your request in at least 10 characters.";
                return;
            }
            const generation = state.generation;
            submit.disabled = true;
            try {
                await state.request("account/privacy-requests", "POST", {
                    kind:kind.value, details:details.value.trim(),
                });
                if (generation !== state.generation) return;
                details.value = "";
                message.textContent =
                    "Request recorded for manual review. No data has been automatically deleted.";
                await refresh();
            } catch {
                if (generation === state.generation) {
                    message.textContent = "Request not submitted. An open request of this type may already exist.";
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
            ["pending", "Pending"], ["reviewing", "Reviewing"],
            ["blocked_external", "External review required"],
            ["closed_review", "Review closed"],
        ]);
        const kind = select([
            ["", "All types"], ["access", "Data access"],
            ["correction", "Correction"], ["erasure", "Deletion review"],
        ]);
        const search = button("Apply filters / تطبيق", () => {});
        search.type = "submit";
        filters.append(field("Status", status), field("Kind", kind), search);
        const message = el("p");
        message.setAttribute("role", "status");
        const results = el("div");
        state.root.append(filters, message, results);

        const allowed = {
            pending:["reviewing", "blocked_external"],
            reviewing:["blocked_external", "closed_review"],
            blocked_external:["reviewing", "closed_review"],
            closed_review:[],
        };
        async function load(page = 1) {
            if (!state.request) return;
            state.controller?.abort();
            const controller = new AbortController();
            state.controller = controller;
            const generation = state.generation;
            const params = new URLSearchParams({status:status.value, page:String(page)});
            if (kind.value) params.set("kind", kind.value);
            message.textContent = "Loading private review queue…";
            results.replaceChildren();
            try {
                const response = await state.request("admin/privacy-requests?" + params.toString(),
                    "GET", undefined, controller.signal);
                if (!active(state, generation, controller)) return;
                const items = Array.isArray(response.data) ? response.data : [];
                for (const record of items) {
                    const caseId = Number(record.id);
                    if (!Number.isSafeInteger(caseId) || caseId <= 0) continue;
                    const card = el("article");
                    card.append(
                        el("h4", "Privacy request #" + caseId + " · " + record.kind),
                        el("p", "Owner: " + (record.owner?.name || "Unknown") +
                            " · " + (record.owner?.email || "") +
                            " · Current state: " + record.status),
                        el("p", record.details),
                        el("p", "Manual review required; no automatic deletion or export occurs.")
                    );
                    const choices = allowed[record.status] || [];
                    if (choices.length) {
                        const form = el("form");
                        form.setAttribute("aria-label", "Privacy review decision " + caseId);
                        const decision = select(choices.map(v => [v, v.replaceAll("_", " ")]));
                        const note = el("textarea");
                        note.minLength = 10;
                        note.maxLength = 2000;
                        note.required = true;
                        note.setAttribute("aria-label", "Private review note");
                        const submit = button("Record review decision / تسجيل القرار", () => {});
                        submit.type = "submit";
                        form.append(field("Next review stage", decision),
                            field("Private decision note", note), submit);
                        form.onsubmit = async event => {
                            event.preventDefault();
                            if (!active(state, generation, controller)) return;
                            if (note.value.trim().length < 10) {
                                message.textContent = "A review note of at least 10 characters is required.";
                                return;
                            }
                            submit.disabled = true;
                            try {
                                await state.request("admin/privacy-requests/" + caseId + "/review",
                                    "PATCH", {
                                        expected_status:record.status,
                                        status:decision.value,
                                        review_note:note.value.trim(),
                                    }, controller.signal);
                                if (active(state, generation, controller)) {
                                    message.textContent = "Decision recorded; refreshing review queue.";
                                    await load(page);
                                }
                            } catch {
                                if (active(state, generation, controller)) {
                                    message.textContent = "Decision not saved; refresh the current request state.";
                                }
                            } finally {
                                submit.disabled = false;
                            }
                        };
                        card.append(form);
                    }
                    results.append(card);
                }
                if (!items.length) results.append(el("p", "No requests match these filters."));
                const controls = el("div");
                controls.className = "pagination";
                const prev = button("Previous / السابق", () => load(page - 1));
                const next = button("Next / التالي", () => load(page + 1));
                prev.disabled = page <= 1;
                next.disabled = page >= response.last_page;
                controls.append(prev, el("span", "Page " + page + " / " + response.last_page), next);
                results.append(controls);
                message.textContent = String(response.total) + " requests in this review status.";
            } catch {
                if (active(state, generation, controller)) {
                    message.textContent = "Privacy queue unavailable; no request state was changed.";
                }
            }
        }
        filters.onsubmit = event => { event.preventDefault(); load(1); };
        load(1);
    }

    return {setSelfActor, setAdminActor};
})();
