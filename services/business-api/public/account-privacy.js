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
            previews: new Set(),
            secrets: new Set(),
            visibilityVersion: 0,
            onHide: null,
        },
        admin: {
            root: adminRoot,
            generation: 0,
            controller: null,
            request: null,
            previews: new Set(),
            secrets: new Set(),
            visibilityVersion: 0,
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
    function wipeSensitive(state, detach = false) {
        for (const preview of state.previews) preview.textContent = "";
        for (const input of state.secrets) input.value = "";
        // Backgrounding does not remove the forms. Keep references so a
        // second background transition still wipes newly entered secrets.
        // Discard references only when the owning DOM nodes are replaced.
        if (detach) {
            state.previews.clear();
            state.secrets.clear();
        }
    }
    function clear(state) {
        wipeSensitive(state, true);
        state.generation++;
        state.controller?.abort();
        state.controller = null;
        state.onHide = null;
        state.request = null;
        state.pending = null;
        state.submitting = false;
        state.root.replaceChildren();
    }
    function active(state, generation, controller) {
        return (
            state.request !== null &&
            state.generation === generation &&
            !controller.signal.aborted &&
            !document.hidden
        );
    }

    function setSelfActor(user, api, onSecurityPrepared) {
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
            if (viewGeneration !== state.generation || !state.request || document.hidden) return;
            if (state.submitting) {
                message.textContent = "Wait for the current request to settle before starting a different one. / انتظر اكتمال الطلب الحالي قبل بدء طلب مختلف.";
                return;
            }
            state.pending = null;
            details.value = "";
            message.textContent =
                "A different request requires a new explicit submission.";
        });
        const reload = button("Refresh requests / تحديث الطلبات", () =>
            refresh(),
        );
        state.root.append(form, reset, reload, message, results);
        state.onHide = () => {
            if (viewGeneration !== state.generation || !state.request) return;
            // Clear the *entire* private result tree, not only export previews.
            // An aborted GET can otherwise leave old history, withdrawal actions
            // and a permanent aria-busy indicator in the backgrounded document.
            results.replaceChildren();
            results.removeAttribute("aria-busy");
            details.value = "";
            message.textContent =
                "Private request history closed. Return and refresh to verify the current state. / أُغلق سجل الطلبات الخاص. ارجع وحدّث للتحقق من الحالة.";
        };
        async function refresh(target = 1) {
            if (!state.request || viewGeneration !== state.generation || document.hidden) return;
            state.controller?.abort();
            const controller = new AbortController();
            state.controller = controller;
            const generation = state.generation;
            // Sensitive access-copy controls and previews are no longer
            // authoritative once a refresh begins; never retain them while
            // waiting for a failed or unresponsive read.
            wipeSensitive(state, true);
            results.replaceChildren();
            results.setAttribute("aria-busy", "true");
            message.textContent = "Refreshing your private request history… / جارٍ تحديث سجل طلباتك الخاص…";
            try {
                const page = await state.request(
                    "account/privacy-requests" +
                        (target > 1 ? "?page=" + target : ""),
                    "GET",
                    undefined,
                    controller.signal,
                );
                if (!active(state, generation, controller)) return;
                if (!Array.isArray(page?.data) ||
                    !Number.isSafeInteger(page.current_page) ||
                    !Number.isSafeInteger(page.last_page) ||
                    !Number.isSafeInteger(page.total) ||
                    page.current_page !== target ||
                    page.current_page < 1 || page.last_page < 1 ||
                    page.last_page > 100000 ||
                    page.current_page > page.last_page ||
                    page.total < (target - 1) * 25 + page.data.length ||
                    page.data.length > 25 ||
                    page.last_page !== Math.max(1, Math.ceil(page.total / 25)))
                    throw Error("Unreadable private request page");
                const seen = new Set();
                for (const row of page.data) {
                    if (!row || typeof row !== "object" ||
                        Array.isArray(row) ||
                        !Number.isSafeInteger(row.id) || row.id < 1 ||
                        seen.has(row.id) ||
                        !["access", "correction", "erasure"].includes(row.kind) ||
                        !["pending", "reviewing", "blocked_external",
                          "closed_review", "withdrawn"].includes(row.status) ||
                        typeof row.created_at !== "string" ||
                        row.created_at.length > 80 ||
                        (row.details != null &&
                          (typeof row.details !== "string" ||
                           row.details.length > 2000)) ||
                        (row.copy != null &&
                          (typeof row.copy !== "object" ||
                           Array.isArray(row.copy) ||
                           !["available", "expired", "revoked"]
                               .includes(row.copy.status) ||
                           (row.copy.status === "available" &&
                             (typeof row.copy.expires_at !== "string" ||
                              row.copy.expires_at.length > 80))))) {
                        throw Error("Unverified private request record");
                    }
                    seen.add(row.id);
                }
                results.replaceChildren();
                message.textContent = "";
                for (const row of page.data) {
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
                    if (row.copy?.status === "available") {
                        const password = el("input");
                        password.type = "password";
                        password.autocomplete = "current-password";
                        const factor = el("input");
                        factor.autocomplete = "one-time-code";
                        factor.maxLength = 64;
                        const preview = el("pre");
                        state.previews.add(preview);
                        state.secrets.add(password);
                        state.secrets.add(factor);
                        preview.style.whiteSpace = "pre-wrap";
                        preview.style.overflowWrap = "anywhere";
                        card.append(
                            el(
                                "p",
                                "Private account copy expires: " +
                                    row.copy.expires_at +
                                    " / تنتهي صلاحية النسخة الخاصة",
                            ),
                            field(
                                "Current password / كلمة المرور الحالية",
                                password,
                            ),
                            field(
                                "Authenticator/recovery code if enabled / رمز المصادقة إن فُعّل",
                                factor,
                            ),
                        );
                        const view = button(
                            "View private account copy / عرض نسخة الحساب الخاصة",
                            async () => {
                                if (!active(state, generation, controller) ||
                                    document.hidden) return;
                                const body = {
                                    password: password.value,
                                    second_factor_code: factor.value,
                                };
                                password.value = "";
                                factor.value = "";
                                preview.textContent = "";
                                // A hidden-to-visible round trip invalidates an in-flight
                                // private-copy read even when the response arrives visible.
                                const visibilityVersion = state.visibilityVersion;
                                view.disabled = true;
                                try {
                                    const copy = await state.request(
                                        `account/privacy-requests/${row.id}/access-copy`,
                                        "POST",
                                        body,
                                        controller.signal,
                                    );
                                    if (!active(state, generation, controller) ||
                                        document.hidden ||
                                        visibilityVersion !== state.visibilityVersion) return;
                                    if (
                                        copy.schema_version !== 1 ||
                                        copy.owner_id !== user.id ||
                                        copy.request_id !== row.id ||
                                        copy.scope !==
                                            "self_service_account_copy"
                                    )
                                        throw Error("Unverified copy");
                                    preview.textContent = JSON.stringify(
                                        copy,
                                        null,
                                        2,
                                    );
                                } catch {
                                    if (active(state, generation, controller))
                                        message.textContent =
                                            "Copy unavailable. Check password/new authenticator code or refresh expiry. / النسخة غير متاحة؛ تحقق من المصادقة والصلاحية.";
                                } finally {
                                    view.disabled = false;
                                }
                            },
                        );
                        card.append(
                            view,
                            button(
                                "Discard private copy / إتلاف النسخة الخاصة",
                                async () => {
                                    if (!active(state, generation, controller))
                                        return;
                                    preview.textContent = "";
                                    password.value = "";
                                    factor.value = "";
                                    try {
                                        await state.request(
                                            `account/privacy-requests/${row.id}/access-copy/revoke`,
                                            "POST",
                                            {},
                                        );
                                        if (
                                            active(
                                                state,
                                                generation,
                                                controller,
                                            )
                                        )
                                            await refresh();
                                    } catch {
                                        if (
                                            active(
                                                state,
                                                generation,
                                                controller,
                                            )
                                        )
                                            message.textContent =
                                                "Discard could not be confirmed. Refresh before retrying. / لم يتأكد الإتلاف؛ حدّث قبل المحاولة.";
                                    }
                                },
                            ),
                            preview,
                        );
                    } else if (row.copy)
                        card.append(
                            el(
                                "p",
                                "Private copy: " +
                                    row.copy.status +
                                    " / حالة النسخة الخاصة",
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
                        if (row.kind === "erasure" && ["customer", "vendor"].includes(user.role)) {
                            const host = el("div");
                            const open = button("Remove optional phone data / إزالة بيانات الهاتف الاختيارية", () => {
                                if (!active(state, generation, controller) || open.disabled) return;
                                open.disabled = true;
                                const form = el("form");
                                form.setAttribute("aria-label", "Optional contact minimization");
                                const password = el("input");
                                password.type = "password"; password.autocomplete = "current-password"; password.required = true;
                                const factor = el("input");
                                factor.autocomplete = "one-time-code"; factor.maxLength = 64;
                                const consent = el("input"); consent.type = "checkbox"; consent.required = true;
                                state.secrets.add(password); state.secrets.add(factor);
                                const submit = button("Confirm optional phone removal / تأكيد إزالة الهاتف الاختياري", () => {});
                                submit.type = "submit";
                                form.append(el("p", "Remove the optional phone and cancel its live verification challenges. Historical receipts, private copies, external records and your account are not deleted by this action. / إزالة الهاتف الاختياري وإلغاء تحديات التحقق النشطة؛ لا يحذف هذا الإجراء السجلات التاريخية أو النسخ الخاصة أو السجلات الخارجية أو الحساب."),
                                    field("Current password / كلمة المرور الحالية", password),
                                    field("Authenticator or recovery code if enabled / رمز المصادقة أو الاسترداد إن فُعّل", factor),
                                    field("I agree to remove optional phone data / أوافق على إزالة بيانات الهاتف الاختيارية", consent), submit);
                                form.onsubmit = async event => {
                                    event.preventDefault();
                                    if (!active(state, generation, controller) || submit.disabled || !consent.checked || !password.value) return;
                                    const body = {confirmation:"remove_optional_phone", password:password.value, second_factor_code:factor.value};
                                    password.value = ""; factor.value = ""; consent.checked = false;
                                    submit.disabled = true;
                                    try {
                                        const receipt = await state.request("account/privacy-requests/" + row.id + "/minimize-contact", "POST", body, controller.signal);
                                        if (!active(state, generation, controller)) return;
                                        const bounded = n => Number.isSafeInteger(n) && n >= 0 && n <= 250;
                                        if (receipt?.request_id !== row.id || !["pending","reviewing","blocked_external"].includes(receipt.case_status) ||
                                            receipt.optional_phone_removed !== true || typeof receipt.profile_phone_changed !== "boolean" ||
                                            !bounded(receipt.live_challenges_cancelled) || !bounded(receipt.challenge_payloads_cleared) ||
                                            receipt.verification_receipts_retained !== true || receipt.external_deletion_verified !== false ||
                                            receipt.account_deleted !== false || receipt.automated_erasure_performed !== false ||
                                            receipt.manual_processing_required !== true) throw Error("Unverified minimization receipt");
                                        host.replaceChildren(el("p", "Optional phone removal recorded. External deletion is not verified; your erasure request remains under review. / سُجلت إزالة الهاتف الاختياري؛ لم يُتحقق من الحذف الخارجي ولا يزال طلب الحذف قيد المراجعة."));
                                    } catch {
                                        if (active(state, generation, controller))
                                            host.replaceChildren(el("p", "Phone removal unconfirmed. Refresh before another decision. / لم تتأكد إزالة الهاتف؛ حدّث قبل قرار آخر."));
                                    }
                                };
                                host.replaceChildren(form);
                            });
                            card.append(open, host);
                        }
                        if (row.kind === "erasure" && ["customer", "vendor"].includes(user.role) &&
                            typeof onSecurityPrepared === "function") {
                            const host = el("div");
                            const open = button("Secure account before erasure / تأمين الحساب قبل الحذف", () => {
                                if (!active(state, generation, controller) || open.disabled) return;
                                open.disabled = true;
                                const securityForm = el("form");
                                securityForm.setAttribute("aria-label", "Optional erasure security preparation");
                                const password = el("input");
                                password.type = "password"; password.autocomplete = "current-password"; password.required = true;
                                const factor = el("input");
                                factor.autocomplete = "one-time-code"; factor.maxLength = 64;
                                const consent = el("input"); consent.type = "checkbox"; consent.required = true;
                                state.secrets.add(password); state.secrets.add(factor);
                                const submit = button("Confirm security step / تأكيد خطوة الأمان", () => {});
                                submit.type = "submit";
                                securityForm.append(el("p", "Optional: discard all your prepared private account copies and end all sessions. Your account and retained source records remain; you must sign in again. / اختياري: إتلاف نسخ حسابك الخاصة وإنهاء جميع الجلسات. يبقى الحساب والسجلات الأصلية؛ يلزم تسجيل الدخول مجدداً."),
                                    field("Current password / كلمة المرور الحالية", password),
                                    field("Authenticator or recovery code if enabled / رمز المصادقة أو الاسترداد إن فُعّل", factor),
                                    field("I agree to discard copies and revoke sessions / أوافق على إتلاف النسخ وإلغاء الجلسات", consent), submit);
                                securityForm.onsubmit = async event => {
                                    event.preventDefault();
                                    if (!active(state, generation, controller) || submit.disabled || !consent.checked || !password.value) return;
                                    const body = {confirmation:"discard_copies_and_revoke_sessions", password:password.value, second_factor_code:factor.value};
                                    password.value = ""; factor.value = ""; consent.checked = false;
                                    submit.disabled = true;
                                    try {
                                        // Do not abort a committed security mutation merely because the tab hides.
                                        const receipt = await state.request("account/privacy-requests/" + row.id + "/secure-account", "POST", body);
                                        if (state.generation !== generation || !state.request) return;
                                        if (receipt?.request_id !== row.id || !["pending", "reviewing", "blocked_external"].includes(receipt.case_status) ||
                                            !Number.isSafeInteger(receipt.derived_copies_discarded) || receipt.derived_copies_discarded < 0 ||
                                            receipt.derived_copies_discarded > 250 || receipt.sessions_revoked !== true ||
                                            receipt.sign_in_required !== true || receipt.automated_erasure_performed !== false ||
                                            receipt.source_records_deleted !== false || receipt.manual_processing_required !== true)
                                            throw Error("Unverified security receipt");
                                        clear(state);
                                        onSecurityPrepared();
                                    } catch {
                                        if (active(state, generation, controller)) {
                                            host.replaceChildren(el("p", "Security step unconfirmed. Sign in again or refresh to check; do not automatically repeat. / لم تتأكد خطوة الأمان؛ سجّل الدخول أو حدّث للتحقق."));
                                        }
                                    }
                                };
                                host.replaceChildren(securityForm);
                            });
                            card.append(open, host);
                        }
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
                    results.replaceChildren();
                    message.textContent =
                        "Could not load your request history. Refresh to verify access-copy and withdrawal status. / تعذر تحميل السجل؛ حدّث للتحقق من النسخ وسحب الطلبات.";
                }
            } finally {
                if (active(state, generation, controller))
                    results.removeAttribute("aria-busy");
            }
        }
        form.onsubmit = async (event) => {
            event.preventDefault();
            if (!state.request || viewGeneration !== state.generation ||
                state.submitting || document.hidden) return;
            if (details.value.trim().length < 10) {
                message.textContent =
                    "Please explain your request in at least 10 characters.";
                return;
            }
            const generation = state.generation;
            const visibilityVersion = state.visibilityVersion;
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
            state.submitting = true;
            submit.disabled = true;
            reset.disabled = true;
            try {
                const receipt = await state.request(
                    "account/privacy-requests",
                    "POST",
                    state.pending,
                );
                if (generation !== state.generation ||
                    visibilityVersion !== state.visibilityVersion || document.hidden) return;
                const rows = await refresh();
                if (generation !== state.generation ||
                    visibilityVersion !== state.visibilityVersion || document.hidden) return;
                if (!rows?.some((row) => row.id === receipt.id))
                    throw Error("Request readback unavailable");
                state.pending = null;
                details.value = "";
                message.textContent =
                    "Request recorded for manual review. No data has been automatically deleted.";
            } catch {
                if (generation === state.generation &&
                    visibilityVersion === state.visibilityVersion && !document.hidden) {
                    message.textContent =
                        "Request could not be confirmed. Check history or retry the unchanged request safely.";
                }
            } finally {
                if (generation === state.generation) {
                    state.submitting = false;
                    submit.disabled = false;
                    reset.disabled = false;
                }
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
        const viewGeneration = state.generation;
        state.onHide = () => {
            if (viewGeneration !== state.generation || !state.request) return;
            // Admin queues contain other peoples' identifiers and internal
            // reviewer notes. A hidden page or BFCache entry must not retain
            // those records or an actionable prior decision form.
            results.replaceChildren();
            message.textContent =
                "Private review queue closed. Return and apply filters to reload. / أُغلقت قائمة المراجعة الخاصة. ارجع وطبّق المرشحات لإعادة التحميل.";
        };

        const allowed = {
            pending: ["reviewing", "blocked_external"],
            reviewing: ["blocked_external", "closed_review"],
            blocked_external: ["reviewing", "closed_review"],
            closed_review: [],
            withdrawn: [],
        };
        async function load(page = 1) {
            if (!state.request || document.hidden ||
                viewGeneration !== state.generation) return;
            state.controller?.abort();
            const controller = new AbortController();
            state.controller = controller;
            const generation = state.generation;
            const params = new URLSearchParams({
                status: status.value,
                page: String(page),
            });
            if (kind.value) params.set("kind", kind.value);
            wipeSensitive(state, true);
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
                if (!Array.isArray(response?.data) ||
                    !Number.isSafeInteger(response.current_page) ||
                    response.current_page !== page ||
                    !Number.isSafeInteger(response.last_page) ||
                    response.last_page < page ||
                    response.last_page > 100000 ||
                    !Number.isSafeInteger(response.total) ||
                    response.total < 0 ||
                    response.last_page !==
                        Math.max(1, Math.ceil(response.total / 25)) ||
                    response.data.length > 25 ||
                    response.data.length > response.total)
                    throw Error("Unverified Admin privacy queue");
                const items = response.data;
                const seen = new Set();
                for (const record of items) {
                    if (!record || typeof record !== "object" ||
                        Array.isArray(record) ||
                        !Number.isSafeInteger(record.id) || record.id < 1 ||
                        seen.has(record.id) ||
                        !["access", "correction", "erasure"].includes(record.kind) ||
                        !["pending", "reviewing", "blocked_external",
                          "closed_review", "withdrawn"].includes(record.status) ||
                        typeof record.details !== "string" ||
                        record.details.length > 2000 ||
                        (record.owner != null &&
                            (typeof record.owner !== "object" ||
                             !Number.isSafeInteger(record.owner.id) ||
                             record.owner.id < 1 ||
                             (record.owner.name != null &&
                              typeof record.owner.name !== "string") ||
                             (record.owner.email != null &&
                              typeof record.owner.email !== "string")))) {
                        throw Error("Unverified Admin privacy record");
                    }
                    seen.add(record.id);
                }
                for (const record of items) {
                    const caseId = record.id;
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
                            "Manual review required; no automatic deletion. A reviewed access request can receive a private account copy; erasure remains a separate review.",
                        ),
                    );
                    if (
                        record.kind === "access" &&
                        record.status === "reviewing" &&
                        !record.copy
                    ) {
                        const adminPassword = el("input");
                        adminPassword.type = "password";
                        adminPassword.autocomplete = "current-password";
                        state.secrets.add(adminPassword);
                        const requestKey = crypto.randomUUID();
                        const prepare = button(
                            "Prepare private account copy / إعداد نسخة الحساب الخاصة",
                            async () => {
                                if (!active(state, generation, controller))
                                    return;
                                const password = adminPassword.value;
                                adminPassword.value = "";
                                prepare.disabled = true;
                                try {
                                    await state.request(
                                        `admin/privacy-requests/${caseId}/access-copy`,
                                        "POST",
                                        { password, request_key: requestKey },
                                        controller.signal,
                                    );
                                    if (active(state, generation, controller))
                                        await load(page);
                                } catch {
                                    if (active(state, generation, controller))
                                        message.textContent =
                                            "Copy preparation unconfirmed. Refresh or retry with the same action. Assisted review is needed for large accounts. / لم يتأكد إعداد النسخة؛ حدّث أو أعد المحاولة. تتطلب الحسابات الكبيرة مراجعة مساعدة.";
                                } finally {
                                    prepare.disabled = false;
                                }
                            },
                        );
                        card.append(
                            el(
                                "p",
                                "Prepare a scoped personal account copy for 24 hours. Excluded records require assisted review; no account data is deleted. / إعداد نسخة شخصية محددة لمدة ٢٤ ساعة دون حذف البيانات.",
                            ),
                            field(
                                "Administrator password / كلمة مرور المسؤول",
                                adminPassword,
                            ),
                            prepare,
                        );
                    }
                    if (record.kind === "erasure" && record.owner?.id &&
                        record.owner.id !== user.id && ["pending", "reviewing", "blocked_external"].includes(record.status)) {
                        const assessmentHost = el("div");
                        const keys = {
                            financial_records: "Financial records / السجلات المالية",
                            charging_records: "Charging and reservation records / سجلات الشحن والحجز",
                            business_relationships: "Business relationships / العلاقات التجارية",
                            support_records: "Support records / سجلات الدعم",
                            privacy_records: "Privacy request history / سجل طلبات الخصوصية",
                            active_credentials: "Stored credentials or sessions / بيانات الاعتماد أو الجلسات المخزنة",
                            external_and_legal_retention: "External and legal retention / الاحتفاظ الخارجي والقانوني",
                        };
                        const inspect = button("Assess erasure dependencies / تقييم متطلبات الحذف", async () => {
                            if (!active(state, generation, controller) || inspect.disabled) return;
                            inspect.disabled = true;
                            assessmentHost.replaceChildren();
                            try {
                                const report = await state.request(
                                    "admin/privacy-requests/" + caseId + "/erasure-assessment",
                                    "GET", undefined, controller.signal,
                                );
                                if (!active(state, generation, controller)) return;
                                if (report?.request_id !== caseId || report.owner_id !== record.owner.id ||
                                    report.case_status !== record.status || report.scope !== "local_dependency_assessment" ||
                                    report.manual_processing_required !== true || report.execution_authorized !== false ||
                                    report.automated_erasure_performed !== false || report.legal_retention_verified !== false ||
                                    !Array.isArray(report.dependencies) || report.dependencies.length !== Object.keys(keys).length ||
                                    new Set(report.dependencies.map(d => d?.key)).size !== Object.keys(keys).length ||
                                    !report.dependencies.every(d => d && Object.hasOwn(keys, d.key) &&
                                        ["present", "not_observed", "unknown"].includes(d.state)) ||
                                    !report.dependencies.some(d => d.key === "external_and_legal_retention" && d.state === "unknown"))
                                    throw Error("Unverified erasure assessment");
                                assessmentHost.append(el("p", "Dependency assessment only. No deletion is authorized. Not observed does not establish absence or legal eligibility. / تقييم المتطلبات فقط؛ لا يوجد تصريح بالحذف."));
                                const list = el("ul");
                                const labels = {present:"Present / موجود", not_observed:"Not observed / لم يُرصد", unknown:"Unknown / غير معروف"};
                                for (const dependency of report.dependencies)
                                    list.append(el("li", keys[dependency.key] + " · " + labels[dependency.state]));
                                assessmentHost.append(list);
                            } catch {
                                if (active(state, generation, controller))
                                    assessmentHost.replaceChildren(el("p", "Assessment unavailable; manual review required. / التقييم غير متاح؛ تلزم مراجعة يدوية."));
                            } finally {
                                inspect.disabled = false;
                            }
                        });
                        card.append(inspect, assessmentHost);
                    }
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
                                const entries = response?.data;
                                if (!Array.isArray(entries) ||
                                    entries.length > 25 ||
                                    entries.some((entry) =>
                                        !entry || typeof entry !== "object" ||
                                        !Number.isSafeInteger(entry.id) || entry.id < 1 ||
                                        !["pending", "reviewing", "blocked_external",
                                          "closed_review", "withdrawn"].includes(entry.from_status) ||
                                        !["pending", "reviewing", "blocked_external",
                                          "closed_review", "withdrawn"].includes(entry.to_status) ||
                                        typeof entry.note !== "string" ||
                                        entry.note.length > 2000 ||
                                        typeof entry.created_at !== "string" ||
                                        entry.created_at.length > 80)) {
                                    throw Error("Unverified Admin decision history");
                                }
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
                    if (record.kind === "erasure" && record.status === "reviewing" &&
                        record.owner?.id && record.owner.id !== user.id) {
                        const planHost = el("div");
                        const endpoint = "admin/privacy-requests/" + caseId + "/erasure-preparation";
                        let planGeneration = 0;
                        const validPlan = (p) => p?.request_id === caseId &&
                            typeof p.review_revision === "string" && /^[1-9][0-9]{0,17}$/.test(p.review_revision) &&
                            typeof p.review_scope === "string" && /^[a-f0-9]{64}$/.test(p.review_scope) &&
                            p.disposition === "retain_pending_legal_and_external_review" &&
                            p.execution_authorized === false && p.legal_retention_verified === false &&
                            p.automated_erasure_performed === false &&
                            (p.stage === "not_prepared" ? p.preparation_id === null && p.proposed_by === null && p.confirmed_by === null :
                                typeof p.preparation_id === "string" && /^[1-9][0-9]{0,17}$/.test(p.preparation_id) &&
                                Number.isSafeInteger(p.proposed_by) && p.proposed_by > 0 && p.proposed_by !== record.owner.id &&
                                (p.stage === "awaiting_independent_review" ? p.confirmed_by === null :
                                    p.stage === "assisted_review_prepared" && Number.isSafeInteger(p.confirmed_by) &&
                                    p.confirmed_by > 0 && p.confirmed_by !== p.proposed_by && p.confirmed_by !== record.owner.id));
                        async function readPlan() {
                            if (!active(state, generation, controller)) return;
                            const own = ++planGeneration;
                            planHost.replaceChildren(el("p", "Loading assisted review preparation… / جارٍ تحميل إعداد المراجعة…"));
                            try {
                                const p = await state.request(endpoint, "GET", undefined, controller.signal);
                                if (!active(state, generation, controller) || own !== planGeneration) return;
                                if (!validPlan(p)) throw Error("Invalid preparation");
                                renderPlan(p);
                            } catch {
                                if (active(state, generation, controller) && own === planGeneration)
                                    planHost.replaceChildren(el("p", "Preparation unavailable; refresh explicitly. / الإعداد غير متاح؛ حدّث صراحة."));
                            }
                        }
                        function renderPlan(p) {
                            const own = planGeneration;
                            const currentPlan = () => active(state, generation, controller) && own === planGeneration;
                            const labels = {
                                not_prepared: "Not prepared / لم يُعدّ",
                                awaiting_independent_review: "Awaiting independent reviewer / بانتظار مراجع مستقل",
                                assisted_review_prepared: "Assisted review prepared / تم إعداد المراجعة المساعدة",
                            };
                            planHost.replaceChildren(el("p", labels[p.stage]),
                                el("p", "Retain records pending legal and external review. No deletion is authorized. / احتفظ بالسجلات حتى المراجعة القانونية والخارجية؛ لا يوجد تصريح بالحذف."));
                            const action = p.stage === "not_prepared" ? "propose" :
                                p.stage === "awaiting_independent_review" && p.proposed_by !== user.id ? "confirm" : null;
                            if (!action) return;
                            const form = el("form");
                            form.setAttribute("aria-label", "Assisted erasure preparation " + caseId);
                            const password = el("input");
                            password.type = "password";
                            password.autocomplete = "current-password";
                            password.required = true;
                            const reason = el("textarea");
                            reason.required = true; reason.minLength = 10; reason.maxLength = 2000;
                            state.secrets.add(password); state.secrets.add(reason);
                            const submit = button(action === "propose" ?
                                "Propose assisted review / اقتراح مراجعة مساعدة" :
                                "Independently confirm preparation / تأكيد الإعداد بشكل مستقل", () => {});
                            submit.type = "submit";
                            form.append(field("Administrator password / كلمة مرور المسؤول", password),
                                field("Private reason / سبب خاص", reason), submit);
                            form.onsubmit = async event => {
                                event.preventDefault();
                                if (!currentPlan() || submit.disabled || !password.value || reason.value.trim().length < 10) return;
                                const payload = {action, review_revision:p.review_revision, review_scope:p.review_scope,
                                    password:password.value, reason:reason.value.trim()};
                                if (action === "confirm") payload.preparation_id = p.preparation_id;
                                password.value = ""; reason.value = ""; submit.disabled = true;
                                try {
                                    const receipt = await state.request(endpoint, "POST", payload, controller.signal);
                                    if (!currentPlan()) return;
                                    if (!validPlan(receipt) || receipt.review_scope !== p.review_scope ||
                                        receipt.review_revision !== p.review_revision ||
                                        (action === "propose" && (receipt.stage !== "awaiting_independent_review" || receipt.proposed_by !== user.id)) ||
                                        (action === "confirm" && (receipt.stage !== "assisted_review_prepared" ||
                                            receipt.preparation_id !== p.preparation_id || receipt.proposed_by !== p.proposed_by ||
                                            receipt.confirmed_by !== user.id))) throw Error("Unverified preparation receipt");
                                    planGeneration++; renderPlan(receipt);
                                } catch {
                                    if (currentPlan()) {
                                        planGeneration++;
                                        planHost.replaceChildren(el("p", "Preparation outcome unconfirmed; reload before another decision. / لم تتأكد النتيجة؛ حدّث قبل قرار آخر."));
                                    }
                                } finally { submit.disabled = false; }
                            };
                            planHost.append(form);
                        }
                        card.append(button("Load erasure preparation / تحميل إعداد الحذف", readPlan), planHost);
                    }
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
                        // This is an internal private explanation, not a
                        // durable draft across background/logout transitions.
                        state.secrets.add(note);
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
            if (viewGeneration !== state.generation ||
                document.hidden || !state.request) return;
            load(1);
        };
        load(1);
    }

    // Background tabs cannot retain a revealed account copy, an authenticator
    // input or an Admin review password in a hidden DOM. No request is replayed.
    function obscurePrivateViews() {
        // A hidden tab or BFCache navigation invalidates every pending
        // private-copy reveal, including a response delivered after pageshow.
        states.mine.visibilityVersion++;
        states.admin.visibilityVersion++;
        // A customer response may arrive after this tab becomes visible again.
        // Abort its previous read/controller so it cannot restore private
        // history or withdrawal/export actions without an explicit refresh.
        states.mine.controller?.abort();
        states.admin.controller?.abort();
        states.mine.onHide?.();
        states.admin.onHide?.();
        wipeSensitive(states.mine);
        wipeSensitive(states.admin);
    }
    document.addEventListener?.("visibilitychange", () => {
        if (document.hidden) obscurePrivateViews();
    });
    window.addEventListener?.("pagehide", obscurePrivateViews);
    return { setSelfActor, setAdminActor };
})();
